<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Models\Application;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\ApplicationsStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use App\Services\ControlHub\Resolution\RegistryUpstreamAdapter;
use App\Services\ControlHub\Resolution\UpstreamAwareProvider;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 31.2 — Déclenchement d'install en DÉSIR D'ÉTAT (FR6).
 *
 * Un ORDRE d'install amont = un `controlhub_contract_items` `type='applications'`,
 * `key=<app_id>`, `enforcement_state` non-`absent`, cible `instance|label` (28.1/28.2
 * l'acceptent DÉJÀ). 31.2 le PROJETTE dans l'ensemble `applications` désiré du poste
 * via {@see UpstreamContractSource::orderedApplicationAppIds()} (3ᵉ accesseur lecture
 * seule) unionné à l'ensemble cible d'{@see ApplicationsStateProvider} AVANT
 * hydratation — payload `{app_id, name}` IDENTIQUE quelle que soit la source ⇒ dédup
 * aggregate naturelle (idempotence). Pont au niveau ENSEMBLE (D3), JAMAIS via
 * adaptateur (anti double-injection / anti doublon de `name`).
 *
 * Couvre : injection instance (AC1), ciblage label porté/non porté (AC2), dédup +
 * idempotence/`travel()` (AC3), standalone & contrat-sans-item-applications court-circuit
 * zéro requête (AC4), déterminisme de l'union (AC5), accesseur unitaire 3 états + `absent`
 * non projeté. Le contrat agent figé (golden/`ContractV1`) est validé par sa propre suite
 * (aucun contrat dans les fixtures ⇒ aucun ordre injecté).
 *
 * Tests HÔTE (php8.4 + pdo_sqlite). Harnais {@see WpkgSchemaBootstrapper} (sans
 * `RefreshDatabase` — cf. note `ApplicationsStateProviderTest`). SQLite n'applique pas
 * varchar/enum PG → on teste des DÉCISIONS (présence/absence/count/hash), jamais des
 * bornes de colonne ; on matche sur `app_id` (string), jamais l'`id` numérique.
 */
class UpstreamInstallOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Projection Postgres-pure : aucune synchro AD (host sans LDAP). Le label
        // d'un parc est posé directement sur la colonne `controlhub_label`.
        WorkstationGroupObserver::disableSync();
        WpkgSchemaBootstrapper::bootstrap();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    // ── AC1 — injection d'un ordre d'install cible `instance` ──────────────

    #[Test]
    public function instance_order_injects_the_ordered_app_into_the_machine_scope(): void
    {
        $this->newApp('firefox', 'Mozilla Firefox');
        $contract = ControlHubContract::factory()->create(); // link_state = active
        $this->orderInstance($contract, 'firefox');

        // Poste QUELCONQUE, aucune app affectée localement.
        $ws = Workstation::create(['name' => 'PC31-2-A', 'status' => 'active']);

        $items = $this->provider()->itemsFor($this->ctx($ws));

        // L'app ordonnée par l'amont figure dans l'ensemble désiré (portée machine).
        self::assertSame(['firefox'], $this->appIdsOf($items));

        $firefox = $items->firstOrFail();
        self::assertSame(['app_id', 'name'], array_keys($firefox->payload), 'payload {app_id, name} inchangé (contrat agent figé)');
        self::assertSame('firefox', $firefox->payload['app_id']);
        self::assertSame('Mozilla Firefox', $firefox->payload['name'], 'le name est hydraté depuis l\'Application locale');

        // Via le compilateur : l'item sort bien en portée MACHINE.
        $compiled = $this->compileApplications($ws);
        self::assertSame('firefox', $compiled[StateContract::SCOPE_MACHINE][0]['payload']['app_id']);
        self::assertSame('applications', $compiled[StateContract::SCOPE_MACHINE][0]['type']);
    }

    #[Test]
    public function permissive_install_order_is_also_projected(): void
    {
        // D1 — pour un ordre d'install, `locked` ET `permissive` signifient tous
        // deux « app présente » : l'accesseur 31.2 n'inspecte PAS l'`enforcement_state`
        // (aggregate, rien à arbitrer). Ce test VERROUILLE cette décision : un futur
        // filtre `enforcement_state` qui perdrait le `permissive` le casserait.
        $this->newApp('vlc', 'VLC');
        $contract = ControlHubContract::factory()->create(); // link_state = active
        $this->orderInstancePermissive($contract, 'vlc');

        $ws = Workstation::create(['name' => 'PC31-2-PERM', 'status' => 'active']);

        // L'accesseur projette l'app permissive (présence dans l'ensemble ordonné)…
        self::assertSame(
            ['vlc'],
            (new UpstreamContractSource([]))->orderedApplicationAppIds($this->ctx($ws)),
            'un ordre permissive est projeté au même titre qu\'un locked (D1)',
        );

        // …et elle figure dans l'ensemble final du provider.
        self::assertSame(['vlc'], $this->appIdsOf($this->provider()->itemsFor($this->ctx($ws))));
    }

    // ── AC2 — ciblage par label : porté vs non porté ──────────────────────

    #[Test]
    public function label_order_applies_only_to_a_workstation_carrying_the_label(): void
    {
        $this->newApp('vlc', 'VLC');
        $contract = ControlHubContract::factory()->create();
        $this->orderLabel($contract, 'vlc', 'salle-info');

        // Poste membre d'un parc portant `controlhub_label = salle-info`.
        $carrier = Workstation::create(['name' => 'PC-CARRIER', 'status' => 'active']);
        $carrier->groups()->attach($this->groupWithLabel('parc-info', 'salle-info')->id);

        // Poste membre d'un parc portant un AUTRE label.
        $other = Workstation::create(['name' => 'PC-OTHER', 'status' => 'active']);
        $other->groups()->attach($this->groupWithLabel('parc-autre', 'autre-salle')->id);

        self::assertSame(['vlc'], $this->appIdsOf($this->provider()->itemsFor($this->ctx($carrier))), 'le poste portant le label reçoit l\'app ordonnée');
        self::assertSame([], $this->appIdsOf($this->provider()->itemsFor($this->ctx($other))), 'le poste ne portant pas le label ne la reçoit pas');
    }

    // ── AC3 — dédup (source locale ∪ amont) + idempotence ─────────────────

    #[Test]
    public function locally_assigned_and_upstream_ordered_app_yields_exactly_one_item(): void
    {
        $firefox = $this->newApp('firefox', 'Mozilla Firefox');
        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'firefox');

        // firefox AUSSI affecté localement au poste (pivot direct).
        $ws = Workstation::create(['name' => 'PC-DEDUP', 'status' => 'active']);
        $ws->applications()->attach([$firefox->id]);

        $compiled = $this->compileApplications($ws);
        $machine = $compiled[StateContract::SCOPE_MACHINE];

        // Payload {app_id, name} IDENTIQUE des deux sources ⇒ dédup aggregate ⇒ UN seul item.
        $firefoxItems = array_filter($machine, static fn (array $i): bool => $i['payload']['app_id'] === 'firefox');
        self::assertCount(1, $firefoxItems, 'une app résolue localement ET ordonnée amont = un seul item (dédup aggregate)');
    }

    #[Test]
    public function compiled_state_hash_is_stable_across_two_compilations(): void
    {
        $this->newApp('firefox', 'Mozilla Firefox');
        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'firefox');

        $ws = Workstation::create(['name' => 'PC-IDEMP', 'status' => 'active']);
        $hasher = new StateHasher();

        // Sources/providers FRAÎCHES par compilation (en prod : conteneur par-requête).
        $first = $this->compileApplications($ws, $hasher);
        $this->travel(3)->hours();
        $second = $this->compileApplications($ws, $hasher);

        self::assertNotSame($first['generated_at'], $second['generated_at']);
        self::assertSame(
            $hasher->hashState($first),
            $hasher->hashState($second),
            'même ordre d\'install + même contexte → même hash (idempotence d\'état, NFR4)',
        );
    }

    // ── AC4 — standalone byte-identique + court-circuit NFR3 ───────────────

    #[Test]
    public function standalone_emits_zero_contract_item_query_and_an_unchanged_set(): void
    {
        $appA = $this->newApp('alpha', 'Alpha');
        $ws = Workstation::create(['name' => 'PC-STANDALONE', 'status' => 'active']);
        $ws->applications()->attach([$appA->id]);

        // AUCUN contrat actif (standalone).
        DB::flushQueryLog();
        DB::enableQueryLog();
        $items = $this->provider()->itemsFor($this->ctx($ws));
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertSame(['alpha'], $this->appIdsOf($items), 'ensemble strictement inchangé vs 27.5 (seule l\'app locale)');
        self::assertSame(0, $this->countQueries($log, '"controlhub_contract_items"'), 'aucune requête items sans contrat actif (court-circuit NFR3)');
    }

    #[Test]
    public function active_contract_without_application_item_short_circuits_and_emits_no_group_query(): void
    {
        $appA = $this->newApp('alpha', 'Alpha');
        $contract = ControlHubContract::factory()->create();
        // Contrat actif mais SANS aucun item `applications` (que du `registry`).
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|X|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        // Poste rattaché à un parc PORTANT un label : si le court-circuit manquait,
        // `labelsCarriedBy()` requêterait `workstation_groups`.
        $ws = Workstation::create(['name' => 'PC-NOAPPITEM', 'status' => 'active']);
        $ws->applications()->attach([$appA->id]);
        $ws->groups()->attach($this->groupWithLabel('parc-x', 'un-label')->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $items = $this->provider()->itemsFor($this->ctx($ws));
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertSame(['alpha'], $this->appIdsOf($items), 'aucun ordre `applications` ⇒ ensemble inchangé');
        // `controlhub_label` n'apparaît QUE dans la requête de `labelsCarriedBy()`
        // (la résolution WPKG/TargetContext touche `workstation_groups` mais ne lit
        // jamais cette colonne) — discriminant fiable du court-circuit.
        self::assertSame(0, $this->countQueries($log, 'controlhub_label'), 'court-circuit avant labelsCarriedBy() : zéro requête « labels portés » (NFR3)');
    }

    // ── AC5 — déterminisme de l'union (tri strcasecmp) ────────────────────

    #[Test]
    public function the_ordered_union_is_deterministically_sorted(): void
    {
        $this->newApp('alpha', 'Alpha');
        $this->newApp('bravo', 'Bravo');
        $this->newApp('charlie', 'Charlie');
        $contract = ControlHubContract::factory()->create();
        // Créés dans un ordre NON trié — la sortie doit l'être (strcasecmp).
        $this->orderInstance($contract, 'charlie');
        $this->orderInstance($contract, 'alpha');
        $this->orderInstance($contract, 'bravo');

        $ws = Workstation::create(['name' => 'PC-ORDER', 'status' => 'active']);

        $appIds = $this->provider()->itemsFor($this->ctx($ws))
            ->map(fn (StateCandidate $c): string => $c->payload['app_id'])
            ->all();

        self::assertSame(['alpha', 'bravo', 'charlie'], $appIds, 'union triée déterministe (NFR4)');
    }

    // ── Accesseur unitaire `orderedApplicationAppIds()` ────────────────────

    #[Test]
    public function ordered_app_ids_returns_empty_when_standalone(): void
    {
        $ws = Workstation::create(['name' => 'PC-ACC-STD', 'status' => 'active']);
        self::assertSame([], (new UpstreamContractSource([]))->orderedApplicationAppIds($this->ctx($ws)));
    }

    #[Test]
    public function ordered_app_ids_returns_empty_when_contract_has_no_application_item(): void
    {
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|X|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $ws = Workstation::create(['name' => 'PC-ACC-NOAPP', 'status' => 'active']);
        self::assertSame([], (new UpstreamContractSource([]))->orderedApplicationAppIds($this->ctx($ws)));
    }

    #[Test]
    public function ordered_app_ids_unions_instance_and_carried_labels_sorted_and_deduplicated(): void
    {
        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'charlie');           // instance (toute la flotte)
        $this->orderLabel($contract, 'alpha', 'salle-info');  // label porté
        $this->orderLabel($contract, 'charlie', 'salle-info'); // doublon de source (instance + label)
        $this->orderLabel($contract, 'zeta', 'autre-salle');  // label NON porté

        $ws = Workstation::create(['name' => 'PC-ACC-UNION', 'status' => 'active']);
        $ws->groups()->attach($this->groupWithLabel('parc-info', 'salle-info')->id);

        self::assertSame(
            ['alpha', 'charlie'],
            (new UpstreamContractSource([]))->orderedApplicationAppIds($this->ctx($ws)),
            'instance ∪ labels portés, dédup + tri ; le label non porté (zeta) est exclu',
        );
    }

    #[Test]
    public function an_absent_application_order_is_never_projected(): void
    {
        $contract = ControlHubContract::factory()->create();
        // `absent` = l'autorité n'impose pas cette clé : exclu en amont (ensureResolved).
        ControlHubContractItem::factory()->absent()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => Application::TYPE_APPLICATIONS,
            'key' => 'firefox',
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $ws = Workstation::create(['name' => 'PC-ABSENT', 'status' => 'active']);
        self::assertSame([], (new UpstreamContractSource([]))->orderedApplicationAppIds($this->ctx($ws)));
    }

    // ── D4 amont — ghost `app_id` ordonné sans ligne `Application` ────────

    #[Test]
    public function upstream_order_for_unknown_app_id_is_skipped(): void
    {
        // Caractérisation du CONTRAT DE SURFACE de 31.3 : un ordre d'install amont
        // vers un `app_id` SANS ligne `Application` correspondante est exposé par
        // l'accesseur (31.2 ne MATÉRIALISE rien — c'est 31.3) mais ÉCARTÉ de
        // l'ensemble final du provider (skip + warn D4 — rien à hydrater).
        Log::spy();

        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'ghost-app'); // aucun newApp('ghost-app')

        $ws = Workstation::create(['name' => 'PC31-2-GHOST', 'status' => 'active']);

        // L'accesseur expose l'ordre brut (31.2 ne crée pas l'Application).
        self::assertSame(
            ['ghost-app'],
            (new UpstreamContractSource([]))->orderedApplicationAppIds($this->ctx($ws)),
            '31.2 ordonne sans matérialiser — la matérialisation relève de 31.3',
        );

        // Mais le provider l'écarte : pas de ligne Application à hydrater (skip+warn).
        self::assertSame([], $this->appIdsOf($this->provider()->itemsFor($this->ctx($ws))));

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                return str_contains($message, 'app_id sans ligne Application')
                    && ($context['app_id'] ?? null) === 'ghost-app';
            })
            ->once();
    }

    // ── #A — chemin de PRODUCTION : décorateur `UpstreamAwareProvider` ────

    #[Test]
    public function decorated_provider_emits_exactly_one_item_per_ordered_app(): void
    {
        // Exerce le CÂBLAGE RÉEL (AgentServiceProvider) : ApplicationsStateProvider
        // enrobé par UpstreamAwareProvider, partageant LA MÊME source 28.3 (singleton
        // en prod). La source enregistre les adaptateurs réels — mais AUCUN pour
        // `applications` (garde anti double-injection). Le décorateur est donc un
        // NO-OP pour ce type : l'union amont passe UNIQUEMENT par l'accesseur du
        // provider. Si un futur dev enregistre un UpstreamPayloadAdapter `applications`,
        // l'ordre serait injecté DEUX fois (accesseur + décorateur) ⇒ ce test casse.
        $this->newApp('firefox', 'Mozilla Firefox');
        $contract = ControlHubContract::factory()->create();
        $this->orderInstance($contract, 'firefox');

        $ws = Workstation::create(['name' => 'PC31-2-DECO', 'status' => 'active']);

        // Reproduction fidèle du wrap de production (MÊME instance de source partagée).
        $source = new UpstreamContractSource([new RegistryUpstreamAdapter()]);
        $inner = new ApplicationsStateProvider(new WorkstationPackagesResolver(), $source);
        $decorated = UpstreamAwareProvider::wrap($inner, $source);

        $items = $decorated->itemsFor($this->ctx($ws));

        // EXACTEMENT un item firefox après décoration (pas deux).
        $firefoxItems = array_filter(
            $items->all(),
            static fn (StateCandidate $c): bool => $c->payload['app_id'] === 'firefox',
        );
        self::assertCount(1, $firefoxItems, 'décorateur no-op pour applications : un seul item firefox (pas de double-injection)');
        self::assertSame(['firefox'], $this->appIdsOf($items));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function newApp(string $appId, ?string $name = null): Application
    {
        return Application::create(['app_id' => $appId, 'name' => $name ?? ucfirst($appId)]);
    }

    private function orderInstance(ControlHubContract $contract, string $appId): ControlHubContractItem
    {
        return ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => Application::TYPE_APPLICATIONS,
            'key' => $appId,
            'value' => null,
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);
    }

    private function orderInstancePermissive(ControlHubContract $contract, string $appId): ControlHubContractItem
    {
        // Patron d'`orderInstance()` mais en `permissive` (D1 : même projection).
        return ControlHubContractItem::factory()->permissive()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => Application::TYPE_APPLICATIONS,
            'key' => $appId,
            'value' => null,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);
    }

    private function orderLabel(ControlHubContract $contract, string $appId, string $label): ControlHubContractItem
    {
        return ControlHubContractItem::factory()->forLabel($label)->create([
            'controlhub_contract_id' => $contract->id,
            'type' => Application::TYPE_APPLICATIONS,
            'key' => $appId,
            'value' => null,
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);
    }

    private function groupWithLabel(string $name, string $label): WorkstationGroup
    {
        // Parc LOGIQUE (is_physical=false) porteur d'un label refnum (30.2).
        return WorkstationGroup::create(['name' => $name, 'controlhub_label' => $label]);
    }

    private function provider(): ApplicationsStateProvider
    {
        // SOURCE fraîche par scénario (mémoïsation == par-requête en prod).
        return new ApplicationsStateProvider(new WorkstationPackagesResolver(), new UpstreamContractSource([]));
    }

    private function ctx(Workstation $ws): TargetContext
    {
        // Machine-only (pas de user) : la portée applications est MACHINE.
        return TargetContext::for($ws, null);
    }

    /**
     * @param  Collection<int, StateCandidate>  $candidates
     * @return list<string>  app_id triés
     */
    private function appIdsOf(Collection $candidates): array
    {
        $ids = $candidates->map(fn (StateCandidate $c): string => $c->payload['app_id'])->all();
        sort($ids);

        return $ids;
    }

    /**
     * Compile l'état avec le SEUL provider `applications` (provider/source frais) —
     * exerce la dédup aggregate + le hash du compilateur sans dépendre des autres
     * types (schéma partiel du bootstrapper).
     *
     * @return array<string,mixed>
     */
    private function compileApplications(Workstation $ws, ?StateHasher $hasher = null): array
    {
        $hasher ??= new StateHasher();

        return (new StateCompiler($hasher, [$this->provider()]))->compile($this->ctx($ws));
    }

    /**
     * @param  list<array{query:string,bindings:array<mixed>,time:float}>  $log
     */
    private function countQueries(array $log, string $needle): int
    {
        return count(array_filter($log, static fn (array $q): bool => str_contains($q['query'], $needle)));
    }
}
