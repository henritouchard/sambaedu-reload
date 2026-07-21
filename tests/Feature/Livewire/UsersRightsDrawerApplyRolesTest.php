<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * `applyRoles()` / `applyPermissions()` du drawer de droits de la page
 * utilisateurs.
 *
 * L'assignation d'un rôle est ADDITIVE : elle laisse intacts les rôles déjà
 * portés par le compte. Le retrait passe par la coche dédiée et ne vise que le
 * rôle sélectionné. Le compte d'administration protégé est écarté de tout
 * retrait, qu'il porte sur un rôle ou sur une permission directe.
 */
class UsersRightsDrawerApplyRolesTest extends TestCase
{
    use CreatesPermissionSchema;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();

        foreach (SambaPermission::cases() as $perm) {
            Permission::firstOrCreate(['name' => $perm->value, 'guard_name' => 'web']);
        }
        foreach (SambaRole::cases() as $sambaRole) {
            Role::firstOrCreate(['name' => $sambaRole->value, 'guard_name' => 'web']);
        }
    }

    protected function tearDown(): void
    {
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeUser(string $login): User
    {
        return User::create([
            'login' => $login,
            'role' => 'autre',
            'is_active' => true,
        ]);
    }

    private function actingAsRightsManager(): User
    {
        $actor = $this->makeUser('rights-manager');
        $actor->givePermissionTo(SambaPermission::UserAssignRight->value);
        $this->actingAs($actor);

        return $actor;
    }

    private function drawer(): string
    {
        return 'pages::users._partials.rights-drawer';
    }

    #[Test]
    public function assigner_un_role_conserve_les_roles_deja_portes(): void
    {
        $this->actingAsRightsManager();

        $target = $this->makeUser('dupont');
        $target->assignRole(SambaRole::UserAdmin->value);
        $target->assignRole(SambaRole::ComputerAdmin->value);

        Livewire::test($this->drawer())
            ->call('open', [$target->login])
            ->set('selectedRole', SambaRole::ShareAdmin->value)
            ->set('removeRole', false)
            ->call('applyRoles');

        $target->unsetRelation('roles');
        $this->assertTrue($target->hasRole(SambaRole::ShareAdmin->value), 'le rôle demandé doit être ajouté');
        $this->assertTrue($target->hasRole(SambaRole::UserAdmin->value), 'user-admin ne doit pas être effacé');
        $this->assertTrue($target->hasRole(SambaRole::ComputerAdmin->value), 'computer-admin ne doit pas être effacé');
    }

    #[Test]
    public function la_coche_retirer_enleve_uniquement_le_role_vise(): void
    {
        $this->actingAsRightsManager();

        $target = $this->makeUser('durand');
        $target->assignRole(SambaRole::UserAdmin->value);
        $target->assignRole(SambaRole::ComputerAdmin->value);

        Livewire::test($this->drawer())
            ->call('open', [$target->login])
            ->set('selectedRole', SambaRole::UserAdmin->value)
            ->set('removeRole', true)
            ->call('applyRoles');

        $target->unsetRelation('roles');
        $this->assertFalse($target->hasRole(SambaRole::UserAdmin->value));
        $this->assertTrue($target->hasRole(SambaRole::ComputerAdmin->value));
    }

    #[Test]
    public function le_compte_protege_est_ignore_par_un_retrait_de_role(): void
    {
        $this->actingAsRightsManager();

        $admin = $this->makeUser(User::PROTECTED_ADMIN_LOGIN);
        $admin->assignRole(SambaRole::SuperAdmin->value);

        Livewire::test($this->drawer())
            ->call('open', [$admin->login])
            ->set('selectedRole', SambaRole::SuperAdmin->value)
            ->set('removeRole', true)
            ->call('applyRoles');

        $admin->unsetRelation('roles');
        $this->assertTrue(
            $admin->hasRole(SambaRole::SuperAdmin->value),
            'le compte protégé ne peut pas perdre super-admin'
        );
    }

    #[Test]
    public function le_compte_protege_est_ignore_par_un_retrait_de_permission(): void
    {
        $this->actingAsRightsManager();

        $admin = $this->makeUser(User::PROTECTED_ADMIN_LOGIN);
        $admin->givePermissionTo(SambaPermission::ServerAdmin->value);

        Livewire::test($this->drawer())
            ->call('open', [$admin->login])
            ->set('selectedPermissions', [SambaPermission::ServerAdmin->value])
            ->set('removePermissions', true)
            ->call('applyPermissions');

        $admin->unsetRelation('permissions');
        $this->assertTrue(
            $admin->hasDirectPermission(SambaPermission::ServerAdmin->value),
            'le compte protégé ne peut perdre aucune permission directe'
        );
    }
}
