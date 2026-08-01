<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 49.1 (AC2 / D9) — le hook « profils de droits » de l'observer pivot.
 *
 * Vérifie :
 *  - attach/detach sur un groupe PORTEUR ⇒ réconciliation effective ;
 *  - attach/detach sur un groupe NON porteur ⇒ ZÉRO écriture de rôle
 *    (early-return : le coût d'un import massif reste borné) ;
 *  - le hook est ancré AVANT le guard `$syncEnabled` : un chemin qui coupe la
 *    synchro FS (import users `persistUserGroupsToSql`, tests FS) réconcilie
 *    quand même ;
 *  - le flag DÉDIÉ `disableProfileReconcile()` est respecté ;
 *  - fail-soft : une réconciliation en échec ne casse pas l'écriture pivot.
 *
 * `$syncEnabled` est coupé par défaut ici : la synchro ShareService FS est hors
 * sujet (elle ne concerne que les groupes `type='classe'` et exige un FS réel).
 */
class UserGroupUserPivotProfileReconcileTest extends TestCase
{
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPermissionSchema();
        Queue::fake();
        UserGroupObserver::disableSync();
        // Guard FS coupé — c'est justement la configuration où la
        // réconciliation des profils DOIT continuer de tourner (D9).
        UserGroupUserPivotObserver::disableSync();
        UserGroupUserPivotObserver::enableProfileReconcile();
    }

    protected function tearDown(): void
    {
        UserGroupUserPivotObserver::enableProfileReconcile();
        UserGroupUserPivotObserver::enableSync();
        UserGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    private function user(string $login): User
    {
        return User::create(['login' => $login, 'role' => 'autre', 'is_active' => true]);
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

    /** @return string[] */
    private function roleNames(User $user): array
    {
        return User::find($user->id)->roles()->pluck('name')->sort()->values()->all();
    }

    #[Test]
    public function attaching_to_a_carrier_group_materializes_the_profile_even_when_fs_sync_is_disabled(): void
    {
        $prof = $this->role('prof');
        $profs = $this->group('Profs', $prof);
        $alice = $this->user('alice');

        self::assertFalse(UserGroupUserPivotObserver::$syncEnabled);

        $alice->groups()->attach($profs->id);

        self::assertSame(['prof'], $this->roleNames($alice));
    }

    #[Test]
    public function detaching_from_a_carrier_group_revokes_the_profile(): void
    {
        $prof = $this->role('prof');
        $profs = $this->group('Profs', $prof);
        $alice = $this->user('alice');

        $alice->groups()->attach($profs->id);
        self::assertSame(['prof'], $this->roleNames($alice));

        $alice->groups()->detach($profs->id);
        self::assertSame([], $this->roleNames($alice));
    }

    #[Test]
    public function attaching_to_a_non_carrier_group_writes_no_role_at_all(): void
    {
        $userAdmin = $this->role('user-admin'); // délégation
        $classe = $this->group('3A');           // ne porte rien

        $bob = $this->user('bob');
        $bob->assignRole($userAdmin->id);

        $before = DB::table('model_has_roles')->count();
        $bob->groups()->attach($classe->id);
        $after = DB::table('model_has_roles')->count();

        self::assertSame($before, $after, 'early-return : aucune écriture de rôle');
        self::assertSame(['user-admin'], $this->roleNames($bob));
    }

    #[Test]
    public function the_dedicated_flag_suspends_the_reconciliation(): void
    {
        $prof = $this->role('prof');
        $profs = $this->group('Profs', $prof);
        $alice = $this->user('alice');

        UserGroupUserPivotObserver::disableProfileReconcile();
        $alice->groups()->attach($profs->id);
        self::assertSame([], $this->roleNames($alice));

        // Réactivé : le filet de rattrapage reste `users:reproject-group-profiles`.
        UserGroupUserPivotObserver::enableProfileReconcile();
        app(\App\Services\GroupRightsProfileService::class)->reprojectAll();
        self::assertSame(['prof'], $this->roleNames($alice));
    }

    #[Test]
    public function a_reconciliation_failure_never_breaks_the_pivot_write(): void
    {
        $profs = $this->group('Profs');
        // Lien pointant sur un rôle inexistant : la réconciliation lèvera.
        DB::table('user_groups')->where('id', $profs->id)->update(['rights_profile_id' => 424242]);

        $alice = $this->user('alice');
        $alice->groups()->attach($profs->id);

        // L'écriture pivot a bien abouti malgré l'échec de réconciliation.
        self::assertSame(
            1,
            DB::table('user_group_user')
                ->where('user_id', $alice->id)
                ->where('user_group_id', $profs->id)
                ->count()
        );
        self::assertSame([], $this->roleNames($alice));
    }

    #[Test]
    public function a_syncWithoutDetaching_on_a_carrier_group_reconciles(): void
    {
        $prof = $this->role('prof');
        $profs = $this->group('Profs', $prof);
        $alice = $this->user('alice');

        // Chemin `persistUserGroupsToSql` (import users) : syncWithoutDetaching
        // sur la relation `groups()` avec le pivot custom.
        $alice->groups()->syncWithoutDetaching([$profs->id => ['role' => 'member']]);

        self::assertSame(['prof'], $this->roleNames($alice));
    }

    #[Test]
    public function an_edge_role_change_alone_does_not_trigger_a_reconciliation(): void
    {
        $prof = $this->role('prof');
        $profs = $this->group('Profs', $prof);
        $alice = $this->user('alice');

        $alice->groups()->attach($profs->id, ['role' => 'member']);
        self::assertSame(['prof'], $this->roleNames($alice));

        $before = DB::table('model_has_roles')->count();
        // `updated` n'est PAS ancré : un changement de rôle d'ARÊTE ne change
        // pas l'appartenance, donc pas les profils.
        $alice->groups()->updateExistingPivot($profs->id, ['role' => 'owner']);
        self::assertSame($before, DB::table('model_has_roles')->count());
        self::assertSame(['prof'], $this->roleNames($alice));
    }
}
