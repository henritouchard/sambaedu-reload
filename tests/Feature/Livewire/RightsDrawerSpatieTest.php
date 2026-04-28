<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Models\User;
use App\Observers\WorkstationGroupObserver;
use App\Repositories\RightRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Tests Feature Livewire — `rights-drawer` Spatie (Story 7.3 refactor UI).
 *
 * Vérifie :
 *  - Affichage des rôles Spatie assignés avec leur label.
 *  - Affichage des permissions associées (labels FR lisibles).
 *  - Plus aucun bitmask hex `0x...` dans le rendu.
 *  - Plus aucune lecture `RightRepository::getAllRightsValues()` au runtime
 *    (vérifié via mock qui lève si consulté).
 */
class RightsDrawerSpatieTest extends TestCase
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
        $this->seedPermissionsAndRoles();

        Queue::fake();
        WorkstationGroupObserver::disableSync();

        // Gate `manage-rights` : on autorise dans tous les tests.
        Gate::define('manage-rights', fn (?User $user) => true);

        // Remplace le `RightRepository` par un mock qui lève à chaque accès :
        // si le drawer appelle encore `getAllRightsValues()`, ce test crashe.
        $failingRepo = Mockery::mock(RightRepository::class);
        $failingRepo->shouldReceive('getAllRightsValues')
            ->andThrow(new RuntimeException('LDAP must not be queried by RightsDrawer in 7.3'));
        $failingRepo->shouldReceive('invalidateCache')->andReturnNull();
        $failingRepo->shouldReceive('findByName')->andReturnNull();
        $this->app->instance(RightRepository::class, $failingRepo);
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

    private function makeUser(string $login, bool $assignUserAdmin = true): User
    {
        $user = User::create([
            'login'    => $login,
            'fullname' => ucfirst($login),
            'role'     => 'autre',
            'dn'       => "CN={$login},OU=Utilisateurs,DC=test",
            'is_active' => true,
        ]);

        if ($assignUserAdmin) {
            $user->assignRole(SambaRole::UserAdmin->value);
        }

        return $user;
    }

    private function drawerComponent(): string
    {
        return 'components::organisms.rights-drawer';
    }

    #[Test]
    public function it_loads_available_spatie_roles_when_opened(): void
    {
        $target = $this->makeUser('drawer-target1');
        $admin = $this->makeUser('drawer-admin1');
        $this->actingAs($admin);

        $component = Livewire::test($this->drawerComponent())
            ->call('open', $target->login);

        $component->assertSet('targetLogin', $target->login);
        $component->assertSet('isOpen', true);

        $meta = $component->get('rolesMeta');
        $this->assertIsArray($meta);
        $this->assertArrayHasKey(SambaRole::UserAdmin->value, $meta);
        $this->assertArrayHasKey(SambaRole::SuperAdmin->value, $meta);
    }

    #[Test]
    public function it_marks_assigned_roles_as_checked(): void
    {
        $target = $this->makeUser('drawer-target2'); // assignUserAdmin=true
        $admin = $this->makeUser('drawer-admin2');
        $this->actingAs($admin);

        $component = Livewire::test($this->drawerComponent())
            ->call('open', $target->login);

        $rolesState = $component->get('rolesState');
        $this->assertTrue($rolesState[SambaRole::UserAdmin->value] ?? false);
        // SuperAdmin non assigné
        $this->assertFalse($rolesState[SambaRole::SuperAdmin->value] ?? true);
    }

    #[Test]
    public function rendered_output_contains_readable_permission_labels_not_bitmask_hex(): void
    {
        $target = $this->makeUser('drawer-target3');
        $admin = $this->makeUser('drawer-admin3');
        $this->actingAs($admin);

        $component = Livewire::test($this->drawerComponent())
            ->call('open', $target->login);

        $rendered = $component->html();

        // Labels FR présents (issus de `SambaPermission::label()`).
        $this->assertStringContainsString('Consulter les utilisateurs', $rendered);

        // Plus aucun bitmask hex `0x...` dans le rendu.
        $this->assertDoesNotMatchRegularExpression(
            '/0x[0-9A-Fa-f]{1,4}/',
            $rendered,
            'Le drawer refactoré ne doit plus afficher de bitmask hex'
        );
    }

    #[Test]
    public function toggling_a_role_updates_spatie_assignment_on_save(): void
    {
        $target = $this->makeUser('drawer-target4', assignUserAdmin: false);
        $admin = $this->makeUser('drawer-admin4');
        $this->actingAs($admin);

        $component = Livewire::test($this->drawerComponent())
            ->call('open', $target->login);

        $component->call('toggleRole', SambaRole::EleveAdmin->value);
        $component->call('saveChanges');

        $this->assertTrue(
            $target->fresh()->hasRole(SambaRole::EleveAdmin->value),
            'Le rôle EleveAdmin doit être assigné après saveChanges'
        );
    }
}
