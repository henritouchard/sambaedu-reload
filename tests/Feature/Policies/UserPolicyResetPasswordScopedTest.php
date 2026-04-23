<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\AuthUser;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Policies\UserPolicy;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 7.2 (AC7, décisions a+b) — UserPolicy::resetPassword et view scopées classe pour Prof.
 *
 * Scoping strict (review 7.2 #6 : `eleve-admin` désormais scopé classe aussi) :
 *  - Prof + élève même classe → ✅
 *  - Prof + élève autre classe → ❌
 *  - Prof sans classe → ❌
 *  - EleveAdmin idem Prof (iso-legacy sovajon_is_admin)
 *  - UserAdmin / SuperAdmin / ReferentNumerique → ✅ global (bypass scoping)
 *  - Rôle custom avec user.password.init mais sans rôle admin global → ✅ global
 *    (scoping classe ne s'applique qu'aux rôles prof/eleve-admin stricts)
 */
class UserPolicyResetPasswordScopedTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    private UserPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->createPermissionSchema();
        (new PermissionSeeder())->run();
        $this->policy = new UserPolicy();
        UserGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeUser(string $login): User
    {
        return User::create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
    }

    private function makeClass(string $name): UserGroup
    {
        return UserGroup::create([
            'name' => $name,
            'display_name' => $name,
            'type' => 'class',
        ]);
    }

    public function test_prof_can_reset_password_of_student_in_same_class(): void
    {
        $prof = $this->makeUser('prof1');
        $student = $this->makeUser('eleve1');
        $class = $this->makeClass('3emeA');

        $prof->assignRole('prof');
        $prof->userGroups()->attach($class->id);
        $student->userGroups()->attach($class->id);

        $this->assertTrue($this->policy->resetPassword($prof, $student));
    }

    public function test_prof_cannot_reset_password_of_student_in_different_class(): void
    {
        $prof = $this->makeUser('prof2');
        $student = $this->makeUser('eleve2');
        $classA = $this->makeClass('3emeA');
        $classB = $this->makeClass('3emeB');

        $prof->assignRole('prof');
        $prof->userGroups()->attach($classA->id);
        $student->userGroups()->attach($classB->id);

        $this->assertFalse($this->policy->resetPassword($prof, $student));
    }

    public function test_prof_without_any_class_cannot_reset_password(): void
    {
        $prof = $this->makeUser('prof3');
        $student = $this->makeUser('eleve3');
        $class = $this->makeClass('3emeA');

        $prof->assignRole('prof');
        // Prof sans classe attachée.
        $student->userGroups()->attach($class->id);

        $this->assertFalse($this->policy->resetPassword($prof, $student));
    }

    public function test_useradmin_can_reset_any_user_bypassing_class_scoping(): void
    {
        $admin = $this->makeUser('admin-user');
        $student = $this->makeUser('any-student');
        $class = $this->makeClass('3emeX');

        $admin->assignRole('user-admin');
        // Admin pas dans la classe — mais a le rôle global.
        $student->userGroups()->attach($class->id);

        $this->assertTrue($this->policy->resetPassword($admin, $student));
    }

    public function test_superadmin_can_reset_any_user(): void
    {
        $admin = $this->makeUser('super');
        $student = $this->makeUser('any');

        $admin->assignRole('super-admin');

        $this->assertTrue($this->policy->resetPassword($admin, $student));
    }

    public function test_referent_numerique_can_reset_any_user(): void
    {
        $rn = $this->makeUser('rn');
        $student = $this->makeUser('any');

        $rn->assignRole('referent-numerique');

        $this->assertTrue($this->policy->resetPassword($rn, $student));
    }

    public function test_prof_multi_establishment_via_multi_classes_works(): void
    {
        // Décision (c) — test de non-régression : un Prof itinérant a plusieurs
        // classes, chacune dans un établissement distinct. Le scoping classe
        // fonctionne de la même manière : il voit les élèves des classes dont
        // il fait partie, peu importe l'établissement.
        $prof = $this->makeUser('prof-itinerant');
        $eleveEtab1 = $this->makeUser('e-etab1');
        $eleveEtab2 = $this->makeUser('e-etab2');
        $eleveEtab3 = $this->makeUser('e-etab3');
        $classEtab1 = $this->makeClass('3A-college-victor-hugo');
        $classEtab2 = $this->makeClass('5B-lycee-jean-jaures');
        $classEtab3 = $this->makeClass('2nde-sans-prof');

        $prof->assignRole('prof');
        $prof->userGroups()->attach([$classEtab1->id, $classEtab2->id]);
        $eleveEtab1->userGroups()->attach($classEtab1->id);
        $eleveEtab2->userGroups()->attach($classEtab2->id);
        $eleveEtab3->userGroups()->attach($classEtab3->id);

        $this->assertTrue($this->policy->resetPassword($prof, $eleveEtab1));
        $this->assertTrue($this->policy->resetPassword($prof, $eleveEtab2));
        $this->assertFalse($this->policy->resetPassword($prof, $eleveEtab3));
    }

    public function test_user_without_permission_is_denied(): void
    {
        $actor = $this->makeUser('noperm');
        $target = $this->makeUser('any');
        // Pas de rôle, pas de perm.

        $this->assertFalse($this->policy->resetPassword($actor, $target));
    }

    public function test_view_with_prof_role_also_scoped_by_class(): void
    {
        // Décision (b) : le Prof ne VOIT que les users de ses classes.
        $prof = $this->makeUser('prof-v');
        $eleveSameClass = $this->makeUser('e-same');
        $eleveOtherClass = $this->makeUser('e-other');
        $cA = $this->makeClass('classeA');
        $cB = $this->makeClass('classeB');

        $prof->assignRole('prof');
        $prof->userGroups()->attach($cA->id);
        $eleveSameClass->userGroups()->attach($cA->id);
        $eleveOtherClass->userGroups()->attach($cB->id);

        $this->assertTrue($this->policy->view($prof, $eleveSameClass));
        $this->assertFalse($this->policy->view($prof, $eleveOtherClass));
    }

    public function test_view_without_target_falls_back_to_global_read(): void
    {
        $prof = $this->makeUser('prof-list');
        $prof->assignRole('prof');

        // Sans target : droit global (listing users) → prof a user.read.
        $this->assertTrue($this->policy->view($prof));
    }

    public function test_reset_password_without_target_returns_true_for_permission_holders(): void
    {
        // Pour permettre de masquer/afficher un bouton générique ("peut resetter"),
        // resetPassword($actor, null) retourne true si la permission existe.
        $prof = $this->makeUser('prof-generic');
        $prof->assignRole('prof');
        $this->assertTrue($this->policy->resetPassword($prof, null));

        $nope = $this->makeUser('nope');
        $this->assertFalse($this->policy->resetPassword($nope, null));
    }

    /**
     * Correction review 7.2 #M1 — En production, `auth()->user()` retourne un
     * `AuthUser` (provider LDAP). La Policy doit résoudre le User Eloquent
     * correspondant par login pour que le scoping classe fonctionne.
     */
    public function test_policy_resolves_authuser_to_eloquent_and_applies_scoping(): void
    {
        $profEloquent = $this->makeUser('prof-authuser');
        $studentSame = $this->makeUser('eleve-same-authuser');
        $studentOther = $this->makeUser('eleve-other-authuser');
        $classA = $this->makeClass('classeA-authuser');
        $classB = $this->makeClass('classeB-authuser');

        $profEloquent->assignRole('prof');
        $profEloquent->userGroups()->attach($classA->id);
        $studentSame->userGroups()->attach($classA->id);
        $studentOther->userGroups()->attach($classB->id);

        // Simule ce que fait `LdapUserProvider::retrieveById` en prod :
        // un `AuthUser` avec le même login que l'Eloquent User.
        $authActor = new AuthUser(null, 'prof-authuser');

        // Scoping classe appliqué : OK pour même classe, KO pour autre.
        $this->assertTrue(
            $this->policy->resetPassword($authActor, $studentSame),
            'AuthUser doit être résolu vers User Eloquent et voir son scoping appliqué'
        );
        $this->assertFalse(
            $this->policy->resetPassword($authActor, $studentOther),
            'AuthUser scopé classe ne doit pas resetter un élève hors de ses classes'
        );

        // Idem pour view().
        $this->assertTrue($this->policy->view($authActor, $studentSame));
        $this->assertFalse($this->policy->view($authActor, $studentOther));
    }

    /**
     * Correction review 7.2 #M1 — Défense en profondeur : un `AuthUser` dont
     * le login n'a pas de correspondance en base Eloquent doit être refusé
     * (fail-closed), plutôt que de tomber dans la branche globale.
     */
    public function test_policy_denies_authuser_without_eloquent_counterpart(): void
    {
        $student = $this->makeUser('e-target');
        $class = $this->makeClass('c-tgt');
        $student->userGroups()->attach($class->id);

        // AuthUser sans User Eloquent correspondant → getEloquentUser() = null.
        $orphanAuth = new AuthUser(null, 'unknown-login');

        $this->assertFalse($this->policy->resetPassword($orphanAuth, $student));
        $this->assertFalse($this->policy->view($orphanAuth, $student));
    }

    /**
     * Review 7.2 #8 — defense-in-depth : le bulk reset filtre chaque cible via
     * la Policy `resetPassword`. On simule ici le filtre Gate utilisé par
     * `UserService::bulkResetPasswords` pour confirmer que le comportement
     * attendu (Prof ne filtre que ses élèves) est garanti au niveau Gate.
     */
    public function test_gate_forUser_filters_bulk_targets_by_class_scoping(): void
    {
        $prof = $this->makeUser('prof-bulk');
        $studentSame = $this->makeUser('e-bulk-same');
        $studentOther = $this->makeUser('e-bulk-other');
        $classA = $this->makeClass('c-bulk-A');
        $classB = $this->makeClass('c-bulk-B');

        $prof->assignRole('prof');
        $prof->userGroups()->attach($classA->id);
        $studentSame->userGroups()->attach($classA->id);
        $studentOther->userGroups()->attach($classB->id);

        $targets = [$studentSame, $studentOther];
        $filtered = array_values(array_filter(
            $targets,
            fn($target) => \Illuminate\Support\Facades\Gate::forUser($prof)->check('resetPassword', $target)
        ));

        $this->assertCount(1, $filtered);
        $this->assertEquals($studentSame->id, $filtered[0]->id);
    }

    /**
     * Correction review 7.2 #6 — `eleve-admin` est désormais scopé classe
     * comme `prof` (retiré de GLOBAL_USER_ROLES, ajouté à CLASS_SCOPED_ROLES).
     * Iso-legacy `sovajon_is_admin` (bits 0x07, scoping classe strict).
     */
    public function test_eleve_admin_is_class_scoped_like_prof(): void
    {
        $actor = $this->makeUser('eleve-admin-scoped');
        $studentSame = $this->makeUser('e-same-ea');
        $studentOther = $this->makeUser('e-other-ea');
        $classA = $this->makeClass('cA-ea');
        $classB = $this->makeClass('cB-ea');

        $actor->assignRole('eleve-admin');
        $actor->userGroups()->attach($classA->id);
        $studentSame->userGroups()->attach($classA->id);
        $studentOther->userGroups()->attach($classB->id);

        // Même classe → OK.
        $this->assertTrue($this->policy->resetPassword($actor, $studentSame));
        $this->assertTrue($this->policy->view($actor, $studentSame));

        // Autre classe → KO (alors qu'avant la correction #6, eleve-admin
        // était dans GLOBAL_USER_ROLES et voyait tout).
        $this->assertFalse($this->policy->resetPassword($actor, $studentOther));
        $this->assertFalse($this->policy->view($actor, $studentOther));
    }
}
