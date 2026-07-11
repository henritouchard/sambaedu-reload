<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\AgentTtlResolver;
use App\Services\Agent\Providers\FirewallCapabilityProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.2 — compilation BOUT-EN-BOUT capacité `firewall` → items de contrat
 * via le `StateCompiler` INCHANGÉ (D2). Prouve : (a) précédence broadcast/parc
 * sur identité ÉGALE (dans les DEUX sens) ; (b) deux règles de `rule_id`
 * distincts COEXISTENT ; (c) deux capacités émettant le MÊME `rule_id`
 * collisionnent (la plus spécifique gagne, piège #10) ; (d) override UserGroup
 * sans effet en compile machine-only (piège #15). `exclusiveKey() = rule_id`.
 */
class CapabilityFirewallCompilationTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $ws;

    private WorkstationGroup $logical;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();

        $this->ws = Workstation::factory()->create();
        $this->logical = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach($this->logical->id);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    /**
     * @param  list<array<string,mixed>>  $rules
     */
    private function makeCapability(string $key, string $default, array $rules): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'default_value' => $default]);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_FIREWALL,
            'spec' => ['rules' => $rules],
        ]);

        return $cap;
    }

    private function compiler(): StateCompiler
    {
        return new StateCompiler(new StateHasher(), [new FirewallCapabilityProvider()], new AgentTtlResolver());
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function machineFirewall(TargetContext $ctx): array
    {
        $state = $this->compiler()->compile($ctx);

        return array_values(array_filter(
            $state[StateContract::SCOPE_MACHINE],
            fn ($i): bool => $i['type'] === 'firewall',
        ));
    }

    /** Une règle internet-block dont l'ensure est piloté par la valeur. */
    private const INTERNET_RULE = [[
        'rule_id' => 'internet-block',
        'direction' => 'out',
        'action' => 'block',
        'remote_scope' => 'internet',
        'protocol' => 'any',
        'ensure' => ['off' => 'present', 'on' => 'absent'],
    ]];

    private function assign(int $capId, string $type, int $id, string $value): void
    {
        DB::table('capability_assignments')->insert([
            'capability_id' => $capId,
            'assignable_type' => $type,
            'assignable_id' => $id,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── (a) Précédence sur identité ÉGALE — deux sens ─────────────────────

    #[Test]
    public function parc_on_absent_beats_broadcast_off_present(): void
    {
        // Broadcast `off` → present ; override parc `on` → absent (MÊME rule_id).
        $cap = $this->makeCapability('inet', 'off', self::INTERNET_RULE);
        $this->assign($cap->id, WorkstationGroup::class, $this->logical->id, 'on');

        $items = $this->machineFirewall(TargetContext::for($this->ws, null));
        self::assertCount(1, $items, 'une seule valeur gagne pour l\'identité rule_id');
        self::assertSame('absent', $items[0]['payload']['ensure'], 'override parc (on/absent) bat broadcast (off/present) → groupe vidé');
    }

    #[Test]
    public function parc_off_present_beats_broadcast_on_absent(): void
    {
        // Miroir : broadcast `on` → absent ; override parc `off` → present.
        $cap = $this->makeCapability('inet', 'on', self::INTERNET_RULE);
        $this->assign($cap->id, WorkstationGroup::class, $this->logical->id, 'off');

        $items = $this->machineFirewall(TargetContext::for($this->ws, null));
        self::assertCount(1, $items);
        self::assertSame('present', $items[0]['payload']['ensure'], 'override parc (off/present) bat broadcast (on/absent)');
    }

    // ── (b) rule_ids distincts COEXISTENT ─────────────────────────────────

    #[Test]
    public function two_rules_with_distinct_rule_ids_coexist(): void
    {
        $this->makeCapability('multi', 'off', [
            ['rule_id' => 'internet-block', 'direction' => 'out', 'action' => 'block', 'remote_scope' => 'internet', 'protocol' => 'any', 'ensure' => 'present'],
            ['rule_id' => 'block-proxy', 'direction' => 'out', 'action' => 'block', 'remote_scope' => 'explicit', 'protocol' => 'any', 'remote_addresses' => ['203.0.113.9'], 'ensure' => 'present'],
        ]);

        $items = $this->machineFirewall(TargetContext::for($this->ws, null));
        self::assertCount(2, $items, 'deux règles de rule_id distincts s\'accumulent');
        $ids = array_map(fn ($i): string => $i['payload']['rule_id'], $items);
        sort($ids);
        self::assertSame(['block-proxy', 'internet-block'], $ids);
    }

    // ── (c) Collision inter-capacités : même rule_id (piège #10) ──────────

    #[Test]
    public function two_capabilities_with_the_same_rule_id_collide(): void
    {
        // Deux capacités émettant `internet-block` : le compilateur n'en garde
        // qu'UNE (identité rule_id) — comportement documenté (sabotage si accidentel).
        $this->makeCapability('cap_a', 'off', [
            ['rule_id' => 'internet-block', 'direction' => 'out', 'action' => 'block', 'remote_scope' => 'internet', 'protocol' => 'any', 'ensure' => 'present'],
        ]);
        $this->makeCapability('cap_b', 'off', [
            ['rule_id' => 'internet-block', 'direction' => 'out', 'action' => 'block', 'remote_scope' => 'internet', 'protocol' => 'any', 'ensure' => 'present'],
        ]);

        $items = $this->machineFirewall(TargetContext::for($this->ws, null));
        self::assertCount(1, $items, 'un même rule_id inter-capacités collisionne (une seule règle)');
    }

    // ── (d) Compile MACHINE-ONLY : override UserGroup sans effet ──────────

    #[Test]
    public function user_group_override_has_no_effect_on_machine_only_compile(): void
    {
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $cap = $this->makeCapability('inet', 'off', self::INTERNET_RULE);
        // Override UserGroup vers `on` (absent) — SANS EFFET en machine-only.
        $this->assign($cap->id, UserGroup::class, $group->id, 'on');

        $items = $this->machineFirewall(TargetContext::for($this->ws, null));
        self::assertCount(1, $items);
        self::assertSame(
            'present',
            $items[0]['payload']['ensure'],
            'le défaut broadcast `off` (present) subsiste : l\'override UserGroup n\'atteint pas l\'item firewall',
        );
    }
}
