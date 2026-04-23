<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Services\UserSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Tests unitaires — Story 7.2 (AC2).
 *
 * Vérifie que `UserSyncService::ensurePermissionsExist` ne détruit plus la
 * configuration des rôles à chaque sync AD.
 *
 * Le bloc fautif (ligne ~195 avant fix) appelait `syncPermissions(...)` sur
 * chaque rôle à chaque sync : les profils custom y perdaient leurs perms,
 * et les admins leurs customisations des rôles seedés.
 */
class UserSyncServiceEnsurePermissionsTest extends TestCase
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

    /**
     * Invoque la méthode privée `ensurePermissionsExist` via Reflection.
     */
    private function callEnsurePermissions(UserSyncService $service): void
    {
        $r = new ReflectionClass($service);
        $m = $r->getMethod('ensurePermissionsExist');
        $m->setAccessible(true);
        $m->invoke($service, fn(string $level, string $msg) => null);
    }

    public function test_ensure_creates_all_permissions_and_roles_on_empty_db(): void
    {
        $service = app(UserSyncService::class);
        $this->callEnsurePermissions($service);

        $this->assertEquals(
            count(SambaPermission::cases()),
            Permission::count()
        );
        $this->assertEquals(
            count(SambaRole::cases()),
            Role::count()
        );
    }

    public function test_ensure_does_not_overwrite_permissions_of_existing_seeded_role(): void
    {
        // 1. Seed initial
        $service = app(UserSyncService::class);
        $this->callEnsurePermissions($service);

        // 2. Admin modifie un rôle seedé via UI (retire toutes ses permissions).
        $computerAdmin = Role::findByName(SambaRole::ComputerAdmin->value, 'web');
        $computerAdmin->syncPermissions([SambaPermission::ComputerView->value]);
        $this->assertEquals(1, $computerAdmin->fresh()->permissions->count());

        // 3. Sync AD rappelle ensurePermissionsExist.
        $this->callEnsurePermissions($service);

        // 4. Les permissions du rôle seedé doivent être préservées.
        $computerAdmin->refresh();
        $this->assertEquals(
            1,
            $computerAdmin->permissions->count(),
            'La sync AD NE DOIT PAS écraser les permissions des rôles seedés édités'
        );
    }

    public function test_ensure_preserves_custom_roles_completely(): void
    {
        $service = app(UserSyncService::class);
        $this->callEnsurePermissions($service);

        // Créer un profil custom.
        $customRole = Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);
        $customRole->syncPermissions([
            SambaPermission::ComputerView->value,
            SambaPermission::UserRead->value,
        ]);

        // Sync AD.
        $this->callEnsurePermissions($service);

        $customRole->refresh();
        $this->assertTrue(Role::where('name', 'Animateur CDI')->exists());
        $this->assertEquals(
            2,
            $customRole->permissions->count(),
            'Les rôles custom ne doivent jamais être modifiés par la sync AD'
        );
    }

    public function test_ensure_is_idempotent_on_repeated_calls(): void
    {
        $service = app(UserSyncService::class);
        $this->callEnsurePermissions($service);
        $this->callEnsurePermissions($service);
        $this->callEnsurePermissions($service);

        $this->assertEquals(count(SambaPermission::cases()), Permission::count());
        $this->assertEquals(count(SambaRole::cases()), Role::count());
    }
}
