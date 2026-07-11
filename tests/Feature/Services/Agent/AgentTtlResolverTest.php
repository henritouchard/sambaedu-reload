<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Agent;

use App\Models\Capability;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\AgentTtlResolver;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 43.3 (AC1, AC2, AC4) — `AgentTtlResolver::ttlSeconds()`.
 *
 * Feature (pas Unit) : le critère « bascule sensible » est SQL
 * (`capability_assignments`) — RefreshDatabase requis, iso patron
 * `AbstractCapabilityStateProvider::resolveOverrides()` dont ce résolveur est
 * le miroir (D3, mailles). Catalogue capacités VIDÉ en setUp (le lot seedé par
 * migration brouillerait les assertions de slug/liste), une capacité de
 * travail `restrict_run` créée par test qui en a besoin.
 */
class AgentTtlResolverTest extends TestCase
{
    use RefreshDatabase;

    private AgentTtlResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        // Catalogue vide : la liste config par défaut (['restrict_run']) ne
        // doit matcher AUCUNE capacité tant qu'on n'en crée pas une nous-même
        // (iso l'assertion de non-régression « 41.2 non livrée »).
        DB::table('capability_assignments')->delete();
        DB::table('capabilities')->delete();

        $this->resolver = new AgentTtlResolver();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    // ── Défaut global (comportement AUJOURD'HUI, AC2 non-régression) ───────

    #[Test]
    public function default_config_and_no_capability_seeded_yields_the_global_ttl_without_any_query(): void
    {
        // Défaut config('agent.ttl_sensitive_capabilities') = ['restrict_run'],
        // mais AUCUNE capacité de ce key en base (catalogue vidé) : la requête
        // `Capability::whereIn('key', …)` renvoie [], early-return AVANT la
        // requête `capability_assignments`.
        $ctx = TargetContext::for(Workstation::factory()->create(), null);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $ttl = $this->resolver->ttlSeconds($ctx);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertSame(3600, $ttl);
        self::assertSame(
            0,
            count(array_filter($queries, fn (array $q): bool => str_contains($q['query'], 'capability_assignments'))),
            'aucune capacité résolue pour les slugs listés ⇒ zéro requête capability_assignments',
        );
    }

    #[Test]
    public function empty_sensitive_capabilities_list_yields_the_global_ttl_without_any_query(): void
    {
        config(['agent.ttl_sensitive_capabilities' => []]);
        Capability::factory()->create(['key' => 'restrict_run']);
        $ctx = TargetContext::for(Workstation::factory()->create(), null);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $ttl = $this->resolver->ttlSeconds($ctx);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertSame(3600, $ttl);
        self::assertCount(0, $queries, 'liste config vide ⇒ zéro requête (early-return AC2)');
    }

    #[Test]
    public function null_ttl_config_key_falls_back_to_3600_not_zero(): void
    {
        // Iso durcissement StateCompiler (ex-ligne 74) : une clé PRÉSENTE mais
        // null (env vide) ne doit pas caster en 0.
        config(['agent.ttl_seconds' => null]);
        $ctx = TargetContext::for(Workstation::factory()->create(), null);

        self::assertSame(3600, $this->resolver->ttlSeconds($ctx));
    }

    // ── Bascule sensible — mailles D3 (miroir resolveOverrides()) ──────────

    #[Test]
    public function sensitive_assignment_on_the_workstation_yields_the_short_ttl(): void
    {
        $cap = Capability::factory()->create(['key' => 'restrict_run']);
        $ws = Workstation::factory()->create();
        $this->assign($cap, Workstation::class, $ws->id, 'on');

        $ttl = $this->resolver->ttlSeconds(TargetContext::for($ws, null));

        self::assertSame((int) config('agent.ttl_sensitive_seconds'), $ttl);
    }

    #[Test]
    public function sensitive_assignment_on_the_direct_physical_room_yields_the_short_ttl(): void
    {
        $cap = Capability::factory()->create(['key' => 'restrict_run']);
        $ws = Workstation::factory()->create();
        $room = WorkstationGroup::factory()->create(['is_physical' => true]);
        $ws->groups()->attach($room->id);
        $this->assign($cap, WorkstationGroup::class, $room->id, 'on');

        $ttl = $this->resolver->ttlSeconds(TargetContext::for($ws, null));

        self::assertSame((int) config('agent.ttl_sensitive_seconds'), $ttl);
    }

    #[Test]
    public function sensitive_assignment_on_a_physical_ancestor_yields_the_short_ttl(): void
    {
        // D3 — chaîne physique ÉTENDUE aux ancêtres : le poste est membre
        // DIRECT de la salle enfant ; l'assignment vit sur le PARENT.
        $cap = Capability::factory()->create(['key' => 'restrict_run']);
        $ws = Workstation::factory()->create();
        $parent = WorkstationGroup::factory()->create(['is_physical' => true]);
        $child = WorkstationGroup::factory()->create(['is_physical' => true, 'parent_id' => $parent->id]);
        $ws->groups()->attach($child->id);
        $this->assign($cap, WorkstationGroup::class, $parent->id, 'on');

        $ttl = $this->resolver->ttlSeconds(TargetContext::for($ws, null));

        self::assertSame((int) config('agent.ttl_sensitive_seconds'), $ttl);
    }

