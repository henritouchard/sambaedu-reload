<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Enums\SambaRole;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Story 34.2 (Q5) — Policy dédiée `NetworkSharePolicy` + permissions
 * `networkshare.view` / `networkshare.manage` accordées à refnum + admins
 * partages/users (+ superadmin auto), refusées aux autres rôles.
 */
class NetworkSharePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new PermissionSeeder())->run();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function userWithRole(SambaRole $role): User
    {
        $user = User::create([
            'login' => 'pol-' . $role->value . '-' . uniqid(),
            'role' => 'autre',
            'is_active' => true,
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    #[Test]
    public function roles_allowed_have_view_and_manage(): void
    {
        foreach ([SambaRole::ReferentNumerique, SambaRole::ShareAdmin, SambaRole::UserAdmin, SambaRole::SuperAdmin] as $role) {
            $user = $this->userWithRole($role);
            self::assertTrue(Gate::forUser($user)->allows('viewAny-networkshare'), "{$role->value} viewAny");
            self::assertTrue(Gate::forUser($user)->allows('view-networkshare'), "{$role->value} view");
            self::assertTrue(Gate::forUser($user)->allows('manage-networkshare'), "{$role->value} manage");
        }
    }

    #[Test]
    public function roles_denied_have_neither(): void
    {
        foreach ([SambaRole::Eleve, SambaRole::Prof, SambaRole::EleveAdmin, SambaRole::Technicien, SambaRole::ComputerAdmin] as $role) {
            $user = $this->userWithRole($role);
            self::assertFalse(Gate::forUser($user)->allows('viewAny-networkshare'), "{$role->value} viewAny");
            self::assertFalse(Gate::forUser($user)->allows('manage-networkshare'), "{$role->value} manage");
        }
    }

    #[Test]
    public function referent_numerique_has_networkshare_but_not_share_manage(): void
    {
        $refnum = $this->userWithRole(SambaRole::ReferentNumerique);
        // Confirme la SÉPARATION : pas de share.* (partages de classe).
        self::assertTrue($refnum->can('networkshare.manage'));
        self::assertFalse($refnum->can('share.manage'));
        self::assertFalse($refnum->can('share.view'));
    }

    #[Test]
    public function guest_is_denied(): void
    {
        self::assertFalse(Gate::allows('viewAny-networkshare'));
        self::assertFalse(Gate::allows('manage-networkshare'));
    }
}
