<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\AgentTtlResolver;
use App\Services\Agent\Contracts\KeyedExclusiveProvider;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\Providers\RegistryMachineCapabilityProvider;
use App\Services\Agent\Providers\RegistryUserCapabilityProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use App\Services\ControlHub\Resolution\RegistryUpstreamAdapter;
use App\Services\ControlHub\Resolution\UpstreamAwareProvider;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use App\Services\ControlHub\Resolution\UpstreamPayloadAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 29.3 — Relaxation permissive : un override `workstationGroup` local
 * surcharge réellement un item amont `permissive` au compilé, tandis qu'un item
 * `locked` reste inbattable.
 *
 * Symétrique inverse de 29.2 : là où 29.2 REFUSE l'écriture d'un override sur un
 * `locked`, 29.3 fait MORDRE l'override sur un `permissive`. Le cœur du changement
 * est la maille divergente injectée par {@see UpstreamContractSource} — `locked`
 * → `StateMaille::Upstream` (rang -1, inbattable) ; `permissive` →
 * `StateMaille::UpstreamPermissive` (rang 6, plancher battable par TOUTE maille
 * locale, défaut diffusé inclus — décision Henri 2026-06-27 : le permissif est le
 * MOINS spécifique de toute la chaîne).
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`. On teste des VALEURS
 * résolues, jamais des bornes de colonne (SQLite n'applique pas varchar/enum PG).
 */
class PermissiveOverrideResolutionTest extends TestCase
{
    use RefreshDatabase;

    private StateHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new StateHasher();
        WorkstationGroupObserver::disableSync();

        // Catalogue de capacités VIDE : le lot iso seedé par migration brouillerait
        // les assertions sur les valeurs résolues.
        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    // ── AC #1 — l'override local surcharge un item permissif (fake provider) ──

    #[Test]
    public function permissive_is_overridden_by_a_local_group_candidate(): void
    {
        $this->upstreamRegistryItem('HKCU|P|Foo|REG_DWORD', '1', ControlHubEnforcementState::Permissive);

        // Réglage LOCAL sur la MÊME clé {hive,path,name}, à une maille locale
        // (LogicalGroup, rang 3) → bat le plancher permissif (UpstreamPermissive, rang 6).
        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Foo', 0), now(), 1),
        ]);

        $items = $this->compileDecorated([$local])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items, 'une seule valeur par clé');
        self::assertSame(0, $items[0]['payload']['value'], 'l\'override LOCAL gagne sur le plancher permissif amont (FR4)');
    }

    // ── AC #2 — sans candidat local, le permissif s'applique comme baseline ──

    #[Test]
    public function permissive_applies_as_baseline_when_no_local_candidate(): void
    {
        $this->upstreamRegistryItem('HKCU|P|Foo|REG_DWORD', '5', ControlHubEnforcementState::Permissive);

        // AUCUN candidat local pour cette clé : le plancher permissif est seul → il s'applique.
        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, []);

        $items = $this->compileDecorated([$local])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(5, $items[0]['payload']['value'], 'en l\'absence TOTALE de candidat local, la valeur permissive amont est la baseline (AC #2)');
    }

    // ── Décision Henri — le permissif est SOUS le défaut diffusé (Broadcast) ──

    #[Test]
    public function permissive_is_below_the_broadcast_default(): void
    {
        $this->upstreamRegistryItem('HKCU|P|Foo|REG_DWORD', '5', ControlHubEnforcementState::Permissive);

        // Le défaut diffusé (Broadcast, rang 5) bat le plancher permissif (rang 6) :
        // PAS de nuance « permissif bat le défaut diffusé » (décision tranchée).
        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::Broadcast, $this->regPayload('HKCU', 'P', 'Foo', 1), now(), 1),
        ]);

        $items = $this->compileDecorated([$local])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(1, $items[0]['payload']['value'], 'le défaut diffusé (Broadcast) surcharge le plancher permissif amont');
    }

    // ── AC #4 — locked reste inbattable (non-régression 28.3/29.2) ────────────

    #[Test]
    public function locked_still_wins_over_a_local_group_candidate(): void
    {
        $this->upstreamRegistryItem('HKCU|P|Foo|REG_DWORD', '9', ControlHubEnforcementState::Locked);

        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Foo', 0), now(), 1),
        ]);

        $items = $this->compileDecorated([$local])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame(9, $items[0]['payload']['value'], 'locked reste INBATTABLE (Upstream rang -1) — la relaxation 29.3 ne touche QUE permissive (AC #4)');
    }

    // ── AC #1 + #5 — e2e : override par WG gagne et ne fuit PAS vers un autre parc ──

    #[Test]
    public function workstation_group_override_wins_for_its_members_and_does_not_leak(): void
    {
        // Capacité réelle : Hidden (toggle on=1 / off=2), défaut on (1). Projection HKCU.
        $cap = Capability::factory()->create([
            'key' => 'show_hidden_files',
            'label' => 'Afficher les fichiers cachés',
            'default_value' => 'on',
        ]);
        CapabilityProjection::factory()->for($cap)->keys([
            ['hive' => 'HKCU', 'path' => 'Software\\Explorer\\Advanced', 'name' => 'Hidden', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 2]],
        ])->create();

        // Item amont PERMISSIF sur la même clé, valeur sentinelle distincte (3) :
        // c'est le plancher, jamais atteint quand un candidat local existe.
        $this->upstreamRegistryItem('HKCU|Software\\Explorer\\Advanced|Hidden|REG_DWORD', '3', ControlHubEnforcementState::Permissive);

        // Override par WORKSTATIONGROUP G (parc logique) : Hidden = off (2).
        $parcG = WorkstationGroup::factory()->logical()->create();
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $parcG->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Poste de G (porte l'override) vs poste de H (autre parc, sans override).
        $wsG = Workstation::factory()->create();
        $wsG->groups()->attach($parcG->id);
        $wsH = Workstation::factory()->create();
        $parcH = WorkstationGroup::factory()->logical()->create();
        $wsH->groups()->attach($parcH->id);

        $valueFor = function (Workstation $ws): int {
            $providers = [
                new RegistryMachineCapabilityProvider(),
                new RegistryUserCapabilityProvider(),
            ];
            $state = $this->compileDecoratedFull($providers, [new RegistryUpstreamAdapter()], TargetContext::for($ws, null));
            $registry = array_values(array_filter(
                $state[StateContract::SCOPE_SESSION],
                static fn (array $i): bool => $i['type'] === 'registry',
            ));
            self::assertCount(1, $registry, 'une seule clé Hidden résolue');

            return $registry[0]['payload']['value'];
        };

        // Poste de G : l'override (off=2) gagne sur le plancher permissif (3) ET le défaut (1).
        self::assertSame(2, $valueFor($wsG), 'l\'override du parc G mord réellement au compilé (FR4)');

        // Poste de H : l'override de G NE FUIT PAS (≠ 2). Le défaut diffusé (1) gagne
        // sur le plancher permissif (3) — confirme le scope par groupe (AC #5) et la
        // règle « permissif sous le défaut diffusé ».
        self::assertSame(1, $valueFor($wsH), 'l\'override de G ne fuit pas vers H ; H retombe sur le défaut diffusé');
    }

    // ── AC #6 — standalone : aucun candidat amont, court-circuit ≤ 1 requête ──

    #[Test]
    public function standalone_no_contract_injects_nothing_and_short_circuits(): void
    {
        // Aucun contrat actif (RefreshDatabase) : le compilé décoré doit être
        // STRICTEMENT identique au compilé non décoré + court-circuit NFR3.
        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(StateMaille::LogicalGroup, $this->regPayload('HKCU', 'P', 'Foo', 7), now(), 1),
        ]);
        $ctx = $this->machineOnlyContext();

        $source = new UpstreamContractSource([new RegistryUpstreamAdapter()]);
        $decorated = UpstreamAwareProvider::wrap($local, $source);

        $bare = (new StateCompiler($this->hasher, [$local], new AgentTtlResolver()))->compile($ctx);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $deco = (new StateCompiler($this->hasher, [$decorated], new AgentTtlResolver()))->compile($ctx);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Byte-identité sur TOUTES les portées + hash global (patron 28.3) : une
        // régression cantonnée à SCOPE_MACHINE/SCOPE_MACHINE_USER passerait sous le
        // radar d'une assertion sur la seule session.
        foreach (StateContract::scopes() as $scope) {
            self::assertSame($bare[$scope], $deco[$scope], "portée {$scope} : décoré == non décoré sans contrat (NFR3)");
        }
        self::assertSame(
            $this->hasher->hashState($bare),
            $this->hasher->hashState($deco),
            'hashState identique sans contrat (NFR3)',
        );
        self::assertSame(7, $deco[StateContract::SCOPE_SESSION][0]['payload']['value'], 'le local survit (aucun plancher permissif injecté)');

        $contractQueries = $this->countQueries($log, '"controlhub_contracts"');
        $itemQueries = $this->countQueries($log, '"controlhub_contract_items"');
        self::assertSame(1, $contractQueries, 'au plus 1 requête « contrat actif ? »');
        self::assertSame(0, $itemQueries, 'aucune requête items quand aucun contrat actif (court-circuit)');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function upstreamRegistryItem(string $key, string $value, ControlHubEnforcementState $state): void
    {
        $contract = ControlHubContract::factory()->create(); // link_state = active
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => $key,
            'value' => $value,
            'enforcement_state' => $state,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);
    }

    /**
     * @param  list<StateProvider>  $providers
     * @return array<string,mixed>
     */
    private function compileDecorated(array $providers): array
    {
        return $this->compileDecoratedFull($providers, [new RegistryUpstreamAdapter()], $this->machineOnlyContext());
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

        return (new StateCompiler($this->hasher, $decorated, new AgentTtlResolver()))->compile($ctx);
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
