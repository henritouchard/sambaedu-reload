<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ApplicationStatus;
use App\Enums\ControlHubEnforcementState;
use App\Enums\ControlHubLinkState;
use App\Events\ControlHubContractChanged;
use App\Models\Application;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use App\Models\ControlHubContractItem;
use App\Models\ControlHubLinkAuditLog;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Policies\CapabilityPolicy;
use App\Services\Agent\AgentTtlResolver;
use App\Services\Agent\Providers\ApplicationsStateProvider;
use App\Services\Agent\Providers\RegistryMachineCapabilityProvider;
use App\Services\Agent\Providers\RegistryUserCapabilityProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use App\Services\AppStore\AppStoreService;
use App\Services\ControlHub\ControlHubContractSeveranceService;
use App\Services\ControlHub\Resolution\RegistryUpstreamAdapter;
use App\Services\ControlHub\Resolution\UpstreamAwareProvider;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Services\ControlHub\UpstreamCatalogResolver;
use App\Services\ControlHub\UpstreamLockResolver;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 32.1 (FR7 + NFR5) — Release des verrous à la rupture du lien amont (controlHub).
 *
 * Couvre :
 *  - AC1 : réception du signal → `severed` + `ControlHubContractChanged` ; 2e signal = no-op.
 *  - AC2 : levée AUTOMATIQUE verrous + bornage catalogue + refus de modif (chokepoint
 *          `active()` → null partagé par catalog/lock/policy), prouvée par test + query-log.
 *  - AC3 : conservation des supports locaux (override `capability_assignments`, `Application`),
 *          jamais écrasés par la valeur amont.
 *  - AC4 : conservation de l'état effectif COMPLET, déverrouillé (M1) — **PARITÉ D'ÉTAT** :
 *          `StateCompiler::compile()` d'un poste du parc émet la MÊME sortie avant/après la
 *          rupture, via matérialisation du canal `registry` réel et des affectations d'apps.
 *  - AC5 : standalone strictement no-op (NFR3) + contrat déjà `severed` = no-op.
 *  - AC6 : exactement 1 ligne d'audit par transition ; 0 sur re-signal.
 *
 * AC7 (contrat agent figé) vit dans sa propre suite (`ContractV1Test` / golden) : ce test
 * ne touche AUCUN champ wire. Tests HÔTE (php8.4 + pdo_sqlite, `CACHE_DRIVER=array`),
 * `RefreshDatabase` (le patron des suites capacité+contrat — `UpstreamLockResolverTest`,
 * `CapabilitiesOverrideAuditTest`). SQLite n'applique pas varchar/enum PG → on teste des
 * DÉCISIONS (état/présence/count/valeur compilée), jamais des bornes de colonne.
 *
 * ⚠️ GARDE-FOU R3 : aucun « central » ; vocabulaire « amont » / `Upstream` / `ControlHub*`.
 */
