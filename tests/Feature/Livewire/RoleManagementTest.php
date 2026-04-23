<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Tests Feature — onglet "Profils" dans /app/rights-management (Story 7.2, AC3).
 *
 * Couvre CRUD profils dynamiques :
 *  - liste avec badge seeded/custom
 *  - création / édition / duplication / suppression
 *  - garde-fous (rôle seedé non supprimable, users assignés bloquent suppression)
 *  - invalidation cache Spatie post-mutation
 */
class RoleManagementTest extends TestCase
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
        (new PermissionSeeder())->run();
    }

    protected function tearDown(): void
    {
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeAdmin(string $login = 'rights-admin'): User
    {
        $admin = User::create([
            'login' => $login,
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->givePermissionTo('user.assign.right');
        return $admin;
    }

    private function pageComponent(): string
    {
        return 'pages::rights-management.index';
    }

    public function test_load_profiles_lists_all_seeded_and_custom_roles(): void
    {
        Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $component = Livewire::test($this->pageComponent())
            ->call('setActiveTab', 'profiles')
            ->call('loadProfiles');

        $profiles = $component->get('profilesList');

        $this->assertGreaterThanOrEqual(
            count(SambaRole::cases()) + 1,
            count($profiles)
        );

        $names = array_column($profiles, 'name');
        $this->assertContains(SambaRole::SuperAdmin->value, $names);
        $this->assertContains('Animateur CDI', $names);

        $custom = collect($profiles)->firstWhere('name', 'Animateur CDI');
        $this->assertFalse($custom['is_seeded']);
        $seeded = collect($profiles)->firstWhere('name', SambaRole::SuperAdmin->value);
        $this->assertTrue($seeded['is_seeded']);
    }

    public function test_create_profile_with_permissions(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->pageComponent())
            ->call('openCreateProfileModal')
            ->set('profileFormName', 'Animateur CDI')
            ->set('profileFormPermissions', [
                SambaPermission::ComputerView->value,
                SambaPermission::ComputerControl->value,
            ])
            ->call('saveProfile')
            ->assertSet('profileModalOpen', false);

        $role = Role::where('name', 'Animateur CDI')->where('guard_name', 'web')->first();
        $this->assertNotNull($role);
        $this->assertEquals(2, $role->permissions->count());
    }

    public function test_create_profile_rejects_name_collision_with_seeded_role(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->pageComponent())
            ->call('openCreateProfileModal')
            ->set('profileFormName', SambaRole::SuperAdmin->value)
            ->set('profileFormPermissions', [SambaPermission::UserRead->value])
            ->call('saveProfile')
            // Validation `unique` sur roles.name, le form ne ferme pas.
            ->assertSet('profileModalOpen', true);
    }

    public function test_edit_profile_updates_permissions(): void
    {
        $role = Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);
        $role->syncPermissions([SambaPermission::ComputerView->value]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->pageComponent())
            ->call('openEditProfileModal', 'Animateur CDI')
            ->assertSet('profileFormName', 'Animateur CDI')
            ->set('profileFormPermissions', [
                SambaPermission::ComputerView->value,
                SambaPermission::ComputerControl->value,
                SambaPermission::UserRead->value,
            ])
            ->call('saveProfile')
            ->assertSet('profileModalOpen', false);

        $role->refresh();
        $this->assertEquals(3, $role->permissions->count());
    }

    /**
     * Review 7.2 #M3 — L'édition des permissions des rôles seedés est
     * interdite (garde-fou serveur + UI disabled). Le test vérifie que la
     * tentative abort 403 et que les permissions restent inchangées.
     */
    public function test_edit_profile_on_seeded_role_is_refused_server_side(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        // Capture l'état initial des permissions du rôle seedé.
        $seedRoleBefore = Role::findByName(SambaRole::Technicien->value, 'web');
        $permsBefore = $seedRoleBefore->permissions->pluck('name')->sort()->values()->toArray();

        Livewire::test($this->pageComponent())
            ->call('openEditProfileModal', SambaRole::Technicien->value)
            ->assertSet('editingProfileIsSeeded', true)
            ->set('profileFormName', SambaRole::Technicien->value)
            // Tentative de vider les permissions — doit être refusée.
            ->set('profileFormPermissions', [SambaPermission::ComputerView->value])
            ->call('saveProfile')
            ->assertStatus(403);

        // Les permissions d'origine doivent être intactes.
        $seedRoleAfter = Role::findByName(SambaRole::Technicien->value, 'web');
        $permsAfter = $seedRoleAfter->permissions->pluck('name')->sort()->values()->toArray();
        $this->assertEquals($permsBefore, $permsAfter);
    }

    /**
     * Review 7.2 #M3 — La duplication d'un rôle seedé reste autorisée :
     * le duplicata devient un rôle custom, pleinement éditable.
     */
    public function test_duplicated_seeded_role_can_be_edited(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        // Duplique `Technicien` → `Technicien_copy`.
        Livewire::test($this->pageComponent())
            ->call('duplicateProfile', SambaRole::Technicien->value);

        $copy = Role::where('name', SambaRole::Technicien->value . '_copy')->first();
        $this->assertNotNull($copy);

        // On peut éditer les permissions du duplicata.
        Livewire::test($this->pageComponent())
            ->call('openEditProfileModal', $copy->name)
            ->assertSet('editingProfileIsSeeded', false)
            ->set('profileFormPermissions', [SambaPermission::ComputerView->value])
            ->call('saveProfile')
            ->assertSet('profileModalOpen', false);

        $copy->refresh();
        $this->assertEquals(1, $copy->permissions->count());
        $this->assertEquals(SambaPermission::ComputerView->value, $copy->permissions->first()->name);
    }

    public function test_duplicate_profile_creates_copy_with_same_permissions(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->pageComponent())
            ->call('duplicateProfile', SambaRole::Technicien->value);

        $copy = Role::where('name', SambaRole::Technicien->value . '_copy')->first();
        $this->assertNotNull($copy, 'Le profil dupliqué doit exister');
        $original = Role::findByName(SambaRole::Technicien->value, 'web');
        $this->assertEquals(
            $original->permissions->pluck('name')->sort()->values()->toArray(),
            $copy->permissions->pluck('name')->sort()->values()->toArray()
        );
    }

    public function test_delete_custom_profile_succeeds(): void
    {
        $role = Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->pageComponent())
            ->call('deleteProfile', 'Animateur CDI');

        $this->assertNull(Role::where('name', 'Animateur CDI')->first());
    }

    public function test_delete_seeded_profile_is_refused(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->pageComponent())
            ->call('deleteProfile', SambaRole::SuperAdmin->value);

        // Le rôle seed doit toujours exister.
        $this->assertNotNull(Role::findByName(SambaRole::SuperAdmin->value, 'web'));
    }

    public function test_delete_custom_profile_with_users_assigned_is_blocked(): void
    {
        $role = Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);

        $user = User::create(['login' => 'animator-1', 'role' => 'prof', 'is_active' => true]);
        $user->assignRole('Animateur CDI');

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->pageComponent())
            ->call('deleteProfile', 'Animateur CDI');

        // Le rôle doit toujours exister (bloqué par le garde-fou).
        $this->assertNotNull(Role::where('name', 'Animateur CDI')->first());
    }

    public function test_non_admin_cannot_save_profile(): void
    {
        $notAdmin = User::create(['login' => 'not-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($notAdmin);

        Livewire::test($this->pageComponent())
            ->set('profileFormName', 'Profil Pirate')
            ->set('profileFormPermissions', [SambaPermission::UserRead->value])
            ->call('saveProfile')
            ->assertStatus(403);
    }

    /**
     * Review 7.2 #7 — `saveProfile` invalide le cache Spatie : après ajout
     * d'une permission au rôle custom, un `$user->can()` sur un fresh model
     * reflète l'ajout à la requête suivante (pas de cache stale).
     */
    public function test_saveProfile_invalidates_spatie_cache_end_to_end(): void
    {
        // 1. Setup : rôle custom + user avec ce rôle, sans user.modify.
        $role = Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);
        $user = User::create(['login' => 'animator-cache', 'role' => 'prof', 'is_active' => true]);
        $user->assignRole('Animateur CDI');

        // 2. Prime le cache : aucune perm user.modify.
        $this->assertFalse($user->can('user.modify'));

        // 3. L'admin ajoute user.modify au rôle via l'UI.
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->pageComponent())
            ->call('openEditProfileModal', 'Animateur CDI')
            ->set('profileFormPermissions', [SambaPermission::UserModify->value])
            ->call('saveProfile')
            ->assertSet('profileModalOpen', false);

        // 4. Fresh fetch : la permission doit être visible grâce à l'invalidation.
        $freshUser = User::find($user->id);
        $this->assertTrue(
            $freshUser->can('user.modify'),
            'Le cache Spatie doit être invalidé après saveProfile (review 7.2 #7)'
        );
    }
}
