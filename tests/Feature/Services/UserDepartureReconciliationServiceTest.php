<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\GroupRightsProfileService;
use App\Services\UserDepartureReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 49.3 (AC10) — réconciliation des départs.
 *
 * Les presence sets sont INJECTÉS : aucun LDAP, aucun `DirectoryEmulator`
 * (absent de la suite). Le service est donc testable sur exactement le
 * prédicat qui compte — « cet identifiant était-il au balayage ? ».
 *
 * L'observer pivot des PROFILS reste ACTIF dans ce fichier (D5) : le détachement
 * nocturne doit produire les mêmes effets que celui du read-back 5 min. Seul le
 * canal FS (`$syncEnabled`) est coupé, et les groupes sont de type `role` — un
 * test ne touche pas au filesystem.
 */
class UserDepartureReconciliationServiceTest extends TestCase
{
    use CreatesPermissionSchema;

    private UserDepartureReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPermissionSchema();
        Queue::fake();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        config()->set('sambaedu.user_sync.reconcile.max_disable_ratio', 0.10);
        config()->set('sambaedu.user_sync.reconcile.max_disable_floor', 5);

        $this->service = app(UserDepartureReconciliationService::class);
    }

    protected function tearDown(): void
    {
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

    private function user(string $login, array $attributes = [], string $source = 'ad'): User
    {
        $user = User::create(array_merge([
            'login' => $login,
            'role' => 'eleve',
            'is_active' => true,
        ], $attributes));

        // `source` n'est délibérément PAS `fillable` (Epic 20).
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

    /**
     * Santé d'un balayage SAIN (celui d'AC3 qui autorise la passe).
     *
     * @param string[] $logins
     * @param string[] $guids
     */
    private function healthyRun(array $logins, array $guids = [], bool $dryRun = false, bool $force = false): array
    {
        return $this->service->run(
            presence: ['present_guids' => $guids, 'present_logins' => $logins],
            health: ['fetch_failed' => false, 'fetch_groups_failed' => 0, 'main_groups_found' => 3],
            dryRun: $dryRun,
            force: $force,
        );
    }

    // ========================================================================
    // AC10-1 — Départ : soft-disable + detach + profil porté retiré,
    //          DÉLÉGATION MANUELLE INTACTE (NFR-R2)
    // ========================================================================

    #[Test]
    public function a_departed_user_is_disabled_detached_and_loses_only_carried_profiles(): void
    {
        $profProfile = $this->role('prof');
        $this->role('user-admin');
        $profs = $this->group('Profs', $profProfile);

        $alice = $this->user('alice', ['role' => 'prof', 'ad_guid' => 'aaaaaaaa-1111-2222-3333-444444444444']);
        $alice->groups()->attach($profs->id);
        $alice->assignRole('prof');
        // Délégation MANUELLE : portée par AUCUN groupe.
        $alice->assignRole('user-admin');

        // Un collègue toujours présent — la passe ne doit pas le toucher.
        $bob = $this->user('bob', ['role' => 'prof']);
        $bob->groups()->attach($profs->id);
        $bob->assignRole('prof');

        $stats = $this->healthyRun(['bob']);

        self::assertFalse($stats['guard_aborted'], 'Balayage sain : la garde ne doit pas se déclencher.');
        self::assertSame(1, $stats['candidates']);
        self::assertSame(1, $stats['disabled']);
        self::assertSame(0, $stats['errors']);

        $alice = User::find($alice->id);
        self::assertFalse((bool) $alice->is_active);
        self::assertSame('autre', $alice->role);
        self::assertSame(0, DB::table('user_group_user')->where('user_id', $alice->id)->count());

        // Le profil PORTÉ est parti (chemin 49.1), la délégation manuelle RESTE.
        self::assertSame(['user-admin'], $this->roleNames($alice));

        // Bob intact.
        $bob = User::find($bob->id);
        self::assertTrue((bool) $bob->is_active);
        self::assertSame(['prof'], $this->roleNames($bob));
        self::assertSame(1, DB::table('user_group_user')->where('user_id', $bob->id)->count());
    }

    #[Test]
    public function absence_is_evaluated_on_both_identifiers(): void
    {
        // Ligne créée par SE5 avant sa première sync : AUCUN ad_guid. Elle est
        // présente à l'AD, mais seulement reconnaissable par son login.
        $noGuid = $this->user('sans-guid');
        // Ligne dont le login SQL diverge de la casse AD : le guid la sauve.
        $byGuid = $this->user('CaseUser', ['ad_guid' => 'bbbbbbbb-1111-2222-3333-444444444444']);

        $stats = $this->healthyRun(
            ['sans-guid', 'autre-compte'],
            ['bbbbbbbb-1111-2222-3333-444444444444'],
        );

        self::assertSame(0, $stats['candidates'], 'Aucun des deux ne doit être vu comme parti.');
        self::assertTrue((bool) User::find($noGuid->id)->is_active);
        self::assertTrue((bool) User::find($byGuid->id)->is_active);
    }

    #[Test]
    public function login_presence_is_case_insensitive(): void
    {
        $user = $this->user('Marie.Curie');

        $stats = $this->healthyRun(['marie.curie']);

        self::assertSame(0, $stats['candidates']);
        self::assertTrue((bool) User::find($user->id)->is_active);
    }

    // ========================================================================
    // AC10-2 — LA garde (NFR-R1) : « fetch en échec ⇒ AUCUNE désactivation »
    // ========================================================================

    #[Test]
    public function guard_aborts_when_the_fetch_threw(): void
    {
        $victim = $this->user('victime');

        $stats = $this->service->run(
            presence: ['present_guids' => [], 'present_logins' => []],
            health: ['fetch_failed' => true, 'fetch_groups_failed' => 0, 'main_groups_found' => 3],
        );

        self::assertTrue($stats['guard_aborted']);
        self::assertSame(UserDepartureReconciliationService::ABORT_FETCH_FAILED, $stats['guard_reason']);
        self::assertSame(0, $stats['disabled']);
        self::assertTrue((bool) User::find($victim->id)->is_active, 'Une panne AD ne doit désactiver PERSONNE.');
    }

    #[Test]
    public function guard_aborts_when_a_single_main_group_failed(): void
    {
        $victim = $this->user('victime');

        $stats = $this->service->run(
            presence: ['present_guids' => [], 'present_logins' => ['un-autre']],
            health: ['fetch_failed' => false, 'fetch_groups_failed' => 1, 'main_groups_found' => 3],
        );

        self::assertTrue($stats['guard_aborted']);
        self::assertSame(UserDepartureReconciliationService::ABORT_GROUP_FETCH_FAILED, $stats['guard_reason']);
        self::assertSame(0, $stats['disabled']);
        self::assertTrue((bool) User::find($victim->id)->is_active);
    }

    #[Test]
    public function guard_aborts_when_no_main_group_was_found(): void
    {
        $victim = $this->user('victime');

        $stats = $this->service->run(
            presence: ['present_guids' => [], 'present_logins' => ['un-autre']],
            health: ['fetch_failed' => false, 'fetch_groups_failed' => 0, 'main_groups_found' => 0],
        );

        self::assertTrue($stats['guard_aborted']);
        self::assertSame(UserDepartureReconciliationService::ABORT_NO_MAIN_GROUPS, $stats['guard_reason']);
        self::assertSame(0, $stats['disabled']);
        self::assertTrue((bool) User::find($victim->id)->is_active);
    }

    #[Test]
    public function guard_aborts_on_an_abnormally_empty_scan(): void
    {
        $victim = $this->user('victime');

        $stats = $this->healthyRun([]);

        self::assertTrue($stats['guard_aborted']);
        self::assertSame(UserDepartureReconciliationService::ABORT_EMPTY_RESULT, $stats['guard_reason']);
        self::assertSame(0, $stats['disabled']);
        self::assertTrue((bool) User::find($victim->id)->is_active);
    }

    #[Test]
    public function an_empty_scan_on_an_empty_base_is_not_an_abort(): void
    {
        // Aucun actif en base : rien d'anormal à ce que le balayage soit vide.
        $stats = $this->healthyRun([]);

        self::assertFalse($stats['guard_aborted']);
        self::assertSame(0, $stats['candidates']);
    }

    #[Test]
    public function guard_aborts_above_the_mass_disable_threshold(): void
    {
        config()->set('sambaedu.user_sync.reconcile.max_disable_floor', 1);

        $a = $this->user('parti-1');
        $b = $this->user('parti-2');
        $this->user('reste');

        // base = 3 → seuil = max(ceil(0.1 × 3), 1) = 1 ; 2 candidats > 1.
        $stats = $this->healthyRun(['reste']);

        self::assertTrue($stats['guard_aborted']);
        self::assertSame(UserDepartureReconciliationService::ABORT_THRESHOLD, $stats['guard_reason']);
        self::assertSame(2, $stats['candidates']);
        self::assertSame(0, $stats['disabled']);
        self::assertTrue((bool) User::find($a->id)->is_active);
        self::assertTrue((bool) User::find($b->id)->is_active);
    }

    #[Test]
    public function force_lifts_the_threshold_only(): void
    {
        config()->set('sambaedu.user_sync.reconcile.max_disable_floor', 1);

        $a = $this->user('parti-1');
        $b = $this->user('parti-2');
        $this->user('reste');

        $stats = $this->healthyRun(['reste'], force: true);

        self::assertFalse($stats['guard_aborted'], '--force lève le seuil.');
        self::assertSame(2, $stats['disabled']);
        self::assertFalse((bool) User::find($a->id)->is_active);
        self::assertFalse((bool) User::find($b->id)->is_active);
    }

    #[Test]
    public function force_never_lifts_the_health_guards(): void
    {
        $victims = [
            $this->user('victime-1'),
            $this->user('victime-2'),
        ];

        foreach (
            [
                ['fetch_failed' => true, 'fetch_groups_failed' => 0, 'main_groups_found' => 3],
                ['fetch_failed' => false, 'fetch_groups_failed' => 2, 'main_groups_found' => 3],
                ['fetch_failed' => false, 'fetch_groups_failed' => 0, 'main_groups_found' => 0],
            ] as $health
        ) {
            $stats = $this->service->run(
                presence: ['present_guids' => [], 'present_logins' => ['un-autre']],
                health: $health,
                force: true,
            );

            self::assertTrue($stats['guard_aborted'], 'Une panne AD reste infranchissable, même avec --force.');
            self::assertSame(0, $stats['disabled']);
        }

        // Le vide anormal aussi (presence vide + actifs en base).
        $stats = $this->healthyRun([], force: true);
        self::assertTrue($stats['guard_aborted']);
        self::assertSame(UserDepartureReconciliationService::ABORT_EMPTY_RESULT, $stats['guard_reason']);

        foreach ($victims as $victim) {
            self::assertTrue((bool) User::find($victim->id)->is_active);
        }
    }

    #[Test]
    public function the_threshold_combines_ratio_and_floor(): void
    {
        config()->set('sambaedu.user_sync.reconcile.max_disable_ratio', 0.10);
        config()->set('sambaedu.user_sync.reconcile.max_disable_floor', 5);

        // Petit parc : le plancher domine (sans lui, 2 départs bloqueraient).
        self::assertSame(5, $this->service->disableThreshold(10));
        // Gros parc : le ratio domine.
        self::assertSame(200, $this->service->disableThreshold(2000));
        // Arrondi supérieur.
        self::assertSame(6, $this->service->disableThreshold(51));
    }

    // ========================================================================
    // AC10-3 — Re-run = no-op
    // ========================================================================

    #[Test]
    public function a_second_identical_pass_is_a_no_op(): void
    {
        $profProfile = $this->role('prof');
        $profs = $this->group('Profs', $profProfile);

        $alice = $this->user('alice', ['role' => 'prof']);
        $alice->groups()->attach($profs->id);
        $alice->assignRole('prof');
        $this->user('bob');

        $first = $this->healthyRun(['bob']);
        self::assertSame(1, $first['disabled']);

        $second = $this->healthyRun(['bob']);

        self::assertFalse($second['guard_aborted']);
        self::assertSame(0, $second['candidates'], 'Les absents sont déjà inactifs : plus aucun candidat.');
        self::assertSame(0, $second['disabled']);
        self::assertSame(0, $second['errors']);
    }

    // ========================================================================
    // AC10-5 — Compte désactivé À LA MAIN : présent au balayage, pas un départ
    // ========================================================================

    #[Test]
    public function a_manually_disabled_but_still_present_account_is_never_a_departure(): void
    {
        $profProfile = $this->role('prof');
        $profs = $this->group('Profs', $profProfile);

        $disabled = $this->user('desactive-main', ['role' => 'prof', 'is_active' => false]);
        $disabled->groups()->attach($profs->id);
        $disabled->assignRole('prof');
        $this->user('present');

        $stats = $this->healthyRun(['desactive-main', 'present']);

        self::assertSame(0, $stats['candidates']);
        self::assertSame(
            1,
            DB::table('user_group_user')->where('user_id', $disabled->id)->count(),
            'Un compte désactivé à la main garde ses appartenances : ce n\'est pas un départ.'
        );
        self::assertSame(['prof'], $this->roleNames($disabled));
    }

    // ========================================================================
    // AC10-6 — Exclusions du périmètre
    // ========================================================================

    #[Test]
    public function protected_admin_system_accounts_and_federated_users_are_never_candidates(): void
    {
        $admin = $this->user('admin', ['role' => 'admin']);
        $system = $this->user('se4install');
        $prefixed = $this->user('api-agent');
        $federated = $this->user('technicien.ext', [], source: 'federated');
        $this->user('present');

        // Trois passes : la ligne fédérée est absente de TOUT balayage AD.
        for ($i = 0; $i < 3; $i++) {
            $stats = $this->healthyRun(['present']);
            self::assertFalse($stats['guard_aborted']);
            self::assertSame(0, $stats['candidates']);
            self::assertSame(0, $stats['disabled']);
        }

        foreach ([$admin, $system, $prefixed, $federated] as $untouched) {
            $fresh = User::find($untouched->id);
            self::assertTrue((bool) $fresh->is_active, "{$fresh->login} ne doit jamais être désactivé.");
            self::assertSame(
                $untouched->role,
                $fresh->role,
                "{$fresh->login} ne doit jamais être rétrogradé."
            );
        }

        self::assertSame(4, $stats['skipped'], 'Les 4 comptes hors périmètre sont comptés au rapport.');
    }

    // ========================================================================
    // AC10-7 — `--dry-run` : base inchangée bit à bit
    // ========================================================================

    #[Test]
    public function dry_run_writes_absolutely_nothing(): void
    {
        $profProfile = $this->role('prof');
        $profs = $this->group('Profs', $profProfile);

        $alice = $this->user('alice', ['role' => 'prof']);
        $alice->groups()->attach($profs->id);
        $alice->assignRole('prof');
        $this->user('bob');

        $stats = $this->healthyRun(['bob'], dryRun: true);

        self::assertSame(1, $stats['candidates'], 'Le plan est bien calculé…');
        self::assertSame(0, $stats['disabled'], '…mais rien n\'est appliqué.');

        $alice = User::find($alice->id);
        self::assertTrue((bool) $alice->is_active);
        self::assertSame('prof', $alice->role);
        self::assertSame(1, DB::table('user_group_user')->where('user_id', $alice->id)->count());
        self::assertSame(['prof'], $this->roleNames($alice));
    }

    #[Test]
    public function dry_run_reports_what_the_guard_would_have_decided(): void
    {
        config()->set('sambaedu.user_sync.reconcile.max_disable_floor', 1);

        $this->user('parti-1');
        $this->user('parti-2');
        $this->user('reste');

        $stats = $this->healthyRun(['reste'], dryRun: true);

        self::assertTrue($stats['guard_aborted']);
        self::assertSame(UserDepartureReconciliationService::ABORT_THRESHOLD, $stats['guard_reason']);
        self::assertSame(1, $stats['threshold']);
    }

    // ========================================================================
    // AC10-8 — Fail-soft : une erreur n'arrête JAMAIS la boucle
    // ========================================================================

    #[Test]
    public function an_error_on_one_user_does_not_stop_the_pass(): void
    {
        $this->app->bind(GroupRightsProfileService::class, fn() => new class extends GroupRightsProfileService {
            public function reconcile(User $user, array $extraRevocableRoleIds = []): array
            {
                if ($user->login === 'boom') {
                    throw new \RuntimeException('échec simulé de réconciliation');
                }

                return parent::reconcile($user, $extraRevocableRoleIds);
            }
        });

        $boom = $this->user('boom');
        $ok = $this->user('parti-ok');
        $this->user('reste');

        $stats = $this->healthyRun(['reste']);

        self::assertFalse($stats['guard_aborted']);
        self::assertSame(2, $stats['candidates']);
        self::assertSame(1, $stats['errors'], 'L\'erreur est comptée…');
        self::assertSame(1, $stats['disabled'], '…et la boucle a continué.');

        self::assertTrue((bool) User::find($boom->id)->is_active, 'La transaction du user en erreur est annulée.');
        self::assertFalse((bool) User::find($ok->id)->is_active);
    }

    // ========================================================================
    // Corrections de review — symétrie du périmètre & retour d'un utilisateur
    // ========================================================================

    /**
     * Un compte rattaché à un AUTRE établissement est absent de CHAQUE
     * balayage (le fetch l'écarte sur `establishmentDn`) : le déclarer parti
     * serait le désactiver toutes les nuits. C'est la règle « ce que le
     * balayage ne peut pas voir ne peut pas être déclaré parti ».
     */
    #[Test]
    public function a_user_of_another_establishment_is_never_a_departure_candidate(): void
    {
        \App\Facades\SEConfig::shouldReceive('getCurrentEstablishmentCode')
            ->andReturn('0123456A');

        $itinerant = $this->user('prof.itinerant', ['school_code' => '9876543Z']);
        $local = $this->user('prof.local', ['school_code' => '0123456A']);
        $sansCode = $this->user('sans.code');

        // Balayage sain où AUCUN des trois n'est présent.
        $stats = $this->healthyRun(['un-autre-compte']);

        self::assertFalse($stats['guard_aborted']);
        self::assertTrue(
            (bool) User::find($itinerant->id)->is_active,
            'un compte d\'un autre établissement n\'est jamais candidat au départ'
        );
        self::assertFalse((bool) User::find($local->id)->is_active);
        self::assertFalse((bool) User::find($sansCode->id)->is_active);
        self::assertSame(2, $stats['candidates']);
    }

    /**
     * Sans code établissement configuré (instance mono-établissement ou
     * centrale), la notion d'« externe » n'existe pas : personne n'est exclu de
     * ce chef.
     */
    #[Test]
    public function without_a_configured_establishment_code_nobody_is_excluded_as_external(): void
    {
        \App\Facades\SEConfig::shouldReceive('getCurrentEstablishmentCode')
            ->andReturn('0');

        $avecCode = $this->user('avec.code', ['school_code' => '9876543Z']);

        $stats = $this->healthyRun(['un-autre-compte']);

        self::assertSame(1, $stats['candidates']);
        self::assertFalse((bool) User::find($avecCode->id)->is_active);
    }

    /**
     * Le RETOUR d'un utilisateur : la story délègue la re-pose des profils au
     * chemin 49.1 (ré-attachement → observer). Ce test le vérifie de bout en
     * bout plutôt que sur parole — c'est la moitié « retour » d'AC6 que la
     * review signalait comme affirmée mais non couverte ici.
     */
    #[Test]
    public function a_returning_user_recovers_the_carried_profile_through_membership(): void
    {
        $profProfile = $this->role('prof');
        $this->role('user-admin');
        $profs = $this->group('Profs', $profProfile);

        $alice = $this->user('alice', ['role' => 'prof']);
        $alice->groups()->attach($profs->id);
        $alice->assignRole('prof');
        $alice->assignRole('user-admin');

        // Départ. Le balayage doit rester NON VIDE (un balayage vide est une
        // condition d'abandon de la garde, à raison).
        $this->user('bob');
        $this->healthyRun(['bob']);
        $alice = User::find($alice->id);
        self::assertFalse((bool) $alice->is_active);
        self::assertSame(
            ['user-admin'],
            $this->roleNames($alice),
            'le départ ne retire que le profil porté'
        );

        // Retour : réactivation (miroir `is_active` de la sync) + ré-attachement
        // au groupe par le read-back — c'est ce dernier geste qui re-pose le
        // profil, via l'observer de 49.1.
        $alice->is_active = true;
        $alice->role = 'prof';
        $alice->save();
        $alice->groups()->attach($profs->id);

        self::assertSame(
            ['prof', 'user-admin'],
            $this->roleNames($alice),
            'le profil porté est re-posé par le seul ré-attachement, sans geste dédié'
        );

        // Et une passe suivante, sur un balayage où il est présent, ne le
        // retouche pas.
        $stats = $this->healthyRun(['alice', 'bob']);
        self::assertSame(0, $stats['candidates']);
        self::assertTrue((bool) User::find($alice->id)->is_active);
    }
}