class ContractSeveranceTest extends TestCase
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

    private function service(): ControlHubContractSeveranceService
    {
        return app(ControlHubContractSeveranceService::class);
    }

    // ── AC1 + AC6 — réception du signal, event, idempotence ───────────────────

    #[Test]
    public function it_severs_the_active_contract_and_dispatches_change_event(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->for($contract, 'contract')->create();

        $result = $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');

        self::assertTrue($result->severed);
        self::assertSame($contract->id, $result->contractId);
        self::assertSame(
            ControlHubLinkState::Severed,
            $contract->fresh()->link_state,
        );
        Event::assertDispatched(ControlHubContractChanged::class, 1);
        self::assertSame(1, ControlHubLinkAuditLog::count());
    }

    #[Test]
    public function a_second_signal_on_an_already_severed_contract_is_a_total_noop(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();

        $first = $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');
        self::assertTrue($first->severed);

        $second = $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');

        self::assertFalse($second->severed);
        self::assertNull($second->contractId);
        // Idempotence stricte (AC1/AC6) : aucun nouvel event, aucune nouvelle trace.
        Event::assertDispatched(ControlHubContractChanged::class, 1);
        self::assertSame(1, ControlHubLinkAuditLog::count());
    }

    // ── AC2 — levée automatique (chokepoint active() → null) ──────────────────

    #[Test]
    public function severance_lifts_catalog_bound_lock_and_modify_refusal(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        // Catalogue applicatif (bornage) + item registry locked qui verrouille une capacité.
        ControlHubContractCatalogApp::create([
            'controlhub_contract_id' => $contract->id,
            'app_key' => 'firefox',
        ]);
        $capability = $this->lockedRegistryCapability($contract, 'HKLM', 'Software\\Se5', 'Locked');

        // AVANT la rupture : bornage actif, capacité verrouillée, modif refusée.
        self::assertTrue((new UpstreamCatalogResolver())->isBounded());
        self::assertTrue((new UpstreamLockResolver())->isCapabilityLocked($capability));
        self::assertFalse(
            (new CapabilityPolicy(new UpstreamLockResolver()))->modify($this->refnum(), $capability),
        );

        $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');

        // APRÈS la rupture (résolveurs frais) : tout tombe via active() → null.
        self::assertFalse((new UpstreamCatalogResolver())->isBounded());
        self::assertFalse((new UpstreamLockResolver())->isCapabilityLocked($capability));
        self::assertTrue(
            (new CapabilityPolicy(new UpstreamLockResolver()))->modify($this->refnum(), $capability),
        );
    }

    #[Test]
    public function after_severance_the_lock_resolver_never_queries_items(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        $this->lockedRegistryCapability($contract, 'HKLM', 'Software\\Se5', 'Locked');

        $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');

        DB::enableQueryLog();
        $resolver = new UpstreamLockResolver();
        $resolver->lockedRegistryKeys(); // déclenche ensureResolved()
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Court-circuit : 1 requête (contrat actif ? → null), JAMAIS la table items.
        $touchedItems = collect($queries)->contains(
            fn (array $q): bool => str_contains((string) $q['query'], 'controlhub_contract_items'),
        );
        self::assertFalse($touchedItems, 'Après severed, aucune requête sur controlhub_contract_items.');
    }

    // ── AC3 — supports locaux conservés (jamais écrasés) ──────────────────────

    #[Test]
    public function a_preexisting_local_override_is_preserved_and_never_overwritten(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        $parc = WorkstationGroup::factory()->logical()->create(['name' => 'Salle Info']);
        $ws = Workstation::factory()->create(['name' => 'PC-INFO1']);
        $ws->groups()->attach($parc->id);

        // Capacité projetée registre + item amont locked (impose 'on' = registre 1).
        $capability = $this->registryCapability('HKLM', 'Software\\Se5', 'Kiosk', default: 'off');
        $this->lockedRegistryItem($contract, 'HKLM', 'Software\\Se5', 'Kiosk', '1');

        // Override LOCAL préexistant du refnum (support local) — valeur 'off' DISTINCTE
        // de la valeur imposée 'on'.
        DB::table('capability_assignments')->insert([
            'capability_id' => $capability->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $parc->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // App matérialisée depuis l'amont (managed_by_control_hub = true).
        $app = Application::create([
            'app_id' => 'firefox',
            'name' => 'Firefox',
            'status' => ApplicationStatus::Available,
            'managed_by_control_hub' => true,
        ]);

        $result = $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');

        // L'override local survit TEL QUEL (jamais touché par la matérialisation) :
        // le défaut d'instance est posé À CÔTÉ (correctif #7), l'override par parc
        // reste intact et continue de PRIMER (AC3).
        $row = DB::table('capability_assignments')
            ->where('capability_id', $capability->id)
            ->where('assignable_id', $parc->id)
            ->first();
        self::assertNotNull($row);
        self::assertSame('off', $row->value, 'override par parc jamais écrasé');
        // Le défaut d'instance a bien été posé ('on'), mais l'override par parc prime :
        self::assertSame(1, $result->valuesMaterialized, 'défaut d\'instance posé (l\'override par parc reste à côté)');
        self::assertSame('on', $capability->fresh()->default_value);
        // PARITÉ AC3 : un poste DU parc overridé garde 'off' (registre 0) — l'override
        // plus spécifique prime sur le défaut d'instance 'on'.
        $effective = $this->registryValueForKey($this->compileRegistry($ws), 'HKLM', 'Software\\Se5', 'Kiosk');
        self::assertSame(0, $effective, 'l\'override par parc (off) prime sur le défaut d\'instance (on)');

        // L'app matérialisée survit + flag d'origine conservé (Q2 — pas de retombée).
        $app->refresh();
        self::assertSame(ApplicationStatus::Available, $app->status);
        self::assertTrue($app->managed_by_control_hub);
    }

    // ── AC4 — PARITÉ D'ÉTAT : registry locked conservé (M1/M2) ────────────────

    #[Test]
    public function instance_locked_registry_state_is_identical_before_and_after_severance(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        $parc = WorkstationGroup::factory()->logical()->create(['name' => 'Parc A']);
        $ws = Workstation::factory()->create(['name' => 'PC-A1']);
        $ws->groups()->attach($parc->id);

        // Capacité projetée registre, défaut LOCAL 'off' (= registre 0), VERROUILLÉE
        // amont à 'on' (= registre 1) via un item registry/locked/instance.
        $capability = $this->registryCapability('HKLM', 'Software\\Se5', 'Kiosk', default: 'off');
        $this->lockedRegistryItem($contract, 'HKLM', 'Software\\Se5', 'Kiosk', '1');

        // AVANT : la capacité est verrouillée ; le compilé émet la valeur IMPOSÉE (1),
        // pas le défaut local (0).
        self::assertTrue((new UpstreamLockResolver())->isCapabilityLocked($capability));
        $before = $this->registryValueForKey($this->compileRegistry($ws), 'HKLM', 'Software\\Se5', 'Kiosk');
        self::assertSame(1, $before, 'amont impose la valeur registre 1');

        $result = $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');

        // Correctif #7 : la valeur de capacité 'on' a été RECOUVRÉE depuis la valeur
        // registre 1 et figée dans le DÉFAUT D'INSTANCE (`capabilities.default_value`),
        // PAS en override par parc (couvre tous les postes, même hors parc).
        self::assertSame(1, $result->valuesMaterialized);
        self::assertSame('on', $capability->fresh()->default_value);
        self::assertSame(0, DB::table('capability_assignments')->count(), 'aucun override par parc (instance = défaut)');

        // APRÈS : plus de contrat actif (verrou levé) MAIS la sortie compilée est
        // IDENTIQUE (1 conservé via le défaut d'instance), et la capacité est éditable.
        self::assertNull(ControlHubContract::active());
        self::assertFalse((new UpstreamLockResolver())->isCapabilityLocked($capability->refresh()));
        $after = $this->registryValueForKey($this->compileRegistry($ws), 'HKLM', 'Software\\Se5', 'Kiosk');
        self::assertSame($before, $after, 'état du parc identique avant/après rupture');
        self::assertTrue(
            (new CapabilityPolicy(new UpstreamLockResolver()))->modify($this->refnum(), $capability),
        );
    }

    #[Test]
    public function instance_locked_registry_parity_holds_for_a_workstation_outside_any_parc(): void
    {
        // Correctif #7 — PREUVE de parité pour un poste HORS de tout parc logique
        // (aucun groupe) : c'est précisément ce que le défaut d'instance couvre et
        // qu'un override par salle physique ne couvrirait PAS.
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        // Poste rattaché à AUCUN parc (ni logique ni physique).
        $ws = Workstation::factory()->create(['name' => 'PC-ORPHELIN']);

        $capability = $this->registryCapability('HKLM', 'Software\\Se5', 'Orphan', default: 'off');
        $this->lockedRegistryItem($contract, 'HKLM', 'Software\\Se5', 'Orphan', '1');

        // AVANT : l'item `instance` est broadcast à TOUTE la flotte ⇒ le poste hors
        // parc reçoit quand même la valeur imposée (1), pas le défaut local (0).
        $before = $this->registryValueForKey($this->compileRegistry($ws), 'HKLM', 'Software\\Se5', 'Orphan');
        self::assertSame(1, $before, 'amont impose la valeur registre 1 même hors parc');

        $result = $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');

        // La valeur est figée dans le DÉFAUT D'INSTANCE — AUCUN override de groupe créé.
        self::assertSame(1, $result->valuesMaterialized);
        self::assertSame('on', $capability->fresh()->default_value);
        self::assertSame(0, DB::table('capability_assignments')->count(), 'pas d\'override (le poste n\'a aucun groupe)');

        // APRÈS : verrou levé MAIS le poste hors parc émet la MÊME valeur (1), via le
        // défaut d'instance — un override par salle physique ne l\'aurait pas couvert.
        self::assertNull(ControlHubContract::active());
        $after = $this->registryValueForKey($this->compileRegistry($ws), 'HKLM', 'Software\\Se5', 'Orphan');
        self::assertSame($before, $after, 'parité conservée pour un poste hors de tout parc');
    }

    #[Test]
    public function label_locked_registry_state_is_materialized_only_on_carrying_parcs(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        $carrying = WorkstationGroup::factory()->logical()->create([
            'name' => 'Salle TP', 'controlhub_label' => 'salle-tp',
        ]);
        $other = WorkstationGroup::factory()->logical()->create([
            'name' => 'Autre', 'controlhub_label' => 'autre',
        ]);
        $wsCarrying = Workstation::factory()->create(['name' => 'PC-TP1']);
        $wsCarrying->groups()->attach($carrying->id);

        $capability = $this->registryCapability('HKLM', 'Software\\Se5', 'Tp', default: 'off');
        ControlHubContractItem::factory()->for($contract, 'contract')->forLabel('salle-tp')->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKLM|Software\\Se5|Tp|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);

        // AVANT : le poste porteur reçoit la valeur imposée (1).
        $before = $this->registryValueForKey($this->compileRegistry($wsCarrying), 'HKLM', 'Software\\Se5', 'Tp');
        self::assertSame(1, $before);

        $result = $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');

        // Matérialisé UNIQUEMENT sur le parc portant le label.
        self::assertSame(1, $result->valuesMaterialized);
        self::assertTrue(
            DB::table('capability_assignments')
                ->where('capability_id', $capability->id)
                ->where('assignable_id', $carrying->id)->exists(),
        );
        self::assertFalse(
            DB::table('capability_assignments')
                ->where('capability_id', $capability->id)
                ->where('assignable_id', $other->id)->exists(),
        );

        // PARITÉ : la sortie compilée du poste porteur reste IDENTIQUE après rupture.
        self::assertNull(ControlHubContract::active());
        $after = $this->registryValueForKey($this->compileRegistry($wsCarrying), 'HKLM', 'Software\\Se5', 'Tp');
        self::assertSame($before, $after);
    }

    #[Test]
    public function permissive_registry_items_are_not_materialized(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        WorkstationGroup::factory()->logical()->create(['name' => 'Parc']);
        $this->registryCapability('HKLM', 'Software\\Se5', 'Perm', default: 'off');
        ControlHubContractItem::factory()->for($contract, 'contract')->permissive()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKLM|Software\\Se5|Perm|REG_DWORD',
            'value' => '1',
        ]);

        $result = $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');

        // Un permissif est un plancher déjà battu par le défaut local → rien à conserver.
        self::assertSame(0, $result->valuesMaterialized);
        self::assertSame(0, DB::table('capability_assignments')->count());
    }

    // ── AC4 — PARITÉ D'ÉTAT : app ordonnée amont conservée ────────────────────

    #[Test]
    public function ordered_application_assignment_is_preserved_after_severance(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        $parc = WorkstationGroup::factory()->logical()->create(['name' => 'Parc App']);
        $ws = Workstation::factory()->create(['name' => 'PC-APP1']);
        $ws->groups()->attach($parc->id);

        // App matérialisée (ligne Application présente) ORDONNÉE amont (item applications,
        // cible `instance` par défaut), mais SANS aucune affectation locale : le poste
        // ne la reçoit QUE via l'ordre amont.
        $app = Application::create([
            'app_id' => 'firefox', 'name' => 'Firefox',
            'status' => ApplicationStatus::Available, 'managed_by_control_hub' => true,
        ]);
        self::assertFalse($app->fresh()->is_parc_default);
        ControlHubContractItem::factory()->for($contract, 'contract')->create([
            'type' => Application::TYPE_APPLICATIONS,
            'key' => 'firefox',
            'value' => null,
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);

        // AVANT : le poste reçoit firefox via l'ORDRE amont (aucune affectation locale).
        $before = $this->applicationAppIds($this->compileApplications($ws));
        self::assertContains('firefox', $before);
        self::assertSame(0, DB::table('application_workstation_group')->count());

        $result = $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');

        // Correctif #7 : un ordre `instance` est figé en DÉFAUT D'INSTANCE
        // (`Application.is_parc_default`, Broadcast 27.17), PAS en affectation par
        // groupe — AUCUNE ligne pivot créée (ni salle physique ni parc).
        self::assertSame(1, $result->applicationsAssigned);
        self::assertSame(0, DB::table('application_workstation_group')->count(), 'instance = défaut, aucun pivot');
        self::assertTrue($app->fresh()->is_parc_default);

        // APRÈS : ordre amont levé (active() → null) MAIS le poste reçoit TOUJOURS
        // firefox via le défaut d'instance conservé — sortie compilée IDENTIQUE.
        self::assertNull(ControlHubContract::active());
        $after = $this->applicationAppIds($this->compileApplications($ws));
        self::assertSame($before, $after, 'le poste conserve l\'app après rupture');
        self::assertContains('firefox', $after);
    }

    #[Test]
    public function label_ordered_application_is_assigned_to_carrying_parc_not_as_instance_default(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        $carrying = WorkstationGroup::factory()->logical()->create([
            'name' => 'Salle App', 'controlhub_label' => 'salle-app',
        ]);

        $app = Application::create([
            'app_id' => 'gimp', 'name' => 'GIMP',
            'status' => ApplicationStatus::Available, 'managed_by_control_hub' => true,
        ]);
        ControlHubContractItem::factory()->for($contract, 'contract')->forLabel('salle-app')->create([
            'type' => Application::TYPE_APPLICATIONS, 'key' => 'gimp', 'value' => null,
        ]);

        $result = $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');

        // Correctif #7 : un ordre `label` reste une affectation par parc PORTEUR
        // (pivot), JAMAIS un défaut d'instance.
        self::assertSame(1, $result->applicationsAssigned);
        self::assertFalse($app->fresh()->is_parc_default, 'label ⇒ pas de défaut d\'instance');
        self::assertSame(1, DB::table('application_workstation_group')
            ->where('workstation_group_id', $carrying->id)->count());
    }

    // ── AC5 — standalone strictement no-op (NFR3) ─────────────────────────────

    #[Test]
    public function severance_is_a_total_noop_in_standalone(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        // Aucun contrat. Un override local pré-existant ne doit pas bouger.
        $capability = Capability::factory()->create(['key' => 'cap_local']);
        $parc = WorkstationGroup::factory()->logical()->create(['name' => 'Parc']);
        DB::table('capability_assignments')->insert([
            'capability_id' => $capability->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $parc->id,
            'value' => 'on',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_API, 'controlhub');

        self::assertFalse($result->severed);
        self::assertSame(0, ControlHubLinkAuditLog::count());
        Event::assertNotDispatched(ControlHubContractChanged::class);
        self::assertSame(1, DB::table('capability_assignments')->count());
    }

    // ── AC6 — récap d'audit ───────────────────────────────────────────────────

    #[Test]
    public function the_audit_log_records_origin_actor_contract_and_summary(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        // 3 items shortcuts (locked) + 1 item `absent` (n'impose rien → exclu du compte).
        ControlHubContractItem::factory()->count(3)->for($contract, 'contract')->create([
            'type' => 'shortcuts',
        ]);
        ControlHubContractItem::factory()->for($contract, 'contract')->absent()->create([
            'type' => 'shortcuts',
        ]);

        // Correctif review #10 : un VRAI item `registry/locked` (matérialisé en défaut
        // d'instance) ET un VRAI item `applications` (matérialisé en is_parc_default),
        // pour PROUVER que les compteurs de matérialisation sont audités.
        $this->registryCapability('HKLM', 'Software\\Se5', 'Audit', default: 'off');
        $this->lockedRegistryItem($contract, 'HKLM', 'Software\\Se5', 'Audit', '1');
        Application::create([
            'app_id' => 'firefox', 'name' => 'Firefox',
            'status' => ApplicationStatus::Available, 'managed_by_control_hub' => true,
        ]);
        ControlHubContractItem::factory()->for($contract, 'contract')->create([
            'type' => Application::TYPE_APPLICATIONS, 'key' => 'firefox', 'value' => null,
        ]);
        Application::create([
            'app_id' => 'vlc', 'name' => 'VLC',
            'status' => ApplicationStatus::Available, 'managed_by_control_hub' => true,
        ]);

        $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_API, 'controlhub:instance', 'révocation refnum');

        $log = ControlHubLinkAuditLog::sole();
        self::assertSame($contract->id, $log->controlhub_contract_id);
        self::assertSame('active', $log->from_state);
        self::assertSame('severed', $log->to_state);
        self::assertSame(ControlHubLinkAuditLog::ORIGIN_API, $log->origin);
        self::assertSame('controlhub:instance', $log->actor_label);
        self::assertSame('révocation refnum', $log->reason);
        // Correctif review #2 : `items_lifted` ne compte QUE locked+permissive (l'absent
        // exclu) : 3 shortcuts + 1 registry + 1 applications = 5.
        self::assertSame(5, $log->summary['items_lifted']);
        // 2 apps managed_by_control_hub conservées (firefox + vlc).
        self::assertSame(2, $log->summary['apps_preserved']);
        // Correctif review #10 : compteurs de matérialisation audités.
        self::assertSame(1, $log->summary['values_materialized'], 'défaut d\'instance registry audité');
        self::assertSame(1, $log->summary['applications_assigned'], 'défaut d\'instance app audité');
    }

    #[Test]
    public function the_audit_log_is_append_only(): void
    {
        $log = ControlHubLinkAuditLog::log(
            contractId: null,
            fromState: 'active',
            toState: 'severed',
            origin: ControlHubLinkAuditLog::ORIGIN_COMMAND,
            actorLabel: 'cli',
            reason: null,
            summary: [],
        );

        $this->expectException(\LogicException::class);
        $log->reason = 'tentative de mutation';
        $log->save();
    }

    // ── Story 51.1 (AC10) — rupture = release PASSIF côté dépôts ──────────────

    #[Test]
    public function severance_lifts_the_depot_add_lock_and_keeps_the_imposed_depot_and_apps(): void
    {
        Event::fake([ControlHubContractChanged::class]);

        $contract = ControlHubContract::factory()->create();
        ControlHubContractCatalogApp::create([
            'controlhub_contract_id' => $contract->id, 'app_key' => 'firefox',
        ]);

        // Dépôt imposé matérialisé + app transférée dessus.
        $imposed = \App\Services\ControlHub\ImposedDepotReconciler::getOrCreateImposedDepot();
        $app = Application::create([
            'depot_id' => $imposed->id, 'app_id' => 'firefox', 'name' => 'Firefox',
            'status' => ApplicationStatus::Installed,
        ]);

        // AVANT : contrat actif ⇒ le verrou d'ajout de dépôt est actif.
        self::assertNotNull(ControlHubContract::active());

        $this->service()->sever(ControlHubLinkAuditLog::ORIGIN_COMMAND, 'refnum01');

        // APRÈS : le verrou d'ajout tombe via active() → null (release PASSIF, aucune
        // écriture dépôt dans sever()).
        self::assertNull(ControlHubContract::active());
        // Le dépôt imposé reste dans la liste (état figé), toujours marqué is_imposed
        // (orphelin — restera exclu de la synchro HTTP), et l'app conserve son depot_id.
        $imposed->refresh();
        self::assertNotNull(\App\Models\Depot::find($imposed->id));
        self::assertTrue($imposed->is_imposed);
        self::assertSame($imposed->id, $app->fresh()->depot_id);
    }

    // ── Q2/#5 — trace d'origine des apps matérialisées (vrai service) ─────────

    #[Test]
    public function materialize_from_source_marks_managed_and_does_not_reset_a_preexisting_local_app(): void
    {
        /** @var AppStoreService $store */
        $store = app(AppStoreService::class);

        // Création amont : flag d'origine posé.
        $created = $store->materializeFromSource('firefox', [
            'name' => 'Firefox', 'xml_url' => 'http://depot/firefox.xml', 'xml_sha' => 'abc123',
        ]);
        self::assertTrue($created->fresh()->managed_by_control_hub, 'app matérialisée ⇒ managed_by_control_hub=true');

        // App LOCALE préexistante (managed=false) : un 2e appel (firstOrCreate) NE la
        // réinitialise PAS (flag + métadonnées préservés — AC3).
        $local = Application::create([
            'app_id' => 'vlc', 'name' => 'VLC',
            'status' => ApplicationStatus::Available, 'managed_by_control_hub' => false,
        ]);
        $store->materializeFromSource('vlc', ['name' => 'VLC (amont)']);

        $local->refresh();
        self::assertFalse($local->managed_by_control_hub, 'app locale préexistante : flag JAMAIS forcé à true');
        self::assertSame('VLC', $local->name, 'métadonnées locales préservées (firstOrCreate)');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function refnum(): User
    {
        return User::firstWhere('login', 'refnum01')
            ?? User::factory()->create(['login' => 'refnum01']);
    }

    /**
     * Crée une capacité projetée registre (1 clé, map `{on:1, off:0}`) avec un défaut
     * LOCAL paramétrable. PAS d'item amont (à ajouter via {@see self::lockedRegistryItem()}).
     */
    private function registryCapability(
        string $hive,
        string $path,
        string $name,
        string $default = 'on',
    ): Capability {
        $capability = Capability::factory()->create(['default_value' => $default]);
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

    /** Ajoute au contrat un item registry/locked/instance verrouillant une clé. */
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
     * Crée une capacité projetée registre + un item amont `locked`/`instance`/
     * `registry` qui la verrouille (clé alignée hive|path|name). Conservé pour AC2
     * (n'examine pas la valeur).
     */
    private function lockedRegistryCapability(
        ControlHubContract $contract,
        string $hive,
        string $path,
        string $name,
    ): Capability {
        $capability = $this->registryCapability($hive, $path, $name, default: 'on');
        $this->lockedRegistryItem($contract, $hive, $path, $name, '1');

        return $capability;
    }

    /**
     * Compile l'état desired-state d'un poste pour les providers registry, décorés
     * amont (un candidat `Upstream` gagne si un item locked existe). Source FRAÎCHE à
     * chaque appel (pas de mémoïsation périmée entre avant/après rupture).
     *
     * @return array<string,mixed>
     */
    private function compileRegistry(Workstation $ws): array
    {
        $source = new UpstreamContractSource([new RegistryUpstreamAdapter()]);
        $providers = [
            UpstreamAwareProvider::wrap(new RegistryMachineCapabilityProvider(), $source),
            UpstreamAwareProvider::wrap(new RegistryUserCapabilityProvider(), $source),
        ];

        return (new StateCompiler(new StateHasher(), $providers, new AgentTtlResolver()))->compile(TargetContext::for($ws, null));
    }

    /**
     * Compile l'état desired-state d'un poste pour le provider `applications`. La
     * source amont (ordres d'install) est FRAÎCHE à chaque appel.
     *
     * @return array<string,mixed>
     */
    private function compileApplications(Workstation $ws): array
    {
        $provider = new ApplicationsStateProvider(
            app(WorkstationPackagesResolver::class),
            new UpstreamContractSource([]),
        );

        return (new StateCompiler(new StateHasher(), [$provider], new AgentTtlResolver()))->compile(TargetContext::for($ws, null));
    }

    /**
     * Valeur registre compilée pour UNE clé précise (hive/path/name) dans la portée
     * MACHINE — isole notre clé du bruit des capacités seedées. `null` si absente.
     *
     * @param  array<string,mixed>  $state
     */
    private function registryValueForKey(array $state, string $hive, string $path, string $name): mixed
    {
        foreach ($state[StateContract::SCOPE_MACHINE] ?? [] as $item) {
            if ($item['type'] !== 'registry') {
                continue;
            }
            $p = $item['payload'];
            if (($p['hive'] ?? null) === $hive && ($p['path'] ?? null) === $path && ($p['name'] ?? null) === $name) {
                return $p['value'];
            }
        }

        return null;
    }

    /**
     * `app_id` des items `applications` de la portée MACHINE d'un état compilé.
     *
     * @param  array<string,mixed>  $state
     * @return list<string>
     */
    private function applicationAppIds(array $state): array
    {
        $items = $state[StateContract::SCOPE_MACHINE] ?? [];

        return array_values(array_map(
            static fn (array $i): string => (string) $i['payload']['app_id'],
            array_filter($items, static fn (array $i): bool => $i['type'] === Application::TYPE_APPLICATIONS),
        ));
    }
}
