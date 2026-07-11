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
use App\Services\Agent\Providers\PrivilegeCapabilityProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 35.6 — compilation BOUT-EN-BOUT capacité `privilege` → items de contrat
 * via le `StateCompiler` INCHANGÉ (D2). Prouve : (a) précédence broadcast/parc
 * sur identité ÉGALE (= même privilège), DANS LES DEUX SENS — la maille
 * gagnante prend la liste `accounts` ENTIÈRE, elle ne s'ajoute PAS (piège #4,
 * NON cumulatif) ; (b) deux privilèges DISTINCTS coexistent ; (c) override
 * UserGroup sans effet en compile machine-only (piège #11).
 * `exclusiveKey() = <privilège>` minuscule (1 segment).
 */
class CapabilityPrivilegeCompilationTest extends TestCase
{
    use RefreshDatabase;

    private const RDP_DENY = 'SeDenyRemoteInteractiveLogonRight';

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

        // Groupe conventionnel pour la résolution du jeton @eleves.
        UserGroup::factory()->create(['name' => 'Eleves', 'type' => 'role']);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $spec
     */
    private function makeCapability(string $key, string $default, array $spec): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'default_value' => $default]);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_PRIVILEGE,
            'spec' => $spec,
        ]);

        return $cap;
    }

    private function compiler(): StateCompiler
    {
        return new StateCompiler(new StateHasher(), [new PrivilegeCapabilityProvider()], new AgentTtlResolver());
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function machinePrivilege(TargetContext $ctx): array
    {
        $state = $this->compiler()->compile($ctx);

        return array_values(array_filter(
            $state[StateContract::SCOPE_MACHINE],
            fn ($i): bool => $i['type'] === 'privilege',
        ));
    }

    /** Spec de la capacité de preuve : map valeur → liste de comptes. */
    private const RDP_SPEC = [
        'privilege' => self::RDP_DENY,
        'accounts' => ['eleves' => ['@eleves'], 'off' => []],
    ];

    // ── (a) Précédence sur identité ÉGALE — deux sens (piège #4) ──────────

    #[Test]
    public function parc_off_beats_broadcast_eleves_on_equal_identity(): void
    {
        // Broadcast `eleves` → item [Eleves] ; override parc `off` → item []
        // (MÊME identité = même privilège). Le parc gagne : le privilège est
        // VIDÉ — la liste gagnante remplace, elle ne s'unionne pas.
        $cap = $this->makeCapability('rdp_denied', 'eleves', self::RDP_SPEC);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->logical->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = $this->machinePrivilege(TargetContext::for($this->ws, null));
        self::assertCount(1, $items, 'une seule liste gagne pour l\'identité <privilège>');
        self::assertSame([], $items[0]['payload']['accounts'], 'override parc (off → []) bat broadcast (eleves) : privilège vidé');
    }

    #[Test]
    public function parc_eleves_beats_broadcast_off_on_equal_identity(): void
    {
        // Miroir : broadcast `off` → [] ; override parc `eleves` → [Eleves].
        $cap = $this->makeCapability('rdp_denied', 'off', self::RDP_SPEC);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->logical->id,
            'value' => 'eleves',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = $this->machinePrivilege(TargetContext::for($this->ws, null));
        self::assertCount(1, $items);
        self::assertSame(['Eleves'], $items[0]['payload']['accounts'], 'override parc (eleves) bat broadcast (off)');
    }

    // ── (b) Privilèges DISTINCTS coexistent (identités distinctes) ────────

    #[Test]
    public function two_distinct_privileges_coexist(): void
    {
        $this->makeCapability('rdp_denied', 'on', [
            'privilege' => self::RDP_DENY,
            'accounts' => ['Eleves'],
        ]);
        $this->makeCapability('batch_denied', 'on', [
            'privilege' => 'SeDenyBatchLogonRight',
            'accounts' => ['Eleves'],
        ]);

        $items = $this->machinePrivilege(TargetContext::for($this->ws, null));
        self::assertCount(2, $items, 'deux privilèges distincts = deux identités = coexistence');
        $privileges = array_map(fn ($i): string => $i['payload']['privilege'], $items);
        sort($privileges);
        self::assertSame(['SeDenyBatchLogonRight', self::RDP_DENY], $privileges);
    }

    // ── (c) Compile MACHINE-ONLY : override UserGroup sans effet ──────────

    #[Test]
    public function user_group_override_has_no_effect_on_machine_only_compile(): void
    {
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $cap = $this->makeCapability('rdp_denied', 'off', self::RDP_SPEC);
        // Override UserGroup vers `eleves` — SANS EFFET en machine-only (piège #11).
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => 'eleves',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Contexte du service SYSTEM : user null → userGroupIds = [].
        $items = $this->machinePrivilege(TargetContext::for($this->ws, null));
        self::assertCount(1, $items);
        self::assertSame(
            [],
            $items[0]['payload']['accounts'],
            'le défaut broadcast `off` ([]) subsiste : l\'override UserGroup n\'atteint pas l\'item privilege',
        );
    }
}
