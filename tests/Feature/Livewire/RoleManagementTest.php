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
 * Tests Feature — gestion des profils dans /app/rights-management (Story 7.2, AC3).
 *
 * Architecture post-refonte 7.2 (commit 9ef7627) : la modale inline a été
 * remplacée par 3 composants Livewire SFC distincts :
 *  - `pages::rights-management.index`              → liste + bulk delete
 *  - `pages::rights-management.profiles.new`       → création
 *  - `pages::rights-management.profiles.[id]`      → édition + suppression unitaire
 *
 * Couvre :
 *  - liste avec badge seeded/custom (loadProfiles)
 *  - création / édition / suppression unitaire / suppression bulk
 *  - garde-fous (rôle seedé non éditable + non supprimable, users assignés
 *    bloquent suppression)
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

    private function indexPage(): string
    {
        return 'pages::rights-management.index';
    }

    private function newProfilePage(): string
    {
        return 'pages::rights-management.profiles.new';
    }

    private function editProfilePage(): string
    {
        return 'pages::rights-management.profiles.[id]';
    }

    public function test_load_profiles_lists_all_seeded_and_custom_roles(): void
    {
        Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $component = Livewire::test($this->indexPage())
            ->call('loadProfiles');

        // Story 49.1 — `profilesList` a été scindé : la liste historique est
        // désormais `unattachedProfilesList` (profils portés par AUCUN groupe).
        // Aucun groupe porteur n'existe dans ce test : tous les profils y sont.
        $profiles = $component->get('unattachedProfilesList');

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

        Livewire::test($this->newProfilePage())
            ->set('name', 'Animateur CDI')
            ->set('permissions', [
                SambaPermission::ComputerView->value,
                SambaPermission::ComputerControl->value,
            ])
            ->call('save');

        $role = Role::where('name', 'Animateur CDI')->where('guard_name', 'web')->first();
        $this->assertNotNull($role);
        $this->assertEquals(2, $role->permissions->count());
    }

    public function test_create_profile_rejects_name_collision_with_seeded_role(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->newProfilePage())
            ->set('name', SambaRole::SuperAdmin->value)
            ->set('permissions', [SambaPermission::UserRead->value])
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);
    }

    public function test_edit_profile_updates_permissions(): void
    {
        $role = Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);
        $role->syncPermissions([SambaPermission::ComputerView->value]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->editProfilePage(), ['id' => $role->id])
            ->assertSet('name', 'Animateur CDI')
            ->assertSet('isSeeded', false)
            ->set('permissions', [
                SambaPermission::ComputerView->value,
                SambaPermission::ComputerControl->value,
                SambaPermission::UserRead->value,
            ])
            ->call('save')
            ->assertHasNoErrors();

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

        $seedRole = Role::findByName(SambaRole::Technicien->value, 'web');
        $permsBefore = $seedRole->permissions->pluck('name')->sort()->values()->toArray();

        Livewire::test($this->editProfilePage(), ['id' => $seedRole->id])
            ->assertSet('isSeeded', true)
            ->set('permissions', [SambaPermission::ComputerView->value])
            ->call('save')
            ->assertStatus(403);

        $seedRole->refresh();
        $permsAfter = $seedRole->permissions->pluck('name')->sort()->values()->toArray();
        $this->assertEquals($permsBefore, $permsAfter);
    }

    public function test_delete_custom_profile_succeeds(): void
    {
        $role = Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->editProfilePage(), ['id' => $role->id])
            ->call('delete');

        $this->assertNull(Role::where('name', 'Animateur CDI')->first());
    }

    public function test_delete_seeded_profile_is_refused(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $seedRole = Role::findByName(SambaRole::SuperAdmin->value, 'web');

        Livewire::test($this->editProfilePage(), ['id' => $seedRole->id])
            ->call('delete');

        // Toast d'erreur affiché, pas de redirect, le rôle est intact.
        $this->assertNotNull(Role::findByName(SambaRole::SuperAdmin->value, 'web'));
    }

    public function test_delete_custom_profile_with_users_assigned_is_blocked(): void
    {
        $role = Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);

        $user = User::create(['login' => 'animator-1', 'role' => 'prof', 'is_active' => true]);
        $user->assignRole('Animateur CDI');

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->editProfilePage(), ['id' => $role->id])
            ->assertSet('usersCount', 1)
            ->call('delete');

        $this->assertNotNull(Role::where('name', 'Animateur CDI')->first());
    }

    /**
     * Bulk delete depuis la liste (sélection multiple via checkboxes) —
     * remplace l'ancien `deleteProfile` unitaire de la modale.
     */
    public function test_bulk_delete_profiles_skips_seeded_and_assigned(): void
    {
        $custom = Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);
        $assigned = Role::create(['name' => 'Bibliothécaire', 'guard_name' => 'web']);

        $user = User::create(['login' => 'biblio-1', 'role' => 'prof', 'is_active' => true]);
        $user->assignRole('Bibliothécaire');

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->indexPage())
            ->set('selectedProfiles', [
                'Animateur CDI',
                'Bibliothécaire',
                SambaRole::SuperAdmin->value,
            ])
            ->call('deleteSelectedProfiles');

        // Custom sans assignation supprimé.
        $this->assertNull(Role::where('name', 'Animateur CDI')->first());
        // Custom assigné conservé (skip).
        $this->assertNotNull(Role::where('name', 'Bibliothécaire')->first());
        // Seedé conservé (skip).
        $this->assertNotNull(Role::findByName(SambaRole::SuperAdmin->value, 'web'));
    }

    public function test_non_admin_cannot_save_profile(): void
    {
        $notAdmin = User::create(['login' => 'not-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($notAdmin);

        Livewire::test($this->newProfilePage())
            ->set('name', 'Profil Pirate')
            ->set('permissions', [SambaPermission::UserRead->value])
            ->call('save')
            ->assertStatus(403);
    }

    /**
     * Review 7.2 #7 — `save()` invalide le cache Spatie : après ajout d'une
     * permission au rôle custom, un `$user->can()` sur un fresh model reflète
     * l'ajout à la requête suivante (pas de cache stale).
     */
    public function test_saveProfile_invalidates_spatie_cache_end_to_end(): void
    {
        // 1. Setup : rôle custom + user avec ce rôle, sans user.modify.
        $role = Role::create(['name' => 'Animateur CDI', 'guard_name' => 'web']);
        $user = User::create(['login' => 'animator-cache', 'role' => 'prof', 'is_active' => true]);
        $user->assignRole('Animateur CDI');

        // 2. Prime le cache : aucune perm user.modify.
        $this->assertFalse($user->can('user.modify'));

        // 3. L'admin ajoute user.modify au rôle via la page d'édition.
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test($this->editProfilePage(), ['id' => $role->id])
            ->set('permissions', [SambaPermission::UserModify->value])
            ->call('save')
            ->assertHasNoErrors();

        // 4. Fresh fetch : la permission doit être visible grâce à l'invalidation.
        $freshUser = User::find($user->id);
        $this->assertTrue(
            $freshUser->can('user.modify'),
            'Le cache Spatie doit être invalidé après save (review 7.2 #7)'
        );
    }
}
