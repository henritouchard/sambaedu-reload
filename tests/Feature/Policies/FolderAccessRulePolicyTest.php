<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Models\Delegation;
use App\Models\FolderAccessRule;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\FolderAccessRuleService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Story 36.4 (AC5/D6) — policy dédiée `folderrule.*` (refnum + ComputerAdmin +
 * superadmin auto) + délégation SCOPÉE par parc (anti-piège Gate global non
 * scopé, piège #9).
 */
class FolderAccessRulePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        (new PermissionSeeder())->run();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function userWithRole(SambaRole $role): User
    {
        $user = User::create(['login' => 'pol-' . $role->value . '-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        $user->assignRole($role->value);

        return $user;
    }

    #[Test]
    public function allowed_roles_have_view_and_manage(): void
    {
        foreach ([SambaRole::ReferentNumerique, SambaRole::ComputerAdmin, SambaRole::SuperAdmin] as $role) {
            $user = $this->userWithRole($role);
            self::assertTrue(Gate::forUser($user)->allows('viewAny-folderrule'), "{$role->value} viewAny");
            self::assertTrue(Gate::forUser($user)->allows('manage-folderrule'), "{$role->value} manage");
        }
    }

    #[Test]
    public function denied_roles_have_neither(): void
    {
        foreach ([SambaRole::Eleve, SambaRole::Prof, SambaRole::EleveAdmin, SambaRole::Technicien, SambaRole::ShareAdmin, SambaRole::UserAdmin] as $role) {
            $user = $this->userWithRole($role);
            self::assertFalse(Gate::forUser($user)->allows('viewAny-folderrule'), "{$role->value} viewAny");
            self::assertFalse(Gate::forUser($user)->allows('manage-folderrule'), "{$role->value} manage");
        }
    }

    #[Test]
    public function guest_is_denied(): void
    {
        self::assertFalse(Gate::allows('viewAny-folderrule'));
        self::assertFalse(Gate::allows('manage-folderrule'));
    }

    // ── Délégation scopée par parc (piège #9) ─────────────────────────────

    #[Test]
    public function a_delegate_scoped_to_parc_a_cannot_assign_parc_b(): void
    {
        // Utilisateur SANS droit global folderrule.manage, mais avec une
        // délégation POSITIVE sur le parc A seulement.
        $user = User::create(['login' => 'deleg-' . uniqid(), 'role' => 'autre', 'is_active' => true]);
        self::assertFalse($user->can('folderrule.manage'), 'pas de droit global');

        $parcA = WorkstationGroup::create(['name' => 'parc-a', 'is_physical' => true, 'is_active' => true]);
        $parcB = WorkstationGroup::create(['name' => 'parc-b', 'is_physical' => true, 'is_active' => true]);

        $perm = Permission::findByName(SambaPermission::FolderRuleManage->value, 'web');
        Delegation::create([
            'user_id' => $user->id,
            'workstation_group_id' => $parcA->id,
            'permission_id' => $perm->id,
            'is_negative' => false,
        ]);

        $group = UserGroup::factory()->create(['name' => 'Profs', 'ad_dn' => null]);
        $rule = FolderAccessRule::factory()->create(['user_group_id' => $group->id]);

        $service = app(FolderAccessRuleService::class);

        // Parc A : autorisé (délégation positive scopée).
        $service->attachParc($rule, $parcA, $user);
        self::assertContains($parcA->id, $rule->fresh()->assignedWorkstationGroupIds());

        // Parc B : REFUSÉ (hors périmètre — Gate global ne suffirait pas).
        $this->expectException(RuntimeException::class);
        $service->attachParc($rule, $parcB, $user);
    }
}
