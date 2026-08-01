<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SambaPermission;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 49.1 (AC8 / D8) — verrouillage des DEUX drawers de droits pour les
 * profils PORTÉS par un groupe.
 *
 * Le `disabled` de l'UI seul serait du théâtre : un payload Livewire forgé
 * écrirait, puis la réconciliation (≤ 5 min de sync delta) ferait un retrait
 * silencieux — exactement le sinistre que l'AC prévient. La garde est donc
 * aussi SERVEUR, et SYMÉTRIQUE (assign ET remove).
 *
 * Les rôles non portés (délégations) doivent continuer à se comporter
 * exactement comme avant.
 */
class CarriedProfileDrawerLockTest extends TestCase
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
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        UserGroupUserPivotObserver::disableProfileReconcile();

        Gate::define('manage-rights', fn(?User $user) => true);
    }

    protected function tearDown(): void
    {
        UserGroupUserPivotObserver::enableProfileReconcile();
        UserGroupUserPivotObserver::enableSync();
        UserGroupObserver::enableSync();
        WorkstationGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function bulkDrawer(): string
    {
        return 'pages::users._partials.rights-drawer';
    }

    private function userDrawer(): string
    {
        return 'components::organisms.rights-drawer';
    }

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    private function carrierGroup(string $name, Role $role): UserGroup
    {
        return UserGroup::create([
            'name' => $name,
            'display_name' => $name,
            'type' => 'role',
            'rights_profile_id' => $role->id,
        ]);
    }

    private function makeUser(string $login): User
    {
        return User::create(['login' => $login, 'role' => 'autre', 'is_active' => true]);
    }

    private function actingAsRightsManager(): User
    {
        $actor = $this->makeUser('rights-manager');
        $actor->givePermissionTo(SambaPermission::UserAssignRight->value);
        $this->actingAs($actor);

        return $actor;
    }

    /** @return string[] */
    private function roleNames(User $user): array
    {
        return User::find($user->id)->roles()->pluck('name')->sort()->values()->all();
    }

    // ========================================================================
    // Drawer BULK (pages/users/_partials/rights-drawer)
    // ========================================================================

    #[Test]
    public function bulk_drawer_exposes_the_carrier_groups_of_each_role(): void
    {
        $prof = $this->role('prof');
        $this->role('user-admin');
        $this->carrierGroup('Profs', $prof);

        $this->actingAsRightsManager();
        $target = $this->makeUser('cible');

        $component = Livewire::test($this->bulkDrawer())
            ->call('open', [$target->login]);

        $roles = collect($component->get('availableRoles'))->keyBy('name');
        self::assertSame(['Profs'], $roles['prof']['carried_by']);
        self::assertSame([], $roles['user-admin']['carried_by']);

        $states = $component->get('roleStates');
        self::assertSame(['Profs'], $states['prof']['carried_by']);
        self::assertSame([], $states['user-admin']['carried_by']);
    }

    #[Test]
    public function bulk_drawer_refuses_to_assign_a_carried_profile(): void
    {
        $prof = $this->role('prof');
        $this->carrierGroup('Profs', $prof);

        $this->actingAsRightsManager();
        $target = $this->makeUser('cible');

        Livewire::test($this->bulkDrawer())
            ->call('open', [$target->login])
            ->set('selectedRole', 'prof')
            ->call('applyRoles')
            ->assertDispatched(
                'toastMagic',
                fn(string $event, array $params) => str_contains($params['message'], 'Profs')
                    && str_contains($params['message'], 'ajoutez l\'utilisateur au groupe')
            );

        self::assertSame([], $this->roleNames($target), 'aucune écriture ne doit avoir lieu');
    }

    #[Test]
    public function bulk_drawer_refuses_to_remove_a_carried_profile(): void
    {
        $prof = $this->role('prof');
        $this->carrierGroup('Profs', $prof);

        $this->actingAsRightsManager();
        $target = $this->makeUser('cible');
        $target->assignRole($prof->id);

        // Payload forgé : le contrôle est `disabled` côté UI.
        Livewire::test($this->bulkDrawer())
            ->call('open', [$target->login])
            ->set('selectedRole', 'prof')
            ->set('removeRole', true)
            ->call('applyRoles');

        self::assertSame(
            ['prof'],
            $this->roleNames($target),
            'le retrait aussi est bloqué : décocher un profil porté serait re-posé'
        );
    }

    #[Test]
    public function bulk_drawer_still_assigns_an_unattached_profile(): void
    {
        $userAdmin = $this->role('user-admin'); // porté par aucun groupe

        $this->actingAsRightsManager();
        $target = $this->makeUser('cible');

        Livewire::test($this->bulkDrawer())
            ->call('open', [$target->login])
            ->set('selectedRole', 'user-admin')
            ->call('applyRoles');

        self::assertSame(['user-admin'], $this->roleNames($target));
    }

    // ========================================================================
    // Drawer ORGANISME (components/organisms/rights-drawer)
    // ========================================================================

    #[Test]
    public function user_drawer_exposes_the_carrier_groups_and_ignores_the_toggle(): void
    {
        $prof = $this->role('prof');
        $this->carrierGroup('Profs', $prof);

        $target = $this->makeUser('cible');
        $this->actingAsRightsManager();

        $component = Livewire::test($this->userDrawer())
            ->call('open', $target->login);

        self::assertSame(['Profs'], $component->get('rolesMeta')['prof']['carried_by']);

        $before = $component->get('rolesState');
        $component->call('toggleRole', 'prof');

        self::assertSame($before, $component->get('rolesState'), 'le toggle est inopérant sur un profil porté');
    }

    #[Test]
    public function user_drawer_refuses_a_forged_assignment_of_a_carried_profile(): void
    {
        $prof = $this->role('prof');
        $this->carrierGroup('Profs', $prof);

        $target = $this->makeUser('cible');
        $this->actingAsRightsManager();

        Livewire::test($this->userDrawer())
            ->call('open', $target->login)
            ->set('rolesState', ['prof' => true])
            ->call('saveChanges');

        self::assertSame([], $this->roleNames($target));
    }

    #[Test]
    public function user_drawer_refuses_a_forged_removal_of_a_carried_profile(): void
    {
        $prof = $this->role('prof');
        $this->carrierGroup('Profs', $prof);

        $target = $this->makeUser('cible');
        $target->assignRole($prof->id);
        $this->actingAsRightsManager();

        Livewire::test($this->userDrawer())
            ->call('open', $target->login)
            ->set('rolesState', ['prof' => false])
            ->call('saveChanges');

        self::assertSame(['prof'], $this->roleNames($target));
    }

    #[Test]
    public function user_drawer_still_toggles_an_unattached_profile(): void
    {
        $this->role('user-admin');

        $target = $this->makeUser('cible');
        $this->actingAsRightsManager();

        Livewire::test($this->userDrawer())
            ->call('open', $target->login)
            ->call('toggleRole', 'user-admin')
            ->call('saveChanges');

        self::assertSame(['user-admin'], $this->roleNames($target));
    }
}
