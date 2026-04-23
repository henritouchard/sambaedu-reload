<?php

declare(strict_types=1);

namespace Tests\Unit\Seeders;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Tests unitaires du PermissionSeeder — Story 7.2 (AC1).
 *
 * Garantit le caractère idempotent et NON-DESTRUCTIF :
 *  - 1ère passe : seed les 19 permissions + 9 rôles ;
 *  - 2ème passe : préserve les rôles seedés modifiés par l'admin ;
 *  - rôles custom (créés via UI ou sync AD) : jamais touchés.
 */
class PermissionSeederTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    public function test_first_run_creates_all_permissions_and_roles(): void
    {
        $seeder = new PermissionSeeder();
        $stats = $seeder->run();

        $this->assertEquals(
            count(SambaPermission::cases()),
            Permission::where('guard_name', 'web')->count(),
            'Toutes les permissions de l\'enum doivent être créées'
        );

        $this->assertEquals(
            count(SambaRole::cases()),
            Role::where('guard_name', 'web')->count(),
            'Tous les rôles seedés doivent être créés'
        );

        $this->assertEquals(count(SambaRole::cases()), $stats['roles_seeded_new']);
        $this->assertEquals(0, $stats['roles_seeded_preserved']);
    }

    public function test_superadmin_role_has_all_permissions_at_first_run(): void
    {
        $seeder = new PermissionSeeder();
        $seeder->run();

        $superAdmin = Role::findByName(SambaRole::SuperAdmin->value, 'web');
        $this->assertEquals(
            count(SambaPermission::cases()),
            $superAdmin->permissions->count()
        );
    }

    public function test_second_run_does_not_touch_modified_seeded_role(): void
    {
        $seeder = new PermissionSeeder();
        $seeder->run();

        // Admin modifie le rôle computer-admin (retire une permission).
        $role = Role::findByName(SambaRole::ComputerAdmin->value, 'web');
        $role->syncPermissions([SambaPermission::ComputerView->value]);
        $initialPermsCount = $role->fresh()->permissions->count();
        $this->assertEquals(1, $initialPermsCount);

        // Re-run du seeder sans force.
        $stats = $seeder->run();

        $role->refresh();
        $this->assertEquals(
            1,
            $role->permissions->count(),
            'Le rôle seedé modifié doit rester intact après re-seed sans force'
        );
        $this->assertEquals(count(SambaRole::cases()), $stats['roles_seeded_preserved']);
        $this->assertEquals(0, $stats['roles_seeded_new']);
    }

    public function test_force_run_resynchronizes_seeded_role_permissions(): void
    {
        $seeder = new PermissionSeeder();
        $seeder->run();

        // Admin vide les permissions d'un rôle.
        $role = Role::findByName(SambaRole::ComputerAdmin->value, 'web');
        $role->syncPermissions([]);
        $this->assertEquals(0, $role->fresh()->permissions->count());

        // Re-run avec force.
        $seeder->run(force: true);

        $role->refresh();
        $expected = SambaRole::ComputerAdmin->permissionNames();
        $this->assertEquals(
            count($expected),
            $role->permissions->count(),
            'Force doit resynchroniser les permissions'
        );
    }

    public function test_second_run_preserves_custom_roles(): void
    {
        $seeder = new PermissionSeeder();
        $seeder->run();

        // Un admin crée un profil custom via l'UI.
        $customRole = Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);
        $customRole->syncPermissions([
            SambaPermission::ComputerView->value,
            SambaPermission::ComputerControl->value,
            SambaPermission::UserRead->value,
        ]);

        // Re-run du seeder.
        $stats = $seeder->run();

        $customRole->refresh();
        $this->assertTrue(
            Role::where('name', 'Animateur CDI')->exists(),
            'Le rôle custom doit survivre au re-seed'
        );
        $this->assertEquals(
            3,
            $customRole->permissions->count(),
            'Les permissions du rôle custom doivent être préservées'
        );
        $this->assertEquals(1, $stats['roles_custom_preserved']);
    }

    public function test_seeder_is_fully_idempotent_on_multiple_runs(): void
    {
        $seeder = new PermissionSeeder();
        $seeder->run();
        $seeder->run();
        $seeder->run();

        $this->assertEquals(
            count(SambaPermission::cases()),
            Permission::count()
        );
        $this->assertEquals(
            count(SambaRole::cases()),
            Role::count()
        );
    }
}
