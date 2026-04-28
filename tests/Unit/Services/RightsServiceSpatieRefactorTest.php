<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\LegacyRight;
use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\LdapModels\LdapRightGroup;
use App\Models\Delegation;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Repositories\RightRepository;
use App\Services\RightsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Tests Story 7.3 — refactor `RightsService::calculateRights()` Spatie-only.
 *
 * Valide :
 *  - Le calcul depuis Spatie pour chaque profil seedé.
 *  - L'agrégation OR des délégations scopées positives.
 *  - Le retrait AND-NOT des délégations scopées négatives.
 *  - Le filtrage systématique de `SE_COMPUTER_VIEW`.
 *  - L'indépendance vis-à-vis de LDAP : la méthode fonctionne même si
 *    `RightRepository::getAllRightsValues()` lève (preuve qu'aucune lecture
 *    LDAP n'est effectuée en runtime).
 */
class RightsServiceSpatieRefactorTest extends TestCase
{
    use CreatesPermissionSchema;
    use DatabaseTransactions;

    private RightsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();
        $this->seedPermissionsAndRoles();

        Queue::fake();
        WorkstationGroupObserver::disableSync();

        // Injection d'un RightRepository qui lève si consulté : garantit
        // qu'aucune lecture LDAP n'est faite pendant `calculateRights()`.
        $failingRepo = Mockery::mock(RightRepository::class);
        $failingRepo->shouldReceive('getAllRightsValues')
            ->andThrow(new RuntimeException('LDAP must not be queried'));
        $failingRepo->shouldReceive('invalidateCache')->andReturnNull();

        $this->service = new RightsService($failingRepo);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        $this->dropPermissionSchema();
        Mockery::close();
        parent::tearDown();
    }

    private function seedPermissionsAndRoles(): void
    {
        foreach (SambaPermission::cases() as $perm) {
            Permission::firstOrCreate(['name' => $perm->value, 'guard_name' => 'web']);
        }
        foreach (SambaRole::cases() as $sambaRole) {
            $role = Role::firstOrCreate(['name' => $sambaRole->value, 'guard_name' => 'web']);
            $role->syncPermissions($sambaRole->permissionNames());
        }
    }

    private function createUser(string $login): User
    {
        return User::create([
            'login'    => $login,
            'fullname' => ucfirst($login),
            'dn'       => "CN={$login},OU=Utilisateurs,DC=test",
            'role'     => 'autre',
            'is_active' => true,
        ]);
    }

    // ================================================================
    // Calcul depuis Spatie (rôles seedés + permissions directes)
    // ================================================================

    #[Test]
    public function it_computes_bitmask_for_user_admin_role(): void
    {
        $user = $this->createUser('ua1');
        $user->assignRole(SambaRole::UserAdmin->value);

        $bitmask = $this->service->calculateRightsForUser($user->fresh());

        // UserAdmin = user.password.init (0x01) | user.read (0x02) | user.modify (0x04)
        // | user.create.temp (0x08) | user.assign.right (0x10) | user.delegate (0x20)
        // | share.view (0x40) | share.refresh (0x80) = 0xFF
        $this->assertSame(0xFF, $bitmask, 'UserAdmin doit produire le bitmask 0xFF (SE_USER_ADMIN)');
    }

    #[Test]
    public function it_computes_bitmask_for_computer_admin_role_without_view(): void
    {
        $user = $this->createUser('ca1');
        $user->assignRole(SambaRole::ComputerAdmin->value);

        $bitmask = $this->service->calculateRightsForUser($user->fresh());

        // ComputerAdmin inclut computer.view (0x100) — mais c'est filtré.
        // Reste : computer.control (0x200) | computer.elevate (0x400)
        //      | computer.install (0x800) | wpkg.assign (0x1000)
        //      | wpkg.add (0x2000) | wpkg.create (0x4000)
        //      | app.customize (alias 0x800 → déjà inclus).
        // Total (sans view) = 0x7E00.
        $this->assertSame(0, $bitmask & LegacyRight::ComputerView->value, 'SE_COMPUTER_VIEW doit être filtré');
        $this->assertNotSame(0, $bitmask & LegacyRight::ComputerControl->value, 'computer.control doit être présent');
        $this->assertNotSame(0, $bitmask & LegacyRight::WpkgCreate->value, 'wpkg.create doit être présent');
    }

    #[Test]
    public function it_computes_zero_bitmask_when_user_has_no_roles_or_permissions(): void
    {
        $user = $this->createUser('noperm1');

        $bitmask = $this->service->calculateRightsForUser($user->fresh());

        $this->assertSame(0, $bitmask);
    }

    #[Test]
    public function it_computes_bitmask_for_super_admin_role(): void
    {
        $user = $this->createUser('sa1');
        $user->assignRole(SambaRole::SuperAdmin->value);

        $bitmask = $this->service->calculateRightsForUser($user->fresh());

        // SuperAdmin = toutes les permissions. SE_ADMIN = 0xFFFF.
        // Filtrage SE_COMPUTER_VIEW → 0xFEFF.
        $this->assertSame(0, $bitmask & LegacyRight::ComputerView->value);
        $this->assertSame(
            0xFFFF & ~LegacyRight::ComputerView->value,
            $bitmask,
            'SuperAdmin doit produire SE_ADMIN (0xFFFF) moins SE_COMPUTER_VIEW'
        );
    }

    // ================================================================
    // Délégations scopées
    // ================================================================

    #[Test]
    public function it_adds_positive_scoped_delegation_to_bitmask(): void
    {
        $user = $this->createUser('deleg1');
        $wg = WorkstationGroup::create([
            'name' => 'salle-deleg-a',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $perm = Permission::findByName(SambaPermission::ComputerElevate->value, 'web');
        Delegation::create([
            'user_id' => $user->id,
            'workstation_group_id' => $wg->id,
            'permission_id' => $perm->id,
            'is_negative' => false,
        ]);

        $bitmaskWithoutScope = $this->service->calculateRightsForUser($user->fresh());
        $bitmaskWithScope = $this->service->calculateRightsForUser($user->fresh(), $wg);

        $this->assertSame(0, $bitmaskWithoutScope, 'Sans scope : aucune délégation comptée');
        $this->assertSame(
            LegacyRight::ComputerElevate->value,
            $bitmaskWithScope,
            'Avec scope : computer.elevate doit être ajouté au bitmask'
        );
    }

    #[Test]
    public function it_removes_negative_scoped_delegation_from_bitmask_and_not(): void
    {
        $user = $this->createUser('deleg2');
        $user->assignRole(SambaRole::ComputerAdmin->value); // Donne computer.install par défaut.

        $wg = WorkstationGroup::create([
            'name' => 'salle-deleg-b',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $perm = Permission::findByName(SambaPermission::ComputerInstall->value, 'web');
        Delegation::create([
            'user_id' => $user->id,
            'workstation_group_id' => $wg->id,
            'permission_id' => $perm->id,
            'is_negative' => true,
        ]);

        $bitmaskWithoutScope = $this->service->calculateRightsForUser($user->fresh());
        $bitmaskWithScope = $this->service->calculateRightsForUser($user->fresh(), $wg);

        // Sans scope : computer.install présent (via role).
        $this->assertNotSame(0, $bitmaskWithoutScope & LegacyRight::ComputerInstall->value);

        // Avec scope : la négative AND-NOT retire ce bit.
        $this->assertSame(0, $bitmaskWithScope & LegacyRight::ComputerInstall->value);
    }

    // ================================================================
    // Preuve : aucune lecture LDAP
    // ================================================================

    #[Test]
    public function it_works_even_if_ldap_is_down(): void
    {
        $user = $this->createUser('offline1');
        $user->assignRole(SambaRole::UserAdmin->value);

        // Le RightRepository injecté dans setUp() lève systématiquement :
        // si calculateRights() le consultait, ce test crasherait.
        $bitmask = $this->service->calculateRightsForUser($user->fresh());

        $this->assertSame(0xFF, $bitmask, 'Le calcul doit aboutir même si LDAP est inaccessible');
    }

    #[Test]
    public function signature_legacy_array_login_still_works(): void
    {
        $user = $this->createUser('retrocompat1');
        $user->assignRole(SambaRole::UserAdmin->value);

        // Signature legacy : `calculateRights(array, string)` — utilisée par
        // `resources/views/pages/users/[login]/_partials/permissions.blade.php`
        // et `MigrateDelegationsCommand`. Doit toujours fonctionner.
        $bitmask = $this->service->calculateRights([], 'retrocompat1');

        $this->assertSame(0xFF, $bitmask, 'Signature legacy doit toujours fonctionner');
    }

    #[Test]
    public function admin_login_returns_se_admin_shortcut(): void
    {
        // Cas spécial root : pas besoin de résoudre en DB.
        $bitmask = $this->service->calculateRights([], 'admin');
        $this->assertSame(LegacyRight::admin(), $bitmask);
    }
}
