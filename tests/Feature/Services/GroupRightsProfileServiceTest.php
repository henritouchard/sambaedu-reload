<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\GroupRightsProfileService;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 49.1 (AC2, AC3, AC4, AC5, AC10, AC11) — réconciliation des profils de
 * droits portés par les groupes.
 *
 * Couvre les 5 scénarios d'AC11 + le technicien fédéré (AC3) + le compte
 * protégé (AC10) + le no-op de `setProfile` + le piège du DERNIER PORTEUR (AC4)
 * + le re-run `reprojectAll` sans écriture + le **test-verrou anti-`syncRoles`**.
 *
 * L'observer pivot est SUSPENDU dans ce fichier (`disableProfileReconcile()`) :
 * on teste le service en isolation. Le câblage observer a son propre fichier
 * (`UserGroupUserPivotProfileReconcileTest`).
 */
class GroupRightsProfileServiceTest extends TestCase
{
    use CreatesPermissionSchema;

    private GroupRightsProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPermissionSchema();
        Queue::fake();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        UserGroupUserPivotObserver::disableProfileReconcile();

        $this->service = app(GroupRightsProfileService::class);
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

        // `source` n'est délibérément PAS `fillable` (Epic 20 : l'origine d'un
        // compte n'est jamais mass-assignée) — on la pose explicitement.
        $user->source = $source;
        $user->save();

