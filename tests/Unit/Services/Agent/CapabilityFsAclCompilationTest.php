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
use App\Services\Agent\Providers\FsAclCapabilityProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.1 — compilation BOUT-EN-BOUT capacité `fs_acl` → items de contrat via
 * le `StateCompiler` INCHANGÉ (D2). Prouve : (a) précédence broadcast/parc sur
 * identité ÉGALE (dans les DEUX sens) ; (b) deux ACE d'identités distinctes
 * (mêmes `path`, trustees différents) COEXISTENT (piège #2) ; (c) deux capacités
 * sur le même chemin coexistent ; (d) override UserGroup sans effet en compile
 * machine-only (piège #10). `exclusiveKey() = {path|trustee|ace_type}`.
 */
class CapabilityFsAclCompilationTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $ws;

    private WorkstationGroup $logical;

    private WorkstationGroup $physical;

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
        $this->physical = WorkstationGroup::factory()->create(['is_physical' => true]);
        $this->ws->groups()->attach($this->logical->id);
        $this->ws->groups()->attach($this->physical->id);

        // Groupe conventionnel pour la résolution des jetons @eleves.
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
     * @param  list<array<string,mixed>>  $aces
     */
    private function makeCapability(string $key, string $default, array $aces): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'default_value' => $default]);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_FS_ACL,
            'spec' => ['aces' => $aces],
        ]);

        return $cap;
    }

    private function compiler(): StateCompiler
    {
        return new StateCompiler(new StateHasher(), [new FsAclCapabilityProvider()]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function machineFsAcl(TargetContext $ctx): array
    {
        $state = $this->compiler()->compile($ctx);

        return array_values(array_filter(
            $state[StateContract::SCOPE_MACHINE],
            fn ($i): bool => $i['type'] === 'fs_acl',
        ));
    }

    /** Spec à UNE ACE PF, trustee @eleves, ensure piloté par la valeur. */
    private const ELEVES_ACE = [[
        'path' => 'C:\\Program Files',
        'ace_type' => 'deny',
        'rights' => 'list_folder',
        'applies_to' => 'folder_only',
        'trustee' => ['eleves' => '@eleves', 'off' => '@eleves'],
        'ensure' => ['eleves' => 'present', 'off' => 'absent'],
    ]];

    // ── (a) Précédence sur identité ÉGALE — deux sens ─────────────────────

    #[Test]
    public function parc_present_beats_broadcast_absent_on_equal_identity(): void
    {
        // Broadcast `off` → item ABSENT (trustee Eleves) ; override parc `eleves`
        // → item PRESENT (MÊME identité path|eleves|deny). Le parc gagne.
        $cap = $this->makeCapability('pf_denied', 'off', self::ELEVES_ACE);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->logical->id,
            'value' => 'eleves',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = $this->machineFsAcl(TargetContext::for($this->ws, null));
        self::assertCount(1, $items, 'une seule valeur gagne pour l\'identité path|trustee|ace_type');
        self::assertSame('present', $items[0]['payload']['ensure'], 'override parc (present) bat broadcast (absent)');
        self::assertSame('Eleves', $items[0]['payload']['trustee']);
    }

    #[Test]
    public function parc_absent_beats_broadcast_present_on_equal_identity(): void
    {
        // Miroir : broadcast `eleves` → PRESENT ; override parc `off` → ABSENT.
        $cap = $this->makeCapability('pf_denied', 'eleves', self::ELEVES_ACE);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->logical->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = $this->machineFsAcl(TargetContext::for($this->ws, null));
        self::assertCount(1, $items);
        self::assertSame('absent', $items[0]['payload']['ensure'], 'override parc (absent) bat broadcast (present)');
    }

    // ── (b) Identités distinctes (trustees différents) COEXISTENT ─────────

    #[Test]
    public function two_aces_with_distinct_trustees_coexist(): void
    {
        // Même path, deux trustees littéraux distincts → deux identités distinctes
        // (piège #2 : cumul, pas remplacement).
        $this->makeCapability('pf_two', 'on', [
            ['path' => 'C:\\Program Files', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => 'Domain Users', 'ensure' => 'present'],
            ['path' => 'C:\\Program Files', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => 'Profs', 'ensure' => 'present'],
        ]);

        $items = $this->machineFsAcl(TargetContext::for($this->ws, null));
        self::assertCount(2, $items, 'deux ACE d\'identités distinctes coexistent');
        $trustees = array_map(fn ($i): string => $i['payload']['trustee'], $items);
        sort($trustees);
        self::assertSame(['Domain Users', 'Profs'], $trustees);
    }

    // ── (c) Deux capacités sur le même chemin coexistent ──────────────────

    #[Test]
    public function two_capabilities_on_the_same_path_coexist(): void
    {
        $this->makeCapability('cap_a', 'on', [
            ['path' => 'C:\\Program Files', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => 'Domain Users', 'ensure' => 'present'],
        ]);
        $this->makeCapability('cap_b', 'on', [
            ['path' => 'C:\\Program Files', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => 'Profs', 'ensure' => 'present'],
        ]);

        $items = $this->machineFsAcl(TargetContext::for($this->ws, null));
        self::assertCount(2, $items, 'deux capacités (trustees distincts) sur le même chemin coexistent');
    }

    // ── (d) Compile MACHINE-ONLY : override UserGroup sans effet ──────────

    #[Test]
    public function user_group_override_has_no_effect_on_machine_only_compile(): void
    {
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $cap = $this->makeCapability('pf_denied', 'off', self::ELEVES_ACE);
        // Override UserGroup vers `eleves` (present) — SANS EFFET en machine-only.
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => 'eleves',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Contexte du service SYSTEM : user null → userGroupIds = [].
        $items = $this->machineFsAcl(TargetContext::for($this->ws, null));
        self::assertCount(1, $items);
        self::assertSame(
            'absent',
            $items[0]['payload']['ensure'],
            'le défaut broadcast `off` (absent) subsiste : l\'override UserGroup n\'atteint pas l\'item fs_acl',
        );
    }
}
