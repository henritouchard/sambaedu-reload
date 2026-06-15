<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateMode;
use App\Enums\StateScope;
use App\Models\OverlaySignal;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\Providers\OverlayStateProvider;
use App\Services\Agent\Providers\WallpaperStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `StateCompiler` — Story 23.4 (AC1, AC2, AC3, AC5, AC6).
 *
 * D2 pur via providers **factices** : le compilateur se teste sans les tables
 * wallpaper/overlay. Le test d'intégration final assemble les providers réels
 * sur un contexte réaliste (assertions structurelles iso `ContractV1Test`,
 * SANS comparer aux golden payloads illustratifs).
 */
class StateCompilerTest extends TestCase
{
    use RefreshDatabase;

    private StateHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new StateHasher();
    }

    // ── Enveloppe v1 (AC5) ───────────────────────────────────────────────

    #[Test]
    public function envelope_has_schema_ttl_default_and_three_scopes_even_without_any_provider(): void
    {
        $state = $this->compiler([])->compile($this->machineOnlyContext());

        self::assertSame(StateContract::SCHEMA, $state['schema']);
        self::assertSame(3600, $state['ttl_seconds']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $state['generated_at'],
        );
        foreach (StateContract::scopes() as $scope) {
            self::assertSame([], $state[$scope], "portée {$scope} : doit être présente et vide");
        }
    }

    #[Test]
    public function envelope_carries_workstation_debug_flag(): void
    {
        $off = TargetContext::for(Workstation::factory()->create(['debug' => false]), null);
        $on = TargetContext::for(Workstation::factory()->create(['debug' => true]), null);

        $stateOff = $this->compiler([])->compile($off);
        $stateOn = $this->compiler([])->compile($on);

        self::assertArrayHasKey('debug', $stateOff);
        self::assertFalse($stateOff['debug']);
        self::assertTrue($stateOn['debug']);

        // Le drapeau entre dans le hash (donc l'ETag) : un toggle franchit le 304.
        self::assertNotSame(
            $this->hasher->hashState($stateOff),
            $this->hasher->hashState($stateOn),
            'le toggle debug doit changer le hash d\'état (sinon le cache 304 le masque)',
        );
    }

    #[Test]
    public function provider_without_rules_yields_absent_type_not_empty_item(): void
    {
        $provider = $this->fakeProvider('ghost', ResourceSemantics::Exclusive, StateScope::Session, []);

        $state = $this->compiler([$provider])->compile($this->machineOnlyContext());

        self::assertSame([], $state[StateContract::SCOPE_SESSION]);
    }

    #[Test]
    public function items_have_exactly_the_five_contract_keys_and_state_hasher_hash(): void
    {
        $provider = $this->fakeProvider('demo', ResourceSemantics::Exclusive, StateScope::Machine, [
            new StateCandidate(StateMaille::Broadcast, ['value' => 'x'], now(), 1),
        ]);

        $state = $this->compiler([$provider])->compile($this->machineOnlyContext());

        $item = $state[StateContract::SCOPE_MACHINE][0];
        self::assertSame(['type', 'semantics', 'mode', 'payload', 'hash'], array_keys($item));
        self::assertSame('demo', $item['type']);
        self::assertSame('exclusive', $item['semantics']);
        self::assertSame('strict', $item['mode']);
        self::assertSame(['value' => 'x'], $item['payload']);
        self::assertSame($this->hasher->hashItem($item), $item['hash']);
    }

    // ── AC1 — registry : un type = zéro modification du compilateur ──────

    #[Test]
    public function a_new_fake_provider_is_served_without_any_compiler_change(): void
    {
        $fake = $this->fakeProvider('brand_new_type', ResourceSemantics::Aggregate, StateScope::MachineUser, [
            new StateCandidate(StateMaille::Broadcast, ['k' => 'v'], now(), 7),
        ]);

        $state = $this->compiler([$fake])->compile($this->machineOnlyContext());

        self::assertCount(1, $state[StateContract::SCOPE_MACHINE_USER]);
        self::assertSame('brand_new_type', $state[StateContract::SCOPE_MACHINE_USER][0]['type']);
    }

    #[Test]
    public function compiler_resolves_from_container_with_registered_registry(): void
    {
        $compiler = $this->app->make(StateCompiler::class);

        self::assertInstanceOf(StateCompiler::class, $compiler);
        self::assertSame($compiler, $this->app->make(StateCompiler::class), 'singleton attendu');
    }

    #[Test]
    public function output_order_is_independent_of_provider_registration_order(): void
    {
        $a = $this->fakeProvider('aaa', ResourceSemantics::Exclusive, StateScope::Session, [
            new StateCandidate(StateMaille::Broadcast, ['p' => 1], now(), 1),
        ]);
        $b = $this->fakeProvider('bbb', ResourceSemantics::Exclusive, StateScope::Session, [
            new StateCandidate(StateMaille::Broadcast, ['p' => 2], now(), 2),
        ]);
        $ctx = $this->machineOnlyContext();

        $stateAb = $this->compiler([$a, $b])->compile($ctx);
        $stateBa = $this->compiler([$b, $a])->compile($ctx);

        self::assertSame(
            ['aaa', 'bbb'],
            array_column($stateAb[StateContract::SCOPE_SESSION], 'type'),
        );
        self::assertSame(
            $this->hasher->hashState($stateAb),
            $this->hasher->hashState($stateBa),
        );
    }

    // ── AC2 — union aggregate + spécificité exclusive ─────────────────────

    #[Test]
    public function aggregate_unions_all_mailles_ordered_by_source_id(): void
    {
        $provider = $this->fakeProvider('agg', ResourceSemantics::Aggregate, StateScope::Session, [
            new StateCandidate(StateMaille::User, ['n' => 'c'], now(), 30),
            new StateCandidate(StateMaille::Broadcast, ['n' => 'a'], now(), 10),
            new StateCandidate(StateMaille::PhysicalGroup, ['n' => 'b'], now(), 20),
        ]);

        $state = $this->compiler([$provider])->compile($this->machineOnlyContext());

        self::assertSame(
            [['n' => 'a'], ['n' => 'b'], ['n' => 'c']],
            array_column($state[StateContract::SCOPE_SESSION], 'payload'),
            'union de toutes les mailles, ordre stable sourceId asc',
        );
    }

    #[Test]
    public function exclusive_specificity_full_chain_each_maille_beats_the_less_specific(): void
    {
        // Décision n° 1 : user > groupes user > poste > WG physique > WG logique > broadcast.
        $chain = [
            [StateMaille::Broadcast, 'broadcast'],
            [StateMaille::LogicalGroup, 'logical'],
            [StateMaille::PhysicalGroup, 'physical'],
            [StateMaille::Workstation, 'poste'],
            [StateMaille::UserGroup, 'user_group'],
            [StateMaille::User, 'user'],
        ];
        $ctx = $this->machineOnlyContext();

        $candidates = [];
        $id = 0;
        foreach ($chain as [$maille, $expectedWinner]) {
            $candidates[] = new StateCandidate($maille, ['winner' => $expectedWinner], now(), ++$id);
            $provider = $this->fakeProvider('excl', ResourceSemantics::Exclusive, StateScope::Session, $candidates);

            $state = $this->compiler([$provider])->compile($ctx);

            self::assertCount(1, $state[StateContract::SCOPE_SESSION]);
            self::assertSame(
                ['winner' => $expectedWinner],
                $state[StateContract::SCOPE_SESSION][0]['payload'],
                "la maille {$maille->value} doit battre toutes les moins spécifiques",
            );
        }
    }

    // ── AC3 — conflit intra-maille ────────────────────────────────────────

    #[Test]
    public function exclusive_conflict_same_maille_most_recent_wins_and_warning_is_logged(): void
    {
        $warnings = $this->captureAgentWarnings();
        $provider = $this->fakeProvider('wp', ResourceSemantics::Exclusive, StateScope::Session, [
            new StateCandidate(StateMaille::PhysicalGroup, ['v' => 'old'], Carbon::parse('2026-06-01 10:00:00'), 1),
            new StateCandidate(StateMaille::PhysicalGroup, ['v' => 'new'], Carbon::parse('2026-06-10 10:00:00'), 2),
        ]);
        $ctx = $this->machineOnlyContext();

        $state = $this->compiler([$provider])->compile($ctx);

        self::assertSame(['v' => 'new'], $state[StateContract::SCOPE_SESSION][0]['payload']);
        self::assertCount(1, $warnings);
        [$message, $context] = $warnings[0];
        self::assertStringContainsString('agent.state.conflict', $message);
        self::assertSame('agent.state.conflict', $context['action_type']);
        self::assertSame($ctx->workstation->id, $context['workstation_id']);
        self::assertSame('wp', $context['type']);
        self::assertSame(StateMaille::PhysicalGroup->value, $context['maille']);
        self::assertEqualsCanonicalizing([1, 2], $context['rule_ids']);
    }

    #[Test]
    public function exclusive_conflict_tiebreak_on_equal_updated_at_highest_id_wins(): void
    {
        $this->captureAgentWarnings();
        $sameInstant = Carbon::parse('2026-06-10 10:00:00');
        $provider = $this->fakeProvider('wp', ResourceSemantics::Exclusive, StateScope::Session, [
            new StateCandidate(StateMaille::Broadcast, ['v' => 'id-9'], $sameInstant, 9),
            new StateCandidate(StateMaille::Broadcast, ['v' => 'id-5'], $sameInstant, 5),
        ]);

        $state = $this->compiler([$provider])->compile($this->machineOnlyContext());

        self::assertSame(['v' => 'id-9'], $state[StateContract::SCOPE_SESSION][0]['payload']);
    }

    #[Test]
    public function exclusive_conflict_sub_second_recency_beats_id_tiebreak(): void
    {
        // Deux règles modifiées dans la MÊME seconde : la récence se compare
        // en microsecondes — le tiebreak id ne doit PAS faire gagner la plus
        // ancienne sous prétexte que getTimestamp() tronque (review 23.4).
        $this->captureAgentWarnings();
        $provider = $this->fakeProvider('wp', ResourceSemantics::Exclusive, StateScope::Session, [
            new StateCandidate(StateMaille::Broadcast, ['v' => 'older-high-id'], Carbon::parse('2026-06-10 10:00:00.100000'), 9),
            new StateCandidate(StateMaille::Broadcast, ['v' => 'newer-low-id'], Carbon::parse('2026-06-10 10:00:00.900000'), 3),
        ]);

        $state = $this->compiler([$provider])->compile($this->machineOnlyContext());

        self::assertSame(['v' => 'newer-low-id'], $state[StateContract::SCOPE_SESSION][0]['payload']);
    }

    #[Test]
    public function conflict_in_a_beaten_maille_does_not_warn(): void
    {
        $warnings = $this->captureAgentWarnings();
        $provider = $this->fakeProvider('wp', ResourceSemantics::Exclusive, StateScope::Session, [
            new StateCandidate(StateMaille::Broadcast, ['v' => 'b1'], now(), 1),
            new StateCandidate(StateMaille::Broadcast, ['v' => 'b2'], now(), 2),
            new StateCandidate(StateMaille::User, ['v' => 'user'], now(), 3),
        ]);

        $state = $this->compiler([$provider])->compile($this->machineOnlyContext());

        self::assertSame(['v' => 'user'], $state[StateContract::SCOPE_SESSION][0]['payload']);
        self::assertCount(0, $warnings, 'un conflit dans une maille battue n\'arbitre rien');
    }

    // ── Story 27.1 — dédup aggregate par contenu (décision n° 4) ──────────

    #[Test]
    public function aggregate_dedups_identical_payloads_from_different_mailles(): void
    {
        // Même raccourci assigné au parc ET au poste → un SEUL item en sortie
        // (sinon `.lnk` en double + hash dépendant du nombre de mailles).
        $provider = $this->fakeProvider('shortcuts', ResourceSemantics::Aggregate, StateScope::MachineUser, [
            new StateCandidate(StateMaille::LogicalGroup, ['name' => 'Intranet', 'place' => 'desktop'], now(), 5),
            new StateCandidate(StateMaille::Workstation, ['name' => 'Intranet', 'place' => 'desktop'], now(), 9),
            new StateCandidate(StateMaille::User, ['name' => 'Notes', 'place' => 'desktop'], now(), 2),
        ]);

        $items = $this->compiler([$provider])->compile($this->machineOnlyContext())[StateContract::SCOPE_MACHINE_USER];

        self::assertCount(2, $items, 'le doublon de contenu est fusionné, l\'item distinct reste');
        // Ordre stable par sourceId asc : Notes (2) avant Intranet (5).
        self::assertSame(
            [['name' => 'Notes', 'place' => 'desktop'], ['name' => 'Intranet', 'place' => 'desktop']],
            array_column($items, 'payload'),
        );
    }

    #[Test]
    public function aggregate_dedup_does_not_affect_distinct_overlay_payloads(): void
    {
        // Non-régression overlay : payloads distincts → aucun élément fusionné.
        $provider = $this->fakeProvider('overlay', ResourceSemantics::Aggregate, StateScope::Session, [
            new StateCandidate(StateMaille::Broadcast, ['kind' => 'notice', 'text' => 'a'], now(), 1),
            new StateCandidate(StateMaille::User, ['kind' => 'notice', 'text' => 'b'], now(), 2),
        ]);

        $items = $this->compiler([$provider])->compile($this->machineOnlyContext())[StateContract::SCOPE_SESSION];

        self::assertCount(2, $items);
    }

    // ── Story 27.1 — mode agrégé par type (décision n° 2) ─────────────────

    #[Test]
    public function mode_aggregates_to_default_only_when_all_selected_candidates_are_default(): void
    {
        $provider = $this->fakeProvider('shortcuts', ResourceSemantics::Aggregate, StateScope::MachineUser, [
            new StateCandidate(StateMaille::User, ['name' => 'A'], now(), 1, StateMode::Default),
            new StateCandidate(StateMaille::User, ['name' => 'B'], now(), 2, StateMode::Default),
        ]);

        $items = $this->compiler([$provider])->compile($this->machineOnlyContext())[StateContract::SCOPE_MACHINE_USER];

        self::assertSame(['default', 'default'], array_column($items, 'mode'), 'tous default → default');
    }

    #[Test]
    public function mode_aggregates_to_strict_when_any_selected_candidate_is_strict(): void
    {
        $provider = $this->fakeProvider('shortcuts', ResourceSemantics::Aggregate, StateScope::MachineUser, [
            new StateCandidate(StateMaille::User, ['name' => 'A'], now(), 1, StateMode::Default),
            new StateCandidate(StateMaille::User, ['name' => 'B'], now(), 2, StateMode::Strict),
        ]);

        $items = $this->compiler([$provider])->compile($this->machineOnlyContext())[StateContract::SCOPE_MACHINE_USER];

        // Posture sûre : un seul strict suffit → tout le type est strict.
        self::assertSame(['strict', 'strict'], array_column($items, 'mode'));
    }

    #[Test]
    public function candidate_without_mode_falls_back_to_provider_default(): void
    {
        // Aucun candidat ne déclare de mode → on retombe sur mode() du provider
        // (comportement 23.4 préservé).
        $defaultProvider = $this->fakeProvider('a', ResourceSemantics::Aggregate, StateScope::Session, [
            new StateCandidate(StateMaille::Broadcast, ['k' => 1], now(), 1),
        ], StateMode::Default);
        $strictProvider = $this->fakeProvider('b', ResourceSemantics::Aggregate, StateScope::Session, [
            new StateCandidate(StateMaille::Broadcast, ['k' => 2], now(), 1),
        ], StateMode::Strict);

        $state = $this->compiler([$defaultProvider, $strictProvider])->compile($this->machineOnlyContext());
        $byType = collect($state[StateContract::SCOPE_SESSION])->keyBy('type');

        self::assertSame('default', $byType['a']['mode']);
        self::assertSame('strict', $byType['b']['mode']);
    }

    #[Test]
    public function exclusive_mode_comes_from_the_winning_candidate(): void
    {
        // L'exclusif tranche la maille gagnante : son mode fait foi.
        $provider = $this->fakeProvider('wallpaper', ResourceSemantics::Exclusive, StateScope::Session, [
            new StateCandidate(StateMaille::Broadcast, ['asset' => 'b'], now(), 1, StateMode::Strict),
            new StateCandidate(StateMaille::User, ['asset' => 'u'], now(), 2, StateMode::Default),
        ]);

        $items = $this->compiler([$provider])->compile($this->machineOnlyContext())[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items);
        self::assertSame('u', $items[0]['payload']['asset'], 'la maille user gagne');
        self::assertSame('default', $items[0]['mode'], 'le mode du candidat gagnant fait foi');
    }

    // ── AC5 — déterminisme (protège l'ETag de 23.5) ───────────────────────

    #[Test]
    public function two_compilations_at_different_instants_yield_same_state_hash(): void
    {
        $provider = $this->fakeProvider('demo', ResourceSemantics::Aggregate, StateScope::Machine, [
            new StateCandidate(StateMaille::Broadcast, ['k' => 'stable'], now(), 1),
        ]);
        $compiler = $this->compiler([$provider]);
        $ctx = $this->machineOnlyContext();

        $first = $compiler->compile($ctx);
        $this->travel(3)->hours();
        $second = $compiler->compile($ctx);

        self::assertNotSame($first['generated_at'], $second['generated_at']);
        self::assertSame(
            $this->hasher->hashState($first),
            $this->hasher->hashState($second),
            'seul generated_at est volatil : même état → même hash (ETag 23.5)',
        );
    }

    // ── AC2 — TargetContext : résolution des appartenances ────────────────

    #[Test]
    public function target_context_resolves_memberships_from_postgres_relations(): void
    {
        $ws = Workstation::factory()->create();
        $room = WorkstationGroup::factory()->create();
        $parc = WorkstationGroup::factory()->logical()->create();
        $ws->groups()->attach([$room->id, $parc->id]);
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $ctx = TargetContext::for($ws, $user);

        self::assertSame([$room->id], $ctx->physicalGroupIds);
        self::assertSame([$parc->id], $ctx->logicalGroupIds);
        self::assertSame([$group->id], $ctx->userGroupIds);
        self::assertEqualsCanonicalizing([$room->id, $parc->id], $ctx->workstationGroupIds());
    }

    #[Test]
    public function target_context_accepts_null_user_with_empty_user_mailles(): void
    {
        $ws = Workstation::factory()->create();

        $ctx = TargetContext::for($ws, null);

        self::assertNull($ctx->user);
        self::assertSame([], $ctx->userGroupIds);
    }

    // ── Intégration — providers réels + factice sur contexte réaliste ─────

    #[Test]
    public function full_compile_with_real_providers_and_a_fake_one_respects_contract_invariants(): void
    {
        $ws = Workstation::factory()->create();
        $room = WorkstationGroup::factory()->create();
        $parc = WorkstationGroup::factory()->logical()->create();
        $ws->groups()->attach([$room->id, $parc->id]);
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        // Wallpapers : broadcast + salle + user → la maille user gagne (exclusif).
        Wallpaper::factory()->default()->create();
        Wallpaper::factory()->create(['owner_type' => WorkstationGroup::class, 'owner_id' => $room->id]);
        $userAsset = WallpaperAsset::factory()->create();
        Wallpaper::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'asset_id' => $userAsset->id,
        ]);

        // Overlay : broadcast + salle + user + expiré (exclu) → union de 3.
        OverlaySignal::create(['kind' => 'info', 'severity' => 'info', 'title' => 'b', 'text' => 'b']);
        OverlaySignal::create([
            'kind' => 'info', 'severity' => 'info', 'title' => 'g', 'text' => 'g',
            'workstation_group_id' => $room->id,
        ]);
        OverlaySignal::create([
            'kind' => 'alert', 'severity' => 'warning', 'title' => 'u', 'text' => 'u',
            'user_login' => $user->login,
        ]);
        OverlaySignal::create([
            'kind' => 'info', 'severity' => 'info', 'title' => 'dead', 'text' => 'dead',
            'expires_at' => now()->subMinute(),
        ]);

        $fake = $this->fakeProvider('fake_type', ResourceSemantics::Aggregate, StateScope::Machine, [
            new StateCandidate(StateMaille::Broadcast, ['flag' => true], now(), 1),
        ]);
        $compiler = $this->compiler([
            $this->app->make(WallpaperStateProvider::class),
            $this->app->make(OverlayStateProvider::class),
            $fake,
        ]);

        $state = $compiler->compile(TargetContext::for($ws, $user));

        // Invariants structurels du contrat (iso ContractV1Test, sans golden).
        self::assertSame(StateContract::SCHEMA, $state['schema']);
        self::assertIsInt($state['ttl_seconds']);
        foreach (StateContract::scopes() as $scope) {
            self::assertTrue(array_is_list($state[$scope]));
            foreach ($state[$scope] as $item) {
                self::assertSame(['type', 'semantics', 'mode', 'payload', 'hash'], array_keys($item));
                self::assertNotNull(ResourceSemantics::tryFrom($item['semantics']));
                self::assertNotNull(StateMode::tryFrom($item['mode']));
                self::assertSame($this->hasher->hashItem($item), $item['hash']);
            }
        }

        // Le provider factice est servi (AC1) — en plus des deux réels.
        self::assertSame('fake_type', $state[StateContract::SCOPE_MACHINE][0]['type']);

        // Session : 4 overlays (identity 24.4 + union de 3 signaux, expiré
        // exclu) + 1 wallpaper (user gagne).
        $types = array_count_values(array_column($state[StateContract::SCOPE_SESSION], 'type'));
        self::assertSame(4, $types['overlay']);
        self::assertSame(1, $types['wallpaper']);
        $wallpaper = collect($state[StateContract::SCOPE_SESSION])->firstWhere('type', 'wallpaper');
        self::assertSame(
            ['asset' => $userAsset->filename, 'checksum' => $userAsset->checksum],
            $wallpaper['payload'],
        );
        // L'identity (sourceId 0) sort en TÊTE de l'union overlay (24.4).
        self::assertSame(
            'identity',
            collect($state[StateContract::SCOPE_SESSION])->firstWhere('type', 'overlay')['payload']['kind'],
        );
        // Ordre déterministe : types asc dans la portée (overlay < wallpaper).
        self::assertSame(
            ['overlay', 'overlay', 'overlay', 'overlay', 'wallpaper'],
            array_column($state[StateContract::SCOPE_SESSION], 'type'),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param  list<StateProvider>  $providers
     */
    private function compiler(array $providers): StateCompiler
    {
        return new StateCompiler($this->hasher, $providers);
    }

    private function machineOnlyContext(): TargetContext
    {
        return TargetContext::for(Workstation::factory()->create(), null);
    }

    /**
     * @param  list<StateCandidate>  $candidates
     */
    private function fakeProvider(
        string $type,
        ResourceSemantics $semantics,
        StateScope $scope,
        array $candidates,
        StateMode $mode = StateMode::Strict,
    ): StateProvider {
        return new class($type, $semantics, $scope, $candidates, $mode) implements StateProvider
        {
            /** @param list<StateCandidate> $candidates */
            public function __construct(
                private readonly string $type,
                private readonly ResourceSemantics $semantics,
                private readonly StateScope $scope,
                private readonly array $candidates,
                private readonly StateMode $mode,
            ) {}

            public function type(): string
            {
                return $this->type;
            }

            public function semantics(): ResourceSemantics
            {
                return $this->semantics;
            }

            public function mode(): StateMode
            {
                return $this->mode;
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
     * Mocke le facade Log (channel `agent`) et capture les warnings émis.
     * `ArrayObject` (référence partagée avec la closure) : un array PHP
     * retourné par valeur ne verrait pas les appends postérieurs à l'appel.
     *
     * @return \ArrayObject<int, array{0:string,1:array<string,mixed>}>
     */
    private function captureAgentWarnings(): \ArrayObject
    {
        $warnings = new \ArrayObject();
        Log::shouldReceive('channel')->with('agent')->andReturnSelf();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('warning')->andReturnUsing(
            function (string $message, array $context = []) use ($warnings): void {
                $warnings->append([$message, $context]);
            },
        );

        return $warnings;
    }
}