        return $user;
    }

    private function group(string $name, ?Role $carries = null, string $type = 'role'): UserGroup
    {
        return UserGroup::create([
            'name' => $name,
            'display_name' => $name,
            'type' => $type,
            'rights_profile_id' => $carries?->id,
        ]);
    }

    /** @return string[] */
    private function roleNames(User $user): array
    {
        return User::find($user->id)->roles()->pluck('name')->sort()->values()->all();
    }

    // ========================================================================
    // AC11-1 — appartenance ⇒ profil ; sortie ⇒ retrait
    // ========================================================================

    #[Test]
    public function joining_a_carrier_group_grants_the_profile_and_leaving_removes_it(): void
    {
        $prof = $this->role('prof');
        $profs = $this->group('Profs', $prof);
        $alice = $this->user('alice');

        $alice->groups()->attach($profs->id);
        $this->service->reconcile($alice);

        self::assertSame(['prof'], $this->roleNames($alice));

        $alice->groups()->detach($profs->id);
        $this->service->reconcile($alice);

        self::assertSame([], $this->roleNames($alice));
    }

    // ========================================================================
    // AC11-2 / NFR-R2 — LE TEST-VERROU : `syncRoles` est interdit
    // ========================================================================

    /**
     * Ce test échouerait si quelqu'un « simplifiait » la réconciliation en
     * `syncRoles` : la délégation manuelle serait détruite. C'est le sinistre
     * central que la story prévient.
     */
    #[Test]
    public function manual_delegations_survive_reconciliation_forever(): void
    {
        $prof = $this->role('prof');
        $userAdmin = $this->role('user-admin'); // porté par AUCUN groupe = délégation
        $profs = $this->group('Profs', $prof);

        $bob = $this->user('bob');
        $bob->groups()->attach($profs->id);
        $bob->assignRole($userAdmin->id); // geste du drawer

        for ($i = 0; $i < 3; $i++) {
            $this->service->reconcile($bob);
        }

        self::assertSame(['prof', 'user-admin'], $this->roleNames($bob));

        // Retrait puis ré-ajout au groupe porteur : la délégation reste.
        $bob->groups()->detach($profs->id);
        $this->service->reconcile($bob);
        self::assertSame(['user-admin'], $this->roleNames($bob));

        $bob->groups()->attach($profs->id);
        $this->service->reconcile($bob);
        self::assertSame(['prof', 'user-admin'], $this->roleNames($bob));
    }

    /**
     * Verrou statique complémentaire : le service ne doit contenir AUCUN appel
     * à `syncRoles`. Un futur refactor « simplificateur » se heurte ici.
     */
    #[Test]
    public function the_service_source_contains_no_syncRoles_call(): void
    {
        $source = file_get_contents(app_path('Services/GroupRightsProfileService.php'));

        self::assertDoesNotMatchRegularExpression(
            '/->\s*syncRoles\s*\(/',
            $source,
            'syncRoles est INTERDIT dans le chemin de matérialisation (NFR-R2) : '
            . 'il effacerait toutes les délégations manuelles du parc.'
        );
    }

    // ========================================================================
    // AC11-3 / AC4 — changement de profil porté, y compris DERNIER PORTEUR
    // ========================================================================

    #[Test]
    public function changing_the_carried_profile_removes_the_former_one_from_all_members(): void
    {
        $prof = $this->role('prof');
        $eleve = $this->role('eleve');
        $group = $this->group('Equipe', $prof);

        $members = collect(['m1', 'm2', 'm3'])->map(function (string $login) use ($group) {
            $u = $this->user($login);
            $u->groups()->attach($group->id);
            $u->assignRole($group->rights_profile_id);
            return $u;
        });

        // `Equipe` est le DERNIER (et seul) porteur de `prof` : après le
        // changement, `prof` sort de l'ensemble « portés » — sans le paramètre
        // `extraRevocableRoleIds`, il resterait orphelin sur tous les membres.
        $stats = $this->service->setProfile($group->fresh(), $eleve->id);

        self::assertTrue($stats['changed']);
        self::assertSame(3, $stats['users']);

        foreach ($members as $member) {
            self::assertSame(['eleve'], $this->roleNames($member), "membre {$member->login}");
        }
    }

    #[Test]
    public function removing_the_carried_profile_revokes_it_from_all_members(): void
    {
        $prof = $this->role('prof');
        $group = $this->group('Profs', $prof);

        $u = $this->user('carla');
        $u->groups()->attach($group->id);
        $u->assignRole($prof->id);

        $this->service->setProfile($group->fresh(), null);

        self::assertSame([], $this->roleNames($u));
        self::assertNull(UserGroup::find($group->id)->rights_profile_id);
    }

    #[Test]
    public function a_profile_still_carried_by_another_group_is_kept_by_members_of_that_group(): void
    {
        $prof = $this->role('prof');
        $eleve = $this->role('eleve');

        $g1 = $this->group('Profs', $prof);
        $g2 = $this->group('Vacataires', $prof);

        $onlyG1 = $this->user('only-g1');
        $onlyG1->groups()->attach($g1->id);
        $onlyG1->assignRole($prof->id);

        $bothGroups = $this->user('both');
        $bothGroups->groups()->attach([$g1->id, $g2->id]);
        $bothGroups->assignRole($prof->id);

        // g1 change de profil : `prof` reste porté par g2.
        $this->service->setProfile($g1->fresh(), $eleve->id);

        self::assertSame(['eleve'], $this->roleNames($onlyG1));
        // Membre des deux : garde `prof` (via g2) et gagne `eleve` (via g1).
        self::assertSame(['eleve', 'prof'], $this->roleNames($bothGroups));
    }

    #[Test]
    public function setProfile_is_a_clean_no_op_when_the_value_is_unchanged(): void
    {
        $prof = $this->role('prof');
        $group = $this->group('Profs', $prof);

        $stats = $this->service->setProfile($group->fresh(), $prof->id);

        self::assertFalse($stats['changed']);
        self::assertSame(0, $stats['users']);
    }

    #[Test]
    public function setProfile_rejects_an_unknown_profile_id(): void
    {
        $group = $this->group('Profs');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->setProfile($group, 999999);
    }

    // ========================================================================
    // AC5 / AC11-4 — cumul pur, aucune précédence
    // ========================================================================

    #[Test]
    public function membership_in_two_carrier_groups_cumulates_both_profiles(): void
    {
        $a = $this->role('profil-a');
        $b = $this->role('profil-b');
        Permission::firstOrCreate(['name' => 'user.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'computer.view', 'guard_name' => 'web']);
        $a->givePermissionTo('user.read');
        $b->givePermissionTo('computer.view');

        $ga = $this->group('Groupe-A', $a);
        $gb = $this->group('Groupe-B', $b);

        $u = $this->user('cumul');
        $u->groups()->attach([$ga->id, $gb->id]);
        $this->service->reconcile($u);

        self::assertSame(['profil-a', 'profil-b'], $this->roleNames($u));

        $fresh = User::find($u->id);
        self::assertTrue($fresh->can('user.read'));
        self::assertTrue($fresh->can('computer.view'));

        // En quitter un ne retire que le profil concerné.
        $u->groups()->detach($ga->id);
        $this->service->reconcile($u);
        self::assertSame(['profil-b'], $this->roleNames($u));
    }

    // ========================================================================
    // AC11-5 — GÉNÉRICITÉ : aucun littéral scolaire câblé
    // ========================================================================

    /**
     * Jeu de groupes NON scolaire (zéro occurrence de `prof`/`eleve`) : le
     * comportement est strictement identique. C'est le test qui prouve que rien
     * de scolaire n'est câblé dans le mécanisme.
     */
    #[Test]
    public function a_non_school_group_set_behaves_identically(): void
    {
        $gestionnaire = $this->role('gestionnaire');
        $compta = $this->group('Comptabilite', $gestionnaire, type: 'function');

        $u = $this->user('agent-compta');
        $u->groups()->attach($compta->id);
        $this->service->reconcile($u);

        self::assertSame(['gestionnaire'], $this->roleNames($u));

        $u->groups()->detach($compta->id);
        $this->service->reconcile($u);
        self::assertSame([], $this->roleNames($u));

        // Aucun rôle `prof`/`eleve` n'a été créé au passage.
        self::assertNull(Role::where('name', 'prof')->first());
        self::assertNull(Role::where('name', 'eleve')->first());
    }

    // ========================================================================
    // AC3 — périmètre borné : fédérés hors-jeu
    // ========================================================================

    #[Test]
    public function a_federated_technician_keeps_its_role_through_a_full_reprojection(): void
    {
        $technicien = $this->role('technicien');
        // Un groupe porte `technicien` — sans la borne `source='ad'`, la
        // re-projection le retirerait au technicien externe (qui n'appartient à
        // aucun groupe), et son `syncRoles([$role])` de login le re-poserait :
        // boucle retrait/re-pose à chaque connexion.
        $this->group('Techniciens', $technicien);

        $externe = $this->user('ext-tech', source: 'federated');
        $externe->assignRole($technicien->id);

        $stats = $this->service->reprojectAll();

        self::assertSame(['technicien'], $this->roleNames($externe));
        self::assertGreaterThanOrEqual(0, $stats['users']);
        // Le fédéré n'est même pas parcouru (borne SQL `source='ad'`).
        self::assertSame(0, $stats['removed']);
    }

    #[Test]
    public function reconcile_skips_federated_accounts(): void
    {
        $prof = $this->role('prof');
        $group = $this->group('Profs', $prof);

        $externe = $this->user('ext-2', source: 'federated');
        $externe->groups()->attach($group->id);

        $result = $this->service->reconcile($externe);

        self::assertTrue($result['skipped']);
        self::assertSame([], $this->roleNames($externe));
    }

    // ========================================================================
    // AC10 — compte protégé `admin` intouché
    // ========================================================================

    #[Test]
    public function the_protected_admin_account_is_never_touched(): void
    {
        $prof = $this->role('prof');
        $superAdmin = $this->role('super-admin');
        $group = $this->group('Profs', $prof);

        $admin = $this->user(User::PROTECTED_ADMIN_LOGIN);
        $admin->assignRole($superAdmin->id);
        $admin->groups()->attach($group->id);

        // Ni assign (il est membre d'un groupe porteur) ni remove : skip TOTAL,
        // AVANT tout appel — son `removeRole` lève ProtectedAdminRightsException.
        $result = $this->service->reconcile($admin);

        self::assertTrue($result['skipped']);
        self::assertSame(['super-admin'], $this->roleNames($admin));

        $this->service->reprojectAll();
        self::assertSame(['super-admin'], $this->roleNames($admin));
    }

    // ========================================================================
    // AC4 — re-projection idempotente + fail-soft
    // ========================================================================

    #[Test]
    public function reprojectAll_backfills_then_is_a_strict_no_op_on_rerun(): void
    {
        $prof = $this->role('prof');
        $eleve = $this->role('eleve');
        $profs = $this->group('Profs', $prof);
        $eleves = $this->group('Eleves', $eleve);

        $p = $this->user('paul');
        $p->groups()->attach($profs->id);
        $e = $this->user('emma');
        $e->groups()->attach($eleves->id);

        $first = $this->service->reprojectAll();
        self::assertSame(2, $first['assigned']);
        self::assertSame(0, $first['removed']);
        self::assertSame(0, $first['errors']);

        $second = $this->service->reprojectAll();
        self::assertSame(0, $second['assigned'], 'la re-projection doit être idempotente');
        self::assertSame(0, $second['removed']);
        self::assertSame(0, $second['errors']);

        self::assertSame(['prof'], $this->roleNames($p));
        self::assertSame(['eleve'], $this->roleNames($e));
    }

    #[Test]
    public function reprojectAll_removes_a_carried_role_no_longer_justified(): void
    {
        $prof = $this->role('prof');
        $this->group('Profs', $prof);

        // Rôle porté détenu SANS appartenance (résidu d'un write pivot brut).
        $orphan = $this->user('orphan');
        $orphan->assignRole($prof->id);

        $stats = $this->service->reprojectAll();

        self::assertSame(1, $stats['removed']);
        self::assertSame([], $this->roleNames($orphan));
    }

    #[Test]
    public function reprojectAll_dry_run_writes_nothing(): void
    {
        $prof = $this->role('prof');
        $profs = $this->group('Profs', $prof);
        $u = $this->user('dry');
        $u->groups()->attach($profs->id);

        $stats = $this->service->reprojectAll(dryRun: true);

        self::assertSame(1, $stats['assigned']);
        self::assertSame([], $this->roleNames($u), 'le dry-run ne doit RIEN écrire');
    }

    /**
     * Fail-soft (NFR-R3) : une erreur sur un utilisateur n'arrête pas la boucle
     * et n'empêche pas les suivants d'être traités.
     */
    #[Test]
    public function an_error_on_one_user_does_not_stop_the_loop(): void
    {
        $prof = $this->role('prof');
        $profs = $this->group('Profs', $prof);

        $broken = $this->user('aaa-broken');
        $broken->groups()->attach($profs->id);
        $healthy = $this->user('zzz-healthy');
        $healthy->groups()->attach($profs->id);

        // Le rôle est supprimé de la table `roles` APRÈS avoir été référencé :
        // `assignRole($id)` lèvera RoleDoesNotExist pour les deux users. On
        // fabrique donc l'erreur autrement : un id de rôle inexistant injecté
        // dans le lien du groupe.
        \Illuminate\Support\Facades\DB::table('user_groups')
            ->where('id', $profs->id)
            ->update(['rights_profile_id' => 424242]);

        $stats = $this->service->reprojectAll();

        self::assertSame(2, $stats['users'], 'les deux users doivent être parcourus');
        self::assertSame(2, $stats['errors']);
        self::assertSame(0, $stats['assigned']);
    }

    // ========================================================================
    // Classification dérivée (D3)
    // ========================================================================

    #[Test]
    public function carried_role_ids_are_read_from_database_and_deduplicated(): void
    {
        $prof = $this->role('prof');
        $this->role('user-admin');
        $this->group('Profs', $prof);
        $this->group('Vacataires', $prof); // même profil, deux porteurs
        $this->group('3A'); // non porteur

        self::assertSame([$prof->id], $this->service->carriedRoleIds());

        self::assertSame(
            ['Profs', 'Vacataires'],
            $this->service->carrierNames($prof->id)
        );
    }

    #[Test]
    public function carriers_by_role_id_maps_every_carrier(): void
    {
        $prof = $this->role('prof');
        $eleve = $this->role('eleve');
        $this->group('Profs', $prof);
        $this->group('Eleves', $eleve);

        $map = $this->service->carriersByRoleId();

        self::assertSame(['Profs'], $map[$prof->id]);
        self::assertSame(['Eleves'], $map[$eleve->id]);
    }
}
