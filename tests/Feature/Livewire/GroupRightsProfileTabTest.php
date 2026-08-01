<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SambaPermission;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 49.1 (AC6, AC7) — onglet Profils de `/app/rights-management`.
 *
 * Couvre les deux sections (groupes porteurs / profils non portés), les gestes
 * donner-changer-retirer avec re-projection effective des membres, et le REFUS
 * de suppression d'un profil porté dans les DEUX chemins UI (liste + page du
 * profil), message nommant les groupes porteurs.
 */
class GroupRightsProfileTabTest extends TestCase
{
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();
        Permission::firstOrCreate(['name' => SambaPermission::UserAssignRight->value, 'guard_name' => 'web']);
        Queue::fake();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        UserGroupUserPivotObserver::disableProfileReconcile();
    }

    protected function tearDown(): void
    {
        UserGroupUserPivotObserver::enableProfileReconcile();
        UserGroupUserPivotObserver::enableSync();
        UserGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function indexPage(): string
    {
        return 'pages::rights-management.index';
    }

    private function editProfilePage(): string
    {
        return 'pages::rights-management.profiles.[id]';
    }

    private function makeAdmin(string $login = 'rights-admin'): User
    {
        $admin = User::create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $admin->givePermissionTo(SambaPermission::UserAssignRight->value);
        $this->actingAs($admin);

        return $admin;
    }

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    private function group(string $name, ?Role $carries = null): UserGroup
    {
        return UserGroup::create([
            'name' => $name,
            'display_name' => $name,
            'type' => 'role',
            'rights_profile_id' => $carries?->id,
        ]);
    }

    private function member(string $login, UserGroup $group): User
    {
        $user = User::create(['login' => $login, 'role' => 'autre', 'is_active' => true]);
        $user->groups()->attach($group->id);

        return $user;
    }

    /** @return string[] */
    private function roleNames(User $user): array
    {
        return User::find($user->id)->roles()->pluck('name')->sort()->values()->all();
    }

    // ========================================================================
    // AC7 — les deux sections
    // ========================================================================

    #[Test]
    public function the_tab_splits_carrier_groups_from_unattached_profiles(): void
    {
        $prof = $this->role('prof');
        $this->role('user-admin'); // délégation, non portée
        $this->group('Profs', $prof);
        $this->group('3A'); // groupe non porteur : absent de la liste

        $this->makeAdmin();

        $component = Livewire::test($this->indexPage())->call('loadProfiles');

        $carriers = $component->get('carrierGroupsList');
        self::assertCount(1, $carriers);
        self::assertSame('Profs', $carriers[0]['group_name']);
        self::assertSame('prof', $carriers[0]['profile_name']);

        $unattached = array_column($component->get('unattachedProfilesList'), 'name');
        self::assertContains('user-admin', $unattached);
        self::assertNotContains('prof', $unattached, 'un profil porté sort de la section secondaire');
    }

    // ========================================================================
    // AC7 — donner des permissions à un groupe
    // ========================================================================

    #[Test]
    public function giving_permissions_to_a_group_links_it_and_reprojects_its_members(): void
    {
        $prof = $this->role('prof');
        $group = $this->group('Profs');
        $paul = $this->member('paul', $group);

        $this->makeAdmin();

        Livewire::test($this->indexPage())
            ->call('loadProfiles')
            ->call('openAssignProfileModal')
            ->assertSet('showProfileAssignModal', true)
            ->set('profileGroupSearch', 'Prof')
            ->tap(function ($c) use ($group) {
                $results = $c->get('profileGroupResults');
                self::assertSame([$group->id], array_column($results, 'id'));
            })
            ->call('selectProfileGroup', $group->id)
            ->set('profileAssignRoleId', $prof->id)
            ->call('submitProfileAssignment')
            ->assertSet('showProfileAssignModal', false);

        self::assertSame($prof->id, UserGroup::find($group->id)->rights_profile_id);
        self::assertSame(['prof'], $this->roleNames($paul));
    }

    #[Test]
    public function the_group_search_excludes_groups_that_already_carry_a_profile(): void
    {
        $prof = $this->role('prof');
        $this->group('Profs', $prof);
        $free = $this->group('Profs-vacataires');

        $this->makeAdmin();

        $component = Livewire::test($this->indexPage())
            ->set('profileGroupSearch', 'Profs');

        self::assertSame([$free->id], array_column($component->get('profileGroupResults'), 'id'));
    }

    #[Test]
    public function changing_the_profile_of_a_carrier_group_reprojects_its_members(): void
    {
        $prof = $this->role('prof');
        $eleve = $this->role('eleve');
        $group = $this->group('Profs', $prof);
        $paul = $this->member('paul', $group);
        $paul->assignRole($prof->id);

        $this->makeAdmin();

        Livewire::test($this->indexPage())
            ->call('loadProfiles')
            ->call('openChangeProfileModal', $group->id)
            ->assertSet('profileAssignMode', 'change')
            ->assertSet('profileAssignGroupId', $group->id)
            ->set('profileAssignRoleId', $eleve->id)
            ->call('submitProfileAssignment');

        self::assertSame($eleve->id, UserGroup::find($group->id)->rights_profile_id);
        self::assertSame(['eleve'], $this->roleNames($paul), 'l\'ancien profil est retiré en une passe');
    }

    #[Test]
    public function removing_the_profile_of_a_carrier_group_revokes_it_from_members(): void
    {
        $prof = $this->role('prof');
        $group = $this->group('Profs', $prof);
        $paul = $this->member('paul', $group);
        $paul->assignRole($prof->id);

        $this->makeAdmin();

        Livewire::test($this->indexPage())
            ->call('loadProfiles')
            ->call('openRemoveProfileModal', $group->id)
            ->assertSet('showProfileRemoveModal', true)
            ->assertSet('profileRemoveProfileLabel', 'prof')
            ->call('confirmRemoveProfile')
            ->assertSet('showProfileRemoveModal', false);

        self::assertNull(UserGroup::find($group->id)->rights_profile_id);
        self::assertSame([], $this->roleNames($paul));
    }

    // ========================================================================
    // AC6 — suppression refusée sur les DEUX chemins
    // ========================================================================

    #[Test]
    public function bulk_delete_refuses_a_carried_profile_and_names_the_carriers(): void
    {
        $custom = Role::create(['name' => 'gestionnaire', 'guard_name' => 'web']);
        $this->group('Comptabilite', $custom);
        $this->group('Finances', $custom);

        $this->makeAdmin();

        Livewire::test($this->indexPage())
            ->set('selectedProfiles', ['gestionnaire'])
            ->call('deleteSelectedProfiles')
            ->assertDispatched(
                'toastMagic',
                fn(string $event, array $params) => str_contains($params['message'], 'Comptabilite')
                    && str_contains($params['message'], 'Finances')
                    && str_contains($params['message'], 'refusée')
            );

        self::assertNotNull(Role::where('name', 'gestionnaire')->first());
    }

    #[Test]
    public function profile_page_delete_refuses_a_carried_profile_and_names_the_carriers(): void
    {
        $custom = Role::create(['name' => 'gestionnaire', 'guard_name' => 'web']);
        $this->group('Comptabilite', $custom);

        $this->makeAdmin();

        Livewire::test($this->editProfilePage(), ['id' => $custom->id])
            ->call('delete')
            ->assertDispatched(
                'toastMagic',
                fn(string $event, array $params) => str_contains($params['message'], 'Comptabilite')
                    && str_contains($params['message'], 'refusée')
            );

        self::assertNotNull(Role::where('name', 'gestionnaire')->first());
    }

    #[Test]
    public function an_unattached_profile_remains_deletable(): void
    {
        $custom = Role::create(['name' => 'libre', 'guard_name' => 'web']);

        $this->makeAdmin();

        Livewire::test($this->indexPage())
            ->set('selectedProfiles', ['libre'])
            ->call('deleteSelectedProfiles');

        self::assertNull(Role::where('name', 'libre')->first());
    }

    // ========================================================================
    // Gardes serveur
    // ========================================================================

    #[Test]
    public function a_user_without_the_permission_cannot_assign_a_profile_to_a_group(): void
    {
        $prof = $this->role('prof');
        $group = $this->group('Profs');

        $intruder = User::create(['login' => 'intrus', 'role' => 'autre', 'is_active' => true]);
        $this->actingAs($intruder);

        Livewire::test($this->indexPage())
            ->set('profileAssignGroupId', $group->id)
            ->set('profileAssignRoleId', $prof->id)
            ->call('submitProfileAssignment')
            ->assertStatus(403);

        self::assertNull(UserGroup::find($group->id)->rights_profile_id);
    }

    #[Test]
    public function a_user_without_the_permission_cannot_remove_a_carried_profile(): void
    {
        $prof = $this->role('prof');
        $group = $this->group('Profs', $prof);

        $intruder = User::create(['login' => 'intrus', 'role' => 'autre', 'is_active' => true]);
        $this->actingAs($intruder);

        Livewire::test($this->indexPage())
            ->set('profileRemoveGroupId', $group->id)
            ->call('confirmRemoveProfile')
            ->assertStatus(403);

        self::assertSame($prof->id, UserGroup::find($group->id)->rights_profile_id);
    }
}
