<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubEnforcementState;
use App\Enums\ControlHubLinkState;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Events\ControlHubContractChanged;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use App\Models\ControlHubContractItem;
use App\Models\ControlHubLinkAuditLog;
use App\Models\Workstation;
use App\Observers\WorkstationGroupObserver;
use App\Policies\CapabilityPolicy;
use App\Services\Agent\TargetContext;
use App\Services\ControlHub\ControlHubContractIngestionService;
use App\Services\ControlHub\ControlHubContractSeveranceService;
use App\Services\ControlHub\Resolution\RegistryUpstreamAdapter;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Services\ControlHub\UpstreamCatalogResolver;
use App\Services\ControlHub\UpstreamLockResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 32.2 (PRD §5.3 panne≠rupture + NFR5) — PREUVE que l'indisponibilité amont
 * (silence) NE libère PAS les verrous, et que la couverture d'audit NFR5 est complète.
 *
 * Verdict d'investigation : story PREUVE DOMINANTE (Q1=A — aucune construction).
 *
 * Ce fichier PROUVE par tests que :
 *
 *   AC1 — Un contrat `active` avec un `received_at` arbitrairement ancien conserve
 *          TOUS ses effets (active() non-null, verrous, bornage catalogue, refus de modif).
 *          La panne = SILENCE PUR, jamais matérialisée.
 *
 *   AC2 — (a) Silence = strictement no-op (aucune écriture, aucun audit, aucun event,
 *          contrat reste `active`). (b) Seule la rupture EXPLICITE (32.1) libère.
 *
 *   AC3/Q1=A — Aucune API de fraîcheur n'est introduite. `active()` ignore `received_at`
 *               et tout TTL. L'indisponibilité est une absence non matérialisée.
 *               GARDE-FOU ANTI-RÉGRESSION : si quelqu'un couplait un jour `active()` à
 *               `received_at`/TTL, ces assertions échoueraient.
 *
 *   AC4/NFR5 — L'enum `{Active, Severed}` n'a que 2 états → l'unique transition possible
 *               est `active → severed` (déjà tracée par 32.1). Le silence n'écrit aucun
 *               audit. Couverture NFR5 complète par construction.
 *
 *   AC5/NFR4 — Reprise après silence : réception identique = no-op (`received_at`
 *               inchangé, aucun event) ; réception différente = `received_at` rafraîchi
 *               + `ControlHubContractChanged` émis. Aucun traitement spécial « sortie
 *               d'indisponibilité » — NFR4 acquis par construction via `ingest()`.
 *
 *   AC6/NFR3 — Standalone : comportement strictement inchangé (aucun contrat, aucun audit,
 *               aucune écriture). Zéro requête sur `controlhub_contract_items` (court-circuit).
 *
 *   AC7       — Contrat agent figé, invisible de l'agent : `active()` non-null pendant la
 *               panne → le dernier état compilé reste servi. Prouvé ici par
 *               `upstream_tier_still_feeds_agent_state_during_silence()` (la contribution
 *               amont reste exposée en maille `Upstream` après 1 an de silence) ; la
 *               non-régression du wire agent figé (golden/`ContractV1`/`StateCompiler`)
 *               relève des suites dédiées (T8). Aucune touche agent/golden ici.
 *
 * ⚠️ GARDE-FOU CENTRAL : `active()` n'interroge JAMAIS `received_at` / TTL / staleness.
 *    Coupler ces deux concepts libérerait les verrous sur une simple panne (anti-pattern).
 * ⚠️ GARDE-FOU R3 : aucun mot « central » ; vocabulaire « amont » / `ControlHub*`.
 *    [Source: prd-contrat-manage-se5.md#R3]
 *
 * Tests HÔTE (php8.4 + pdo_sqlite, CACHE_DRIVER=array), RefreshDatabase.
 * SQLite n'applique pas les enums/varchar PG → on teste des DÉCISIONS (état, count,
 * valeur), jamais des bornes de colonne.
 */
class UpstreamUnavailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        WorkstationGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC1 — active() ignore received_at (non-libération acquise par construction)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC1 — Le contrat `active` reste actif quelle que soit l'ancienneté de `received_at`.
     * Prouve que `active()` filtre `link_state` SEUL, jamais `received_at`.
     */
    #[Test]
    public function active_contract_remains_active_regardless_of_how_old_received_at_is(): void
    {
        $contract = ControlHubContract::factory()->create();
        self::assertNotNull(ControlHubContract::active(), 'Pré-condition : contrat actif créé');

        // Avancer 365 jours sans aucune réception amont (= silence/panne simulée).
        $this->travel(365)->days();

        $resolved = ControlHubContract::active();
        self::assertNotNull($resolved, 'active() doit rester non-null après 365 jours de silence');
        self::assertSame($contract->id, $resolved->id);
        self::assertSame(ControlHubLinkState::Active, $resolved->link_state);
    }

    /**
     * AC1/AC3 — `active()` filtre uniquement `link_state`, pas `received_at`.
     *
     * Trois scénarios :
     *   A) received_at = null  + link_state = active → retourné (aucun TTL imposé)
     *   B) received_at ancien  + link_state = active → retourné
     *   C) received_at frais   + link_state = severed → non retourné (rupture explicite)
     *
     * GARDE-FOU ANTI-RÉGRESSION : si quelqu'un introduisait un TTL dans active(), les
     * assertions A) et B) échoueraient immédiatement.
     */
    #[Test]
    public function active_scope_filters_only_on_link_state_never_on_received_at(): void
    {
        // A) received_at = null mais link_state = active → doit être retourné.
        $contractNoDate = ControlHubContract::factory()->notYetReceived()->create();
        self::assertNull($contractNoDate->received_at, 'Pré-condition : received_at est null');
        $resolved = ControlHubContract::active();
        self::assertNotNull($resolved, 'active() retourne un contrat même sans received_at (aucun TTL)');
        self::assertSame($contractNoDate->id, $resolved->id);

        $contractNoDate->delete();

        // B) received_at très ancien (5 ans) mais link_state = active → doit être retourné.
        ControlHubContract::factory()->create(['received_at' => now()->subYears(5)]);
        self::assertNotNull(
            ControlHubContract::active(),
            'active() retourne un contrat avec received_at vieux de 5 ans (aucun TTL)',
        );

        ControlHubContract::query()->delete();

        // C) received_at frais mais link_state = severed → ne doit PAS être retourné.
        ControlHubContract::factory()->severed()->create(['received_at' => now()]);
        self::assertNull(
            ControlHubContract::active(),
            'active() retourne null pour un contrat severed, même avec received_at frais',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC1 — Resolvers restent verrouillés pendant l'indisponibilité
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC1 — `UpstreamCatalogResolver::isBounded()` reste `true` durant la panne amont.
     * Preuve que le bornage catalogue ne dépend pas de la fraîcheur du contrat.
     */
    #[Test]
    public function upstream_catalog_resolver_stays_bounded_during_upstream_silence(): void
    {
        $contract = ControlHubContract::factory()->create(['received_at' => now()->subYear()]);
        ControlHubContractCatalogApp::create([
            'controlhub_contract_id' => $contract->id,
            'app_key' => 'firefox',
        ]);

        // Avancer d'1 an supplémentaire (2 ans de silence total).
        $this->travel(365)->days();

        // Le bornage reste actif : active() non-null → isBounded() = true.
        $resolver = new UpstreamCatalogResolver();
        self::assertTrue(
            $resolver->isBounded(),
            'Le bornage catalogue doit rester actif pendant la panne amont',
        );
    }

    /**
     * AC1 — `UpstreamLockResolver::isCapabilityLocked()` reste `true` durant la panne.
     * Preuve que le verrou de capacité ne dépend pas de la fraîcheur du contrat.
     */
    #[Test]
    public function upstream_lock_resolver_stays_locked_during_upstream_silence(): void
    {
        $contract = ControlHubContract::factory()->create(['received_at' => now()->subYear()]);
        $capability = $this->registryCapability('HKLM', 'Software\\Se5', 'Kiosk');
        $this->lockedRegistryItem($contract, 'HKLM', 'Software\\Se5', 'Kiosk');

        $this->travel(365)->days();

        // Résolveur frais (pas de mémoïsation périmée) : le verrou est TOUJOURS actif.
        $resolver = new UpstreamLockResolver();
        self::assertTrue(
            $resolver->isCapabilityLocked($capability),
            'La capacité doit rester verrouillée amont pendant la panne',
        );
    }

    /**
     * AC1 — `CapabilityPolicy::modify()` retourne `false` durant la panne amont.
     * Preuve que le refus de modif ne dépend pas de la fraîcheur du contrat.
     */
    #[Test]
    public function capability_policy_refuses_modify_during_upstream_silence(): void
    {
        $contract = ControlHubContract::factory()->create(['received_at' => now()->subYear()]);
        $capability = $this->registryCapability('HKLM', 'Software\\Se5', 'Kiosk');
        $this->lockedRegistryItem($contract, 'HKLM', 'Software\\Se5', 'Kiosk');

        $this->travel(365)->days();

        // Policy fraîche (résolveur non mémoïsé) : le refus est TOUJOURS en vigueur.
        $policy = new CapabilityPolicy(new UpstreamLockResolver());
        self::assertFalse(
            $policy->modify(null, $capability),
            'CapabilityPolicy::modify() doit refuser tant que le contrat est actif, même en cas de panne',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2 — Panne = silence pur : aucune écriture, aucun audit, aucun event
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC2(a) — Le silence (panne) est strictement no-op : aucune écriture sur la table
     * des contrats, aucun audit, aucun event, `received_at` inchangé.
     */
    #[Test]
    public function upstream_silence_produces_zero_writes_zero_audits_zero_events(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        $receivedAtBefore = $contract->received_at->toIso8601String();

        // Avancer 90 jours sans aucun appel de service (= panne, silence pur).
        $this->travel(90)->days();

        // Prouve : zéro écriture sur la table des contrats pendant le silence.
        DB::enableQueryLog();
        ControlHubContract::active(); // Accès read-only seulement.
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $hasWrite = collect($queries)->contains(
            static fn (array $q): bool => (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', (string) $q['query']),
        );
        self::assertFalse($hasWrite, 'La panne (silence) ne doit produire aucune écriture sur controlhub_contracts');

        // Zéro audit (la panne n'est pas une transition d'état).
        self::assertSame(0, ControlHubLinkAuditLog::count(), 'Aucun audit produit pendant la panne');

        // Aucun event dispatché pendant le silence.
        Event::assertNotDispatched(ControlHubContractChanged::class);

        // received_at non touché (silence = zéro écriture confirmé).
        $receivedAtAfter = $contract->fresh()->received_at->toIso8601String();
        self::assertSame($receivedAtBefore, $receivedAtAfter, 'received_at ne doit pas changer pendant la panne');
    }

    /**
     * AC2(b) — Panne ≠ rupture : seule la rupture EXPLICITE via `sever()` libère les
     * verrous. Le silence, lui, est no-op : active()=non-null, bornage/verrous maintenus,
     * 0 audit, 0 event. La rupture explicite, elle, pose `severed`, lève tout, 1 audit.
     */
    #[Test]
    public function only_explicit_severance_lifts_locks_not_upstream_silence(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create(['received_at' => now()->subYear()]);
        $capability = $this->registryCapability('HKLM', 'Software\\Se5', 'KioskB');
        $this->lockedRegistryItem($contract, 'HKLM', 'Software\\Se5', 'KioskB');
        ControlHubContractCatalogApp::create([
            'controlhub_contract_id' => $contract->id,
            'app_key' => 'firefox',
        ]);

        // (a) SILENCE : verrous maintenus, 0 audit, 0 event.
        $this->travel(365)->days();

        self::assertNotNull(ControlHubContract::active(), 'Après panne : contrat toujours actif');
        self::assertTrue((new UpstreamCatalogResolver())->isBounded(), 'Après panne : bornage toujours actif');
        self::assertTrue(
            (new UpstreamLockResolver())->isCapabilityLocked($capability),
            'Après panne : verrou toujours actif',
        );
        self::assertSame(0, ControlHubLinkAuditLog::count(), 'Panne = 0 audit NFR5');
        Event::assertNotDispatched(ControlHubContractChanged::class);

        // (b) RUPTURE EXPLICITE (32.1) : tout se lève, 1 audit, 1 event.
        /** @var ControlHubContractSeveranceService $svc */
        $svc = app(ControlHubContractSeveranceService::class);
        $result = $svc->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'test-actor');

        self::assertTrue($result->severed, 'La rupture explicite doit réussir');
        self::assertNull(ControlHubContract::active(), 'Après rupture : active()=null');
        self::assertFalse((new UpstreamCatalogResolver())->isBounded(), 'Après rupture : bornage levé');
        self::assertFalse(
            (new UpstreamLockResolver())->isCapabilityLocked($capability),
            'Après rupture : verrou levé',
        );
        self::assertSame(1, ControlHubLinkAuditLog::count(), 'Rupture = 1 audit NFR5');
        Event::assertDispatched(ControlHubContractChanged::class, 1);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC3/Q1=A — Aucune API de fraîcheur : active() indépendant de received_at
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC3/Q1=A — Preuve structurelle que `active()` ne dépend d'aucun TTL ni de
     * `received_at`. L'indisponibilité amont est un SILENCE NON MATÉRIALISÉ.
     *
     * GARDE-FOU ANTI-RÉGRESSION : si ce test échoue un jour, c'est qu'un développeur
     * a couplé `active()` à `received_at` ou à un TTL — ce qui libérerait les verrous
     * sur une simple panne, violant frontalement le PRD §5.3.
     */
    #[Test]
    public function active_method_is_independent_of_received_at_and_any_ttl(): void
    {
        // received_at = null → actif quand même (aucun TTL).
        ControlHubContract::factory()->notYetReceived()->create();
        self::assertNotNull(
            ControlHubContract::active(),
            'GARDE-FOU : active() doit retourner un contrat même sans received_at (aucun TTL)',
        );
        ControlHubContract::query()->delete();

        // received_at vieux de 10 ans → actif quand même.
        ControlHubContract::factory()->create(['received_at' => now()->subDecade()]);
        self::assertNotNull(
            ControlHubContract::active(),
            'GARDE-FOU : active() doit retourner un contrat vieux de 10 ans (aucun TTL)',
        );
        ControlHubContract::query()->delete();

        // Faire avancer le temps de 365 jours → always actif si link_state=active.
        ControlHubContract::factory()->create(['received_at' => now()->subYear()]);
        $this->travel(365)->days();
        self::assertNotNull(
            ControlHubContract::active(),
            'GARDE-FOU : active() doit rester non-null après travel(365) (aucun TTL implicite)',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC4/NFR5 — active→severed est l'unique transition auditée
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC4/NFR5 — Preuve de couverture NFR5 complète.
     *
     * L'enum `ControlHubLinkState` n'a que 2 états (`{Active, Severed}`). L'unique
     * transition possible est donc `active → severed` — déjà tracée par 32.1.
     * Le silence (panne) n'est PAS une transition d'état → aucun audit NFR5 produit.
     * La couverture NFR5 est SATISFAITE PAR CONSTRUCTION.
     */
    #[Test]
    public function nfr5_coverage_is_complete_silence_is_not_a_transition_and_produces_no_audit(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        ControlHubContract::factory()->create();

        // L'enum n'a que 2 états → 1 seule transition possible (active→severed).
        $cases = ControlHubLinkState::cases();
        self::assertCount(2, $cases, 'L\'enum doit avoir exactement 2 états : Active et Severed');
        self::assertSame('Active', $cases[0]->name);
        self::assertSame('Severed', $cases[1]->name);

        // Silence de 6 mois → AUCUN audit NFR5 (la panne n'est pas une transition).
        $this->travel(180)->days();
        self::assertSame(
            0,
            ControlHubLinkAuditLog::count(),
            'L\'indisponibilité (silence) ne produit aucun audit NFR5 — ce n\'est pas une transition d\'état',
        );

        // Rupture explicite → UNE ligne d'audit (unique transition active→severed tracée).
        $svc = app(ControlHubContractSeveranceService::class);
        $svc->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'test-nfr5');

        self::assertSame(1, ControlHubLinkAuditLog::count(), 'La transition active→severed est tracée (1 ligne)');
        $log = ControlHubLinkAuditLog::sole();
        self::assertSame(ControlHubLinkState::Active->value, $log->from_state);
        self::assertSame(ControlHubLinkState::Severed->value, $log->to_state);
        // NFR5 complet : l'enum n'a que 2 états → 1 seule transition → 32.1 la trace → couverture totale.
    }

    /**
     * AC4/NFR5 — GARDE-FOU D'INVARIANT (Finding #2, review opus 2026-06-30).
     *
     * « L'enum a 2 états » prouve seulement l'absence d'un 3e état — PAS que tout
     * passage à `severed` est audité. La couverture NFR5 repose en réalité sur le
     * fait que la SEULE écriture de `severed` est `sever()` (chemin audité de 32.1).
     * Le chemin de réception (`ingest()`) — unique mutation entrante — ne doit JAMAIS
     * produire `severed`, même si le payload le réclame (le service ignore le
     * `link_state` du payload, cf. ControlHubContractIngestionService §70/§144).
     *
     * Scénario d'échec verrouillé : si un jour `ingest()` propageait un `severed`
     * reçu (ou tout autre chemin entrant écrivait `severed` sans audit), une
     * transition NON tracée naîtrait → NFR5 violé. Cette assertion casserait.
     */
    #[Test]
    public function ingest_never_writes_severed_even_when_payload_claims_it(): void
    {
        $svc = new ControlHubContractIngestionService();

        // 1re réception → Active.
        $svc->ingest($this->minimalPayload('cap_invariant_a'));
        self::assertSame(
            ControlHubLinkState::Active,
            ControlHubContract::query()->sole()->link_state,
            'Pré-condition : la 1re réception pose Active',
        );

        // Réception DIFFÉRENTE dont le payload tente d'imposer link_state=severed.
        $payload = $this->minimalPayload('cap_invariant_b');
        $payload['link_state'] = 'severed';
        $result = $svc->ingest($payload);

        self::assertTrue($result->mutated, 'Pré-condition : payload différent → mutation');
        self::assertSame(
            ControlHubLinkState::Active,
            ControlHubContract::query()->sole()->link_state,
            'GARDE-FOU : ingest() ne doit JAMAIS écrire severed (link_state du payload ignoré) '
            .'— sever() reste l\'unique écrivain de Severed, ce qui fonde la couverture NFR5',
        );
        self::assertSame(
            0,
            ControlHubLinkAuditLog::count(),
            'La réception ne produit jamais d\'audit de transition (severed inatteignable par ingest)',
        );
    }

    /**
     * AC1/AC4 — GARDE-FOU ANTI-DÉCROISSANCE (Finding #1, review opus 2026-06-30).
     *
     * La preuve « zéro write pendant le silence » ne mord pas un job PLANIFIÉ (que
     * `travel()` ne déclenche pas). Le vrai risque de régression est l'ajout d'une
     * commande planifiée qui ferait « décroître » un contrat selon son ancienneté
     * (ex. `controlhub:expire-stale` flippant active→severed sur TTL), libérant les
     * verrous sur une simple panne — frontalement contraire au PRD §5.3.
     *
     * On verrouille donc l'allowlist des commandes controlhub planifiées : seul
     * `controlhub:heartbeat` (transport sortant, ne touche jamais le contrat) est
     * admis. Tout nouveau job controlhub planifié casse cette assertion et force à
     * confronter le PRD §5.3 (panne ≠ rupture).
     */
    #[Test]
    public function no_scheduled_command_decays_the_contract_on_staleness(): void
    {
        // Bootstrap du noyau console → enregistre app/Console/Kernel::schedule()
        // ET routes/console.php (façade Schedule) dans le même singleton.
        $this->app->make(ConsoleKernel::class)->bootstrap();
        $schedule = $this->app->make(Schedule::class);

        $controlhubCommands = collect($schedule->events())
            ->map(static fn ($event): string => (string) ($event->command ?? ''))
            ->filter(static fn (string $cmd): bool => str_contains($cmd, 'controlhub:'))
            ->values();

        // Sanity : l'introspection du scheduler VOIT bien les jobs (sinon le reject
        // ci-dessous passerait trivialement → faux négatif).
        self::assertNotEmpty(
            $controlhubCommands,
            'Sanity : l\'introspection du scheduler doit voir au moins controlhub:heartbeat',
        );

        // Allowlist des commandes controlhub AUTORISÉES à être planifiées : uniquement
        // celles qui ne peuvent PAS faire « décroître » le contrat sur l'ancienneté.
        //  - controlhub:heartbeat          → transport sortant, ne touche jamais le contrat.
        //  - controlhub:report-compliance  → émetteur de conformité (canal ③, story 39.2).
        //    STRICTEMENT read-only sur le contrat (lit items + link_state pour bâtir
        //    l'enveloppe, n'écrit JAMAIS link_state ni ne prune) → compatible PRD §5.3.
        //    Confronté au §5.3 le 2026-07-06 (review epic 39, finding E2) : autorisé.
        $decayAllowlist = ['controlhub:heartbeat', 'controlhub:report-compliance'];

        $nonAllowlisted = $controlhubCommands
            ->reject(static fn (string $cmd): bool => collect($decayAllowlist)
                ->contains(static fn (string $allowed): bool => str_contains($cmd, $allowed)))
            ->values()
            ->all();

        self::assertSame(
            [],
            $nonAllowlisted,
            'GARDE-FOU : seules les commandes controlhub read-only-sur-contrat (heartbeat, '
            .'report-compliance) sont planifiées. Un nouveau job controlhub planifié '
            .'(décroissance/TTL/expire) doit être confronté au PRD §5.3 (panne ≠ rupture) '
            .'ET ajouté explicitement à $decayAllowlist avec justification avant d\'être autorisé ici.',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC5/NFR4 — Reprise après silence
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC5/NFR4 — Réception IDENTIQUE après silence = no-op.
     * `received_at` inchangé, aucun event — la reprise ne provoque aucun effet de bord.
     * (Comportement acquis par construction via `ingest()` — prouvé ici.)
     */
    #[Test]
    public function identical_reception_after_silence_is_a_noop_with_received_at_unchanged(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $svc = new ControlHubContractIngestionService();
        $payload = $this->minimalPayload('cap_nfr4_identical');

        // 1re réception : crée le contrat.
        $first = $svc->ingest($payload);
        self::assertTrue($first->mutated, 'Pré-condition : 1re ingestion = mutated');

        $contract = ControlHubContract::active();
        $receivedAtBefore = $contract->received_at->copy();

        // Silence de 30 jours.
        $this->travel(30)->days();

        // Réception IDENTIQUE (reprise) → no-op.
        $second = $svc->ingest($payload);

        self::assertFalse($second->mutated, 'Réception identique après silence = no-op (NFR4)');
        self::assertSame(
            $receivedAtBefore->toIso8601String(),
            $contract->fresh()->received_at?->toIso8601String(),
            'received_at inchangé après réception identique (NFR4)',
        );
        // Exactement 1 event total (1re ingestion seulement), aucun sur no-op.
        Event::assertDispatchedTimes(ControlHubContractChanged::class, 1);
    }

    /**
     * AC5/NFR4 — Réception DIFFÉRENTE après silence = mutation normale.
     * `received_at` rafraîchi, `ControlHubContractChanged` émis — reprise sans effet de
     * bord spécial (pas de traitement « sortie d'indisponibilité »).
     */
    #[Test]
    public function different_reception_after_silence_refreshes_received_at_and_dispatches_event(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $svc = new ControlHubContractIngestionService();

        // 1re ingestion avec un payload (clé A).
        $svc->ingest($this->minimalPayload('cap_nfr4_key_a'));
        $contract = ControlHubContract::active();
        $receivedAtBefore = $contract->received_at->copy();

        // Silence de 30 jours.
        $this->travel(30)->days();

        // Réception DIFFÉRENTE (clé B modifiée) → mutation.
        $result = $svc->ingest($this->minimalPayload('cap_nfr4_key_b'));

        self::assertTrue($result->mutated, 'Réception différente après silence = mutation (NFR4)');

        $receivedAtAfter = $contract->fresh()->received_at;
        self::assertNotNull($receivedAtAfter, 'received_at ne doit pas être null après mutation');
        self::assertTrue(
            $receivedAtAfter->isAfter($receivedAtBefore),
            'received_at doit être rafraîchi (30 jours plus tard) après réception différente',
        );

        // 2 events au total : 1 pour la 1re ingestion, 1 pour la 2e.
        Event::assertDispatchedTimes(ControlHubContractChanged::class, 2);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC6/NFR3 — Standalone strictement inchangé
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC6/NFR3 — Sans contrat, le comportement est byte-identique au standalone 27.x.
     * Le temps qui passe ne change rien : aucun contrat créé, aucun audit, aucune écriture.
     */
    #[Test]
    public function standalone_instance_behavior_is_strictly_unchanged_over_time(): void
    {
        self::assertNull(ControlHubContract::active(), 'Standalone : aucun contrat actif');

        $this->travel(365)->days();

        self::assertNull(ControlHubContract::active(), 'Standalone après 1 an : toujours aucun contrat');
        self::assertFalse(
            (new UpstreamCatalogResolver())->isBounded(),
            'Standalone : jamais borné (NFR3)',
        );
        self::assertFalse(
            (new UpstreamLockResolver())->isCapabilityLocked(Capability::factory()->create()),
            'Standalone : aucune capacité verrouillée (NFR3)',
        );
        self::assertSame(0, ControlHubLinkAuditLog::count(), 'Standalone : aucun audit NFR5');
    }

    /**
     * AC6/NFR3 — En standalone, `active()` produit exactement 1 requête SELECT sur
     * `controlhub_contracts` et ZÉRO écriture — la table items n'est jamais touchée.
     */
    #[Test]
    public function standalone_produces_zero_writes_and_never_queries_items_table(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        DB::enableQueryLog();
        ControlHubContract::active();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Zéro écriture.
        $hasWrite = collect($queries)->contains(
            static fn (array $q): bool => (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', (string) $q['query']),
        );
        self::assertFalse($hasWrite, 'Standalone : aucune écriture sur la table des contrats (NFR3)');

        // La table items n'est jamais interrogée (court-circuit NFR3 via active()→null).
        $touchedItems = collect($queries)->contains(
            static fn (array $q): bool => str_contains((string) $q['query'], 'controlhub_contract_items'),
        );
        self::assertFalse($touchedItems, 'Standalone : aucune requête sur controlhub_contract_items (court-circuit NFR3)');

        Event::assertNotDispatched(ControlHubContractChanged::class);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC7 — Le dernier état compilé reste servi à l'agent pendant la panne
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC7 — Contrat agent figé : tant que `active()` est non-null (panne = silence),
     * la contribution amont continue d'alimenter l'état compilé servi à l'agent.
     *
     * Preuve fidèle et légère via `UpstreamContractSource::candidatesFor()` (la source
     * qui injecte la maille `Upstream` dans le `StateCompiler`) : un item registre
     * verrouillé reste exposé en maille `Upstream` AVANT et APRÈS 1 an de silence.
     * La non-régression du wire agent figé (golden/`ContractV1`) relève des suites
     * dédiées (T8) — ici on prouve que la panne ne fait pas DISPARAÎTRE l'amont du state.
     */
    #[Test]
    public function upstream_tier_still_feeds_agent_state_during_silence(): void
    {
        $contract = ControlHubContract::factory()->create(['received_at' => now()->subYear()]);
        // Item registre MACHINE (HKLM) verrouillé → scope Machine côté adaptateur.
        $this->lockedRegistryItem($contract, 'HKLM', 'Software\\Se5', 'Kiosk');

        $ctx = TargetContext::for(Workstation::factory()->create(), null);

        // AVANT le silence : l'amont contribue à l'état compilé (maille Upstream).
        $before = (new UpstreamContractSource([new RegistryUpstreamAdapter()]))
            ->candidatesFor(CapabilityProjection::MECHANISM_REGISTRY, StateScope::Machine, $ctx);
        self::assertNotEmpty($before, 'Pré-condition : l\'amont contribue au state agent');

        // 1 an de silence pur (aucune réception).
        $this->travel(365)->days();

        // APRÈS : la contribution amont est TOUJOURS servie, en maille Upstream.
        $after = (new UpstreamContractSource([new RegistryUpstreamAdapter()]))
            ->candidatesFor(CapabilityProjection::MECHANISM_REGISTRY, StateScope::Machine, $ctx);
        self::assertNotEmpty(
            $after,
            'AC7 : le dernier état compilé (contribution amont) reste servi pendant la panne',
        );
        self::assertSame(
            StateMaille::Upstream,
            $after[0]->maille,
            'AC7 : la contribution reste en maille Upstream (verrou maintenu pendant la panne)',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Crée une capacité projetée registre (1 clé hive|path|name, map on=1/off=0).
     * Calqué sur ContractSeveranceTest::registryCapability().
     */
    private function registryCapability(string $hive, string $path, string $name): Capability
    {
        $capability = Capability::factory()->create(['default_value' => 'off']);
        CapabilityProjection::factory()->for($capability)->keys([
            [
                'hive' => $hive,
                'path' => $path,
                'name' => $name,
                'type' => 'REG_DWORD',
                'value' => ['on' => 1, 'off' => 0],
            ],
        ])->create();

        return $capability;
    }

    /**
     * Ajoute un item `registry/locked/instance` au contrat (verrouille la clé).
     * Calqué sur ContractSeveranceTest::lockedRegistryItem().
     */
    private function lockedRegistryItem(
        ControlHubContract $contract,
        string $hive,
        string $path,
        string $name,
        string $value = '1',
    ): ControlHubContractItem {
        return ControlHubContractItem::factory()->for($contract, 'contract')->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => "{$hive}|{$path}|{$name}|REG_DWORD",
            'value' => $value,
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);
    }

    /**
     * Payload minimal reproductible pour les tests de reprise NFR4.
     * Un seul item `capabilities/locked/instance` avec une clé paramétrable.
     *
     * @return array<string, mixed>
     */
    private function minimalPayload(string $itemKey = 'cap_payload_default'): array
    {
        return [
            'items' => [
                [
                    'type' => 'capabilities',
                    'key' => $itemKey,
                    'value' => 'on',
                    'enforcement_state' => 'locked',
                    'target_type' => 'instance',
                ],
            ],
            'labels' => [],
            'imposed_groups' => [],
            'catalog_apps' => [],
        ];
    }
}
