<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Repositories\GroupRepository;
use App\Repositories\RightRepository;
use App\Repositories\UserGroupRepository;
use App\Services\GroupRightsProfileService;
use App\Services\UserGroupService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 49.1 — correction de review : la DISPARITION d'un groupe porteur.
 *
 * Le cleanup du balayage AD (`UserGroupService::syncFromAd()`) supprime les
 * groupes absents de l'annuaire par un `delete()` de MASSE : aucun event
 * Eloquent n'est émis, ni sur le groupe, ni sur le pivot. Or dès que le groupe
 * disparaît, son profil sort de `carriedRoleIds()` et devient indistinguable
 * d'une délégation manuelle — la réconciliation générique ne le retire plus,
 * sur AUCUNE passe, y compris `users:reproject-group-profiles` rejouée. Sans
 * rattrapage explicite, les anciens membres gardent le profil À VIE.
 *
 * C'est le même piège que le dernier porteur (D4/AC4), et il se traite de la
 * même façon : capture AVANT la suppression, révocation explicite APRÈS.
 *
 * Ces tests verrouillent les deux moitiés : le mécanisme
 * (`reconcileOrphanedProfiles`) et son câblage réel dans le cleanup de sync.
 */
class CarrierGroupDeletionReconcileTest extends TestCase
{
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPermissionSchema();
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

