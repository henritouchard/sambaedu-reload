<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\Workstation;
use App\Services\Agent\Contracts\KeyedExclusiveProvider;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use App\Services\ControlHub\Resolution\KeyedUpstreamAwareProvider;
use App\Services\ControlHub\Resolution\RegistryUpstreamAdapter;
use App\Services\ControlHub\Resolution\ShortcutsUpstreamAdapter;
use App\Services\ControlHub\Resolution\UpstreamAwareProvider;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Services\ControlHub\Resolution\UpstreamPayloadAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 28.3 — Résolution AMONT > local dans `StateCompiler` (end-to-end).
 *
 * Contrat persisté (factories 28.1) → {@see UpstreamContractSource} → providers
 * décorés ({@see UpstreamAwareProvider}) → compilé. Couvre : amont prime sur
 * local (même clé), empilement local sans équivalent amont, standalone
 * byte-identique + comptage de requêtes (NFR3), déterminisme du hash (NFR4),
 * `absent` non injecté / `locked`+`permissive` priment (AC #6), label ignoré
 * (Epic 30), contrat severed inerte (Epic 32), R3 (aucun « central »).
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`.
 */
class UpstreamContractResolutionTest extends TestCase
{
    use RefreshDatabase;

    private StateHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new StateHasher();
    }

    // ── AC #1 — amont prime sur local pour la même clé (registry) ─────────

    #[Test]
    public function upstream_beats_local_same_key(): void
    {
        $contract = ControlHubContract::factory()->create(); // link_state = active
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|Software\\Foo|Bar|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        // Réglage LOCAL sur la MÊME clé {hive,path,name}, valeur différente.
        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(
                StateMaille::LogicalGroup,
                $this->regPayload('HKCU', 'Software\\Foo', 'Bar', 0),
                now(),
                1,
            ),
        ]);

        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items, 'une seule valeur par clé');
        self::assertSame(1, $items[0]['payload']['value'], 'la valeur AMONT gagne (maille Upstream la plus spécifique)');
    }

    #[Test]
    public function upstream_hklm_item_routes_to_machine_scope(): void
    {
        // Routage de portée : hive=HKLM → portée MACHINE (service SYSTEM), pas
        // SESSION. Le couple (providerType, scope) discrimine les deux providers
        // registry — un item HKLM ne doit atteindre QUE le scope machine.
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKLM|Software\\Policies\\Foo|Bar|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        // Provider registry de portée MACHINE (équivalent RegistryMachineCapabilityProvider).
        $local = $this->keyedExclusiveProvider('registry', StateScope::Machine, []);
        $compiled = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()]);

        $machine = $compiled[StateContract::SCOPE_MACHINE];
        self::assertCount(1, $machine, 'l\'item HKLM est injecté dans la portée MACHINE');
        self::assertSame('HKLM', $machine[0]['payload']['hive']);
        self::assertSame(1, $machine[0]['payload']['value'], 'la valeur amont HKLM est servie en scope machine');
        self::assertSame([], $compiled[StateContract::SCOPE_SESSION], 'aucun item HKLM ne fuit en portée SESSION');
    }

    #[Test]
    public function upstream_reg_multi_sz_value_is_coerced_to_list(): void
    {
        // REG_MULTI_SZ : la value amont (chaîne JSON array) doit être coercée en
        // list<string>, iso AbstractCapabilityStateProvider::typedValue() — sinon
        // l'agent reçoit une string là où il attend un tableau (#2).
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|Software\\Foo|Multi|REG_MULTI_SZ',
            'value' => '["alpha","beta"]',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, []);
        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(['alpha', 'beta'], $items[0]['payload']['value'], 'REG_MULTI_SZ → list<string>');
    }

    // ── AC #2 — empilement : le local sans équivalent amont survit ────────

    #[Test]
    public function local_without_upstream_equivalent_survives_aggregate(): void
    {
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'shortcuts',
            'key' => 'AmontApp',
            'value' => '\\\\srv\\amont.exe',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        // Raccourcis LOCAUX distincts (aucun équivalent amont).
        $local = $this->fakeProvider('shortcuts', ResourceSemantics::Aggregate, StateScope::MachineUser, [
            new StateCandidate(StateMaille::User, ['name' => 'LocalA', 'target' => 'a'], now(), 10),
            new StateCandidate(StateMaille::LogicalGroup, ['name' => 'LocalB', 'target' => 'b'], now(), 20),
        ]);

        $items = $this->compileDecorated([$local], [new ShortcutsUpstreamAdapter()])[StateContract::SCOPE_MACHINE_USER];

        $names = collect($items)->pluck('payload.name')->sort()->values()->all();
        self::assertSame(['AmontApp', 'LocalA', 'LocalB'], $names, 'l\'item amont s\'AJOUTE à l\'union, les locaux survivent');
    }

    #[Test]
    public function local_exclusive_key_not_imposed_by_upstream_survives(): void
    {
        // Type exclusif par clé : l'amont impose la clé Beta ; la clé Alpha (non
        // imposée) conserve sa valeur locale gagnante. Empilement par clé (AC #2).
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Beta|REG_DWORD',
            'value' => '9',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Alpha', 5), now(), 1),
        ]);

        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];
        $byName = collect($items)->keyBy('payload.name');

        self::assertCount(2, $items, 'clé locale Alpha + clé amont Beta s\'accumulent');
        self::assertSame(5, $byName['Alpha']['payload']['value'], 'la clé locale non imposée survit');
        self::assertSame(9, $byName['Beta']['payload']['value'], 'la clé imposée par l\'amont est présente');
    }

    // ── AC #3 / #5c — standalone byte-identique (test révélateur NFR3) ────

    #[Test]
    public function no_active_contract_output_is_byte_identical_and_single_cheap_query(): void
    {
        // Aucun contrat actif : le compilé via providers DÉCORÉS doit être
        // STRICTEMENT identique (items + ordre + hashState) au compilé via les
        // MÊMES providers NON décorés.
        $providers = [
            $this->fakeProvider('agg', ResourceSemantics::Aggregate, StateScope::MachineUser, [
                new StateCandidate(StateMaille::Broadcast, ['k' => 'a'], now(), 1),
                new StateCandidate(StateMaille::User, ['k' => 'b'], now(), 2),
            ]),
            $this->keyedExclusiveProvider('registry', StateScope::Session, [
                new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'X', 7), now(), 3),
            ]),
        ];
        $ctx = $this->machineOnlyContext();

        $source = new UpstreamContractSource([new RegistryUpstreamAdapter(), new ShortcutsUpstreamAdapter()]);
        $decorated = array_map(fn (StateProvider $p): StateProvider => UpstreamAwareProvider::wrap($p, $source), $providers);

        $bare = (new StateCompiler($this->hasher, $providers))->compile($ctx);

        // Comptage de requêtes pendant la compilation décorée.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $deco = (new StateCompiler($this->hasher, $decorated))->compile($ctx);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Byte-identique : items par portée + hash global.
        foreach (StateContract::scopes() as $scope) {
            self::assertSame($bare[$scope], $deco[$scope], "portée {$scope} : décoré == non décoré");
        }
        // hashState() exclut déjà `generated_at` (VOLATILE_STATE_KEYS) : pas de
        // neutralisation à faire ici — l'égalité prouve la byte-identité du reste.
        self::assertSame(
            $this->hasher->hashState($bare),
            $this->hasher->hashState($deco),
            'hashState identique sans contrat (NFR3)',
        );

        // Court-circuit : EXACTEMENT 1 requête « contrat actif ? », ZÉRO sur items
        // (pas de N+1 par provider — la résolution est partagée et mémoïsée).
        $contractQueries = $this->countQueries($log, '"controlhub_contracts"');
        $itemQueries = $this->countQueries($log, '"controlhub_contract_items"');
        self::assertSame(1, $contractQueries, 'au plus 1 requête « contrat actif ? » partagée');
        self::assertSame(0, $itemQueries, 'aucune requête items quand aucun contrat actif (court-circuit)');
    }

    // ── AC #4 — déterminisme du hash avec contrat actif ───────────────────

    #[Test]
    public function deterministic_hash_with_active_contract(): void
    {
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Det|REG_DWORD',
            'value' => '3',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, []);
        $ctx = $this->machineOnlyContext();

        // Source FRAÎCHE par compilation (en prod : conteneur par-requête).
        $first = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx);
        $this->travel(3)->hours();
        $second = $this->compileDecoratedFull([$local], [new RegistryUpstreamAdapter()], $ctx);

        self::assertNotSame($first['generated_at'], $second['generated_at']);
        self::assertSame(
            $this->hasher->hashState($first),
            $this->hasher->hashState($second),
            'même contrat + même contexte → même hash (ETag 23.5)',
        );
        // L'item amont est bien servi (sourceId = id de l'item contrat, stable).
        self::assertSame(3, $first[StateContract::SCOPE_SESSION][0]['payload']['value']);
    }

    // ── AC #6 — absent non injecté ; locked + permissive priment ──────────

    #[Test]
    public function absent_item_is_not_injected(): void
    {
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->absent()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        // Réglage local sur la clé que l'amont déclare « absente ».
        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Foo', 7), now(), 1),
        ]);

        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(7, $items[0]['payload']['value'], 'l\'item absent n\'est pas injecté → le local survit');
    }

    #[Test]
    public function locked_wins_over_local_but_permissive_is_overridden_by_local(): void
    {
        // Story 29.3 (relaxation permissive livrée) — la maille diverge selon
        // l'enforcement : `locked` → `Upstream` (rang -1, INBATTABLE) ;
        // `permissive` → `UpstreamPermissive` (rang 6, PLANCHER battable). Un
        // override local (maille `LogicalGroup`) surcharge donc le `permissive`
        // mais JAMAIS le `locked`. (Remplace l'ancien comportement 28.3 où
        // `permissive` se comportait comme `locked` — couture Epic 29 fermée.)
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->permissive()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Perm|REG_DWORD',
            'value' => '1',
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Lock|REG_DWORD',
            'value' => '2',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Perm', 0), now(), 1),
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Lock', 0), now(), 2),
        ]);

        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];
        $byName = collect($items)->keyBy('payload.name');

        self::assertSame(0, $byName['Perm']['payload']['value'], 'permissive est un plancher : l\'override local le surcharge (FR4 — Story 29.3)');
        self::assertSame(2, $byName['Lock']['payload']['value'], 'locked reste inbattable (Upstream rang -1) — l\'amont prime sur le local');
    }

    // ── Bornage : label ignoré (Epic 30) ; severed inerte (Epic 32) ───────

    #[Test]
    public function label_targeted_item_is_ignored(): void
    {
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->forLabel('salle-info')->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
            'value' => '1',
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Foo', 0), now(), 1),
        ]);

        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(0, $items[0]['payload']['value'], 'un item target_type=label est ignoré en 28.3 (Epic 30)');
    }

    #[Test]
    public function severed_contract_injects_nothing(): void
    {
        $contract = ControlHubContract::factory()->severed()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|P|Foo|REG_DWORD',
            'value' => '1',
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Foo', 0), now(), 1),
        ]);

        $items = $this->compileDecorated([$local], [new RegistryUpstreamAdapter()])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(0, $items[0]['payload']['value'], 'un contrat severed (non actif) n\'injecte aucun candidat');
    }

    // ── Câblage conteneur : tous les providers sont enrobés ───────────────

    #[Test]
    public function container_wires_every_provider_through_the_decorator(): void
    {
        $compiler = $this->app->make(StateCompiler::class);

        $prop = new \ReflectionProperty(StateCompiler::class, 'providers');
        $prop->setAccessible(true);
        /** @var array<int, StateProvider> $providers */
        $providers = $prop->getValue($compiler);

        self::assertNotEmpty($providers);
        foreach ($providers as $provider) {
            self::assertInstanceOf(
                UpstreamAwareProvider::class,
                $provider,
                'chaque provider du registry est enrobé par le décorateur amont',
            );
        }
        // Au moins un provider registry (KeyedExclusiveProvider) conserve son
        // marqueur au travers du décorateur.
        $keyed = array_filter($providers, fn ($p): bool => $p instanceof KeyedUpstreamAwareProvider);
        self::assertNotEmpty($keyed, 'le marqueur KeyedExclusiveProvider est relayé (registry)');
        foreach ($keyed as $p) {
            self::assertInstanceOf(KeyedExclusiveProvider::class, $p);
        }
    }

    // ── AC #5b — R3 : aucun identifiant « central » ───────────────────────

    #[Test]
    public function r3_no_central_identifier(): void
    {
        $deliveredFqcns = [
            UpstreamContractSource::class,
            UpstreamAwareProvider::class,
            KeyedUpstreamAwareProvider::class,
            UpstreamPayloadAdapter::class,
            RegistryUpstreamAdapter::class,
            ShortcutsUpstreamAdapter::class,
            StateMaille::class,
        ];

        foreach ($deliveredFqcns as $fqcn) {
            $this->assertStringNotContainsStringIgnoringCase('central', $fqcn, "FQCN « {$fqcn} » ne doit pas contenir « central ».");

            $reflection = new \ReflectionClass($fqcn);
            foreach ($reflection->getMethods() as $method) {
                $this->assertStringNotContainsStringIgnoringCase('central', $method->getName(), "Méthode {$fqcn}::{$method->getName()}");
            }
            foreach ($reflection->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('central', $property->getName());
            }
            foreach (array_keys($reflection->getConstants()) as $constName) {
                $this->assertStringNotContainsStringIgnoringCase('central', (string) $constName);
            }
        }

        // La nouvelle maille est bien `Upstream` (valeur 'upstream'), jamais central.
        self::assertSame('upstream', StateMaille::Upstream->value);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Compile un jeu de providers ENROBÉS par le décorateur amont (source fraîche
     * lisant le contrat actif persisté) sur un contexte machine-only.
     *
     * @param  list<StateProvider>  $providers
     * @param  list<UpstreamPayloadAdapter>  $adapters
     * @return array<string,mixed>
     */
    private function compileDecorated(array $providers, array $adapters): array
    {
        return $this->compileDecoratedFull($providers, $adapters, $this->machineOnlyContext());
    }

    /**
     * @param  list<StateProvider>  $providers
     * @param  list<UpstreamPayloadAdapter>  $adapters
     * @return array<string,mixed>
     */
    private function compileDecoratedFull(array $providers, array $adapters, TargetContext $ctx): array
    {
        $source = new UpstreamContractSource($adapters);
        $decorated = array_map(
            fn (StateProvider $p): StateProvider => UpstreamAwareProvider::wrap($p, $source),
            $providers,
        );

        return (new StateCompiler($this->hasher, $decorated))->compile($ctx);
    }

    private function machineOnlyContext(): TargetContext
    {
        return TargetContext::for(Workstation::factory()->create(), null);
    }

    /**
     * @param  list<array{query:string,bindings:array<mixed>,time:float}>  $log
     */
    private function countQueries(array $log, string $needle): int
    {
        return count(array_filter($log, static fn (array $q): bool => str_contains($q['query'], $needle)));
    }

    /**
     * @return array<string,mixed>
     */
    private function regPayload(string $hive, string $path, string $name, int $value): array
    {
        return [
            'hive' => $hive,
            'path' => $path,
            'name' => $name,
            'type' => 'REG_DWORD',
            'value' => $value,
        ];
    }

    /**
     * @param  list<StateCandidate>  $candidates
     */
    private function fakeProvider(
        string $type,
        ResourceSemantics $semantics,
        StateScope $scope,
        array $candidates,
    ): StateProvider {
        return new class($type, $semantics, $scope, $candidates) implements StateProvider
        {
            /** @param list<StateCandidate> $candidates */
            public function __construct(
                private readonly string $type,
                private readonly ResourceSemantics $semantics,
                private readonly StateScope $scope,
                private readonly array $candidates,
            ) {}

            public function type(): string
            {
                return $this->type;
            }

            public function semantics(): ResourceSemantics
            {
                return $this->semantics;
            }

            public function scope(): StateScope
            {
                return $this->scope;
            }

            public function itemsFor(TargetContext $ctx): Collection
            {
                return collect($this->candidates);
            }
        };
    }

    /**
     * @param  list<StateCandidate>  $candidates
     */
    private function keyedExclusiveProvider(
        string $type,
        StateScope $scope,
        array $candidates,
    ): StateProvider {
        return new class($type, $scope, $candidates) implements KeyedExclusiveProvider, StateProvider
        {
            /** @param list<StateCandidate> $candidates */
            public function __construct(
                private readonly string $type,
                private readonly StateScope $scope,
                private readonly array $candidates,
            ) {}

            public function type(): string
            {
                return $this->type;
            }

            public function semantics(): ResourceSemantics
            {
                return ResourceSemantics::Exclusive;
            }

            public function scope(): StateScope
            {
                return $this->scope;
            }

            public function exclusiveKey(array $payload): string
            {
                return strtolower(($payload['hive'] ?? '').'|'.($payload['path'] ?? '').'|'.($payload['name'] ?? ''));
            }

            public function itemsFor(TargetContext $ctx): Collection
            {
                return collect($this->candidates);
            }
        };
    }
}