    #[Test]
    public function sensitive_assignment_on_a_direct_logical_group_yields_the_short_ttl(): void
    {
        $cap = Capability::factory()->create(['key' => 'restrict_run']);
        $ws = Workstation::factory()->create();
        $parc = WorkstationGroup::factory()->create(['is_physical' => false]);
        $ws->groups()->attach($parc->id);
        $this->assign($cap, WorkstationGroup::class, $parc->id, 'on');

        $ttl = $this->resolver->ttlSeconds(TargetContext::for($ws, null));

        self::assertSame((int) config('agent.ttl_sensitive_seconds'), $ttl);
    }

    #[Test]
    public function sensitive_assignment_on_the_session_user_yields_the_short_ttl(): void
    {
        $cap = Capability::factory()->create(['key' => 'restrict_run']);
        $ws = Workstation::factory()->create();
        $user = User::factory()->create();
        $this->assign($cap, User::class, $user->id, 'on');

        $ttl = $this->resolver->ttlSeconds(TargetContext::for($ws, $user));

        self::assertSame((int) config('agent.ttl_sensitive_seconds'), $ttl);
    }

    #[Test]
    public function sensitive_assignment_on_a_user_group_yields_the_short_ttl(): void
    {
        $cap = Capability::factory()->create(['key' => 'restrict_run']);
        $ws = Workstation::factory()->create();
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);
        $this->assign($cap, UserGroup::class, $group->id, 'on');

        $ttl = $this->resolver->ttlSeconds(TargetContext::for($ws, $user));

        self::assertSame((int) config('agent.ttl_sensitive_seconds'), $ttl);
    }

    #[Test]
    public function machine_only_context_still_sees_sensitive_assignments_on_the_room(): void
    {
        // D3 (piège n°1 de la story) : l'enveloppe MACHINE (user=null) doit
        // AUSSI porter le TTL court quand l'assignment vit sur la salle — le
        // poste (SYSTEM) est l'autorité, pas seulement le compagnon de session.
        $cap = Capability::factory()->create(['key' => 'restrict_run']);
        $ws = Workstation::factory()->create();
        $room = WorkstationGroup::factory()->create(['is_physical' => true]);
        $ws->groups()->attach($room->id);
        $this->assign($cap, WorkstationGroup::class, $room->id, 'on');

        $ttl = $this->resolver->ttlSeconds(TargetContext::for($ws, null));

        self::assertSame((int) config('agent.ttl_sensitive_seconds'), $ttl);
    }

    // ── D2 — value non-null exigé ───────────────────────────────────────────

    #[Test]
    public function null_value_assignment_is_not_a_switch_yields_the_global_ttl(): void
    {
        $cap = Capability::factory()->create(['key' => 'restrict_run']);
        $ws = Workstation::factory()->create();
        $this->assign($cap, Workstation::class, $ws->id, null);

        $ttl = $this->resolver->ttlSeconds(TargetContext::for($ws, null));

        self::assertSame(3600, $ttl);
    }

    // ── AC2 — slug hors liste / capacité absente ────────────────────────────

    #[Test]
    public function assignment_on_a_capability_key_outside_the_configured_list_yields_the_global_ttl(): void
    {
        $cap = Capability::factory()->create(['key' => 'some_other_capability']);
        $ws = Workstation::factory()->create();
        $this->assign($cap, Workstation::class, $ws->id, 'on');

        // 'some_other_capability' n'est PAS dans agent.ttl_sensitive_capabilities
        // (défaut ['restrict_run']).
        $ttl = $this->resolver->ttlSeconds(TargetContext::for($ws, null));

        self::assertSame(3600, $ttl);
    }

    #[Test]
    public function unrelated_workstation_is_unaffected_by_a_sensitive_assignment_elsewhere(): void
    {
        $cap = Capability::factory()->create(['key' => 'restrict_run']);
        $other = Workstation::factory()->create();
        $this->assign($cap, Workstation::class, $other->id, 'on');

        $ws = Workstation::factory()->create();
        $ttl = $this->resolver->ttlSeconds(TargetContext::for($ws, null));

        self::assertSame(3600, $ttl);
    }

    // ── D4/D5 — plancher serveur + défaut global inchangé ───────────────────

    #[Test]
    public function sensitive_ttl_is_floored_at_60_seconds(): void
    {
        config(['agent.ttl_sensitive_seconds' => 10]);
        $cap = Capability::factory()->create(['key' => 'restrict_run']);
        $ws = Workstation::factory()->create();
        $this->assign($cap, Workstation::class, $ws->id, 'on');

        $ttl = $this->resolver->ttlSeconds(TargetContext::for($ws, null));

        self::assertSame(60, $ttl);
    }

    #[Test]
    public function global_ttl_is_floored_at_1_second(): void
    {
        config(['agent.ttl_seconds' => -5]);
        $ws = Workstation::factory()->create();

        $ttl = $this->resolver->ttlSeconds(TargetContext::for($ws, null));

        self::assertSame(1, $ttl);
    }

    // ── Piège n°5 — déterminisme (aucune horloge/aléa) ──────────────────────

    #[Test]
    public function ttl_is_deterministic_across_repeated_calls_on_the_same_context(): void
    {
        $cap = Capability::factory()->create(['key' => 'restrict_run']);
        $ws = Workstation::factory()->create();
        $this->assign($cap, Workstation::class, $ws->id, 'on');
        $ctx = TargetContext::for($ws, null);

        $first = $this->resolver->ttlSeconds($ctx);
        $second = $this->resolver->ttlSeconds($ctx);

        self::assertSame($first, $second);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function assign(Capability $cap, string $assignableType, int $assignableId, ?string $value): void
    {
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => $assignableType,
            'assignable_id' => $assignableId,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