    // ========================================================================
    // Helpers
    // ========================================================================

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    private function user(string $login, string $source = 'ad'): User
    {
        $user = User::create([
            'login' => $login,
            'role' => 'autre',
            'is_active' => true,
        ]);

        $user->source = $source;
        $user->save();

        return $user;
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

    private function adGroup(string $cn): array
    {
        return [
            'cn' => $cn,
            'dn' => "CN={$cn},OU=Groups,DC=example,DC=local",
            'description' => $cn,
        ];
    }

    private function makeImportService(Collection $adGroups): UserGroupService
    {
        $groupRepository = $this->createMock(GroupRepository::class);
        $groupRepository->method('getGroupsWithMemberCount')->willReturn($adGroups);
        $groupRepository->method('getGroupMembers')->willReturn(collect());

        $rightRepository = $this->createMock(RightRepository::class);
        $rightRepository->method('getAllRightsValues')->willReturn([]);

        return new UserGroupService(
            new UserGroupRepository(),
            $groupRepository,
            $rightRepository,
        );
    }

    // ========================================================================
    // Le trou, démontré sur le mécanisme générique
    // ========================================================================

    #[Test]
    public function the_generic_reconciliation_alone_can_never_revoke_an_orphaned_profile(): void
    {
        // Ce test documente POURQUOI le rattrapage explicite est nécessaire :
        // il échouerait si l'on prétendait couvrir le cas par `reprojectAll()`.
        $gestionnaire = $this->role('gestionnaire');
        $compta = $this->group('Comptabilite', $gestionnaire);
        $alice = $this->user('alice');

        $alice->groups()->attach($compta->id);
        $service = app(GroupRightsProfileService::class);
        $service->reconcile($alice);
        self::assertSame(['gestionnaire'], $this->roleNames($alice));

        // Le groupe porteur disparaît (DELETE de masse, sans events).
        UserGroup::query()->whereKey($compta->id)->delete();

        // Le filet générique, même rejoué, ne peut PAS retirer le profil :
        // il n'est plus « porté », donc plus révocable.
        $service->reprojectAll();
        $service->reprojectAll();

        self::assertSame(
            ['gestionnaire'],
            $this->roleNames($alice),
            'la réconciliation générique ne peut pas connaître un profil devenu orphelin'
        );
    }

    #[Test]
    public function reconcile_orphaned_profiles_revokes_the_profile_and_spares_delegations(): void
    {
        $gestionnaire = $this->role('gestionnaire');
        $userAdmin = $this->role('user-admin');
        $compta = $this->group('Comptabilite', $gestionnaire);

        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $alice->groups()->attach($compta->id);
        $bob->groups()->attach($compta->id);

        $service = app(GroupRightsProfileService::class);
        $service->reconcile($alice);
        $service->reconcile($bob);

        // Alice a AUSSI une délégation manuelle posée au drawer.
        $alice->assignRole($userAdmin);

        $orphanRoleIds = [$gestionnaire->id];
        $memberIds = [$alice->id, $bob->id];

        UserGroup::query()->whereKey($compta->id)->delete();

        $stats = $service->reconcileOrphanedProfiles($memberIds, $orphanRoleIds);

        self::assertSame(2, $stats['users']);
        self::assertSame(2, $stats['removed']);
        self::assertSame(0, $stats['errors']);
        self::assertSame(
            ['user-admin'],
            $this->roleNames($alice),
            'la délégation manuelle survit à la révocation du profil orphelin (NFR-R2)'
        );
        self::assertSame([], $this->roleNames($bob));
    }

    #[Test]
    public function reconcile_orphaned_profiles_never_touches_a_non_member(): void
    {
        $technicien = $this->role('technicien');
        $compta = $this->group('Comptabilite', $technicien);

        $alice = $this->user('alice');
        $alice->groups()->attach($compta->id);
        app(GroupRightsProfileService::class)->reconcile($alice);

        // Technicien fédéré : hors périmètre, membre d'aucun groupe, son rôle
        // est piloté par son login fédéré (AC3).
        $ext = $this->user('ext-technicien', 'federated');
        $ext->assignRole($technicien);

        UserGroup::query()->whereKey($compta->id)->delete();

        app(GroupRightsProfileService::class)
            ->reconcileOrphanedProfiles([$alice->id, $ext->id], [$technicien->id]);

        self::assertSame([], $this->roleNames($alice));
        self::assertSame(
            ['technicien'],
            $this->roleNames($ext),
            'un compte hors périmètre (source != ad) n\'est jamais réconcilié'
        );
    }

    #[Test]
    public function reconcile_orphaned_profiles_is_a_no_op_without_orphans(): void
    {
        $prof = $this->role('prof');
        $profs = $this->group('Profs', $prof);
        $alice = $this->user('alice');
        $alice->groups()->attach($profs->id);
        app(GroupRightsProfileService::class)->reconcile($alice);

        $stats = app(GroupRightsProfileService::class)->reconcileOrphanedProfiles([$alice->id], []);

        self::assertSame(['users' => 0, 'assigned' => 0, 'removed' => 0, 'errors' => 0], $stats);
        self::assertSame(['prof'], $this->roleNames($alice));
    }

    // ========================================================================
    // Le câblage réel : le cleanup du balayage AD
    // ========================================================================

    #[Test]
    public function the_ad_sweep_cleanup_revokes_the_profile_of_a_deleted_carrier_group(): void
    {
        $gestionnaire = $this->role('gestionnaire');
        $compta = $this->group('Comptabilite', $gestionnaire);

        $alice = $this->user('alice');
        $alice->groups()->attach($compta->id);
        app(GroupRightsProfileService::class)->reconcile($alice);
        self::assertSame(['gestionnaire'], $this->roleNames($alice));

        // L'AD ne renvoie plus `Comptabilite` : le cleanup supprime la ligne.
        $this->makeImportService(collect([$this->adGroup('RH')]))->syncFromAd();

        self::assertNull(UserGroup::find($compta->id), 'le groupe disparu doit être supprimé');
        self::assertSame(
            [],
            $this->roleNames($alice),
            'le profil du groupe porteur supprimé doit être retiré à ses anciens membres'
        );
    }

    #[Test]
    public function the_ad_sweep_cleanup_spares_delegations_of_former_members(): void
    {
        $gestionnaire = $this->role('gestionnaire');
        $userAdmin = $this->role('user-admin');
        $compta = $this->group('Comptabilite', $gestionnaire);

        $alice = $this->user('alice');
        $alice->groups()->attach($compta->id);
        app(GroupRightsProfileService::class)->reconcile($alice);
        $alice->assignRole($userAdmin);

        $this->makeImportService(collect([$this->adGroup('RH')]))->syncFromAd();

        self::assertSame(
            ['user-admin'],
            $this->roleNames($alice),
            'la délégation manuelle n\'est jamais emportée par le cleanup'
        );
    }

    #[Test]
    public function the_ad_sweep_cleanup_leaves_surviving_carriers_alone(): void
    {
        $prof = $this->role('prof');
        $profs = $this->group('Profs', $prof);

        $alice = $this->user('alice');
        $alice->groups()->attach($profs->id);
        app(GroupRightsProfileService::class)->reconcile($alice);

        // `Profs` est toujours dans l'AD : rien ne doit bouger.
        $this->makeImportService(collect([$this->adGroup('Profs')]))->syncFromAd();

        self::assertNotNull(UserGroup::find($profs->id));
        self::assertSame(['prof'], $this->roleNames($alice));
    }
}
