<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\SambaRole;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\PermissionSeeder;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Story 49.2 — NFR-R5 : PARITÉ D'AUTHZ.
 *
 * Le cut-over Postgres ne doit changer AUCUN droit effectif. Ce test fige, pour
 * cinq profils représentatifs, la table des décisions de Gate — celle-là même
 * qui était rendue avant la bascule. Les valeurs attendues ne sont pas dérivées
 * du code sous test : elles sont écrites en dur, profil par profil, ability par
 * ability. C'est tout l'intérêt d'un test de parité — si l'implémentation change
 * d'avis, il le dit.
 *
 * Les cinq profils sont montés comme le produit les monte réellement :
 *  - élève et prof reçoivent leur rôle par APPARTENANCE à un groupe porteur
 *    (mécanisme livré par 49.1 : le groupe porte le profil de droits), pas par
 *    un `assignRole` de complaisance ;
 *  - le délégué `user-admin` cumule (49.1 : permissions cumulatives, aucune
 *    précédence) ;
 *  - le compte protégé `admin` passe par `Gate::before` ;
 *  - le technicien fédéré est `source='federated'`, rôle `technicien`.
 */
class AuthzParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les abilities suivies. Toutes sont des `SambaPermission`, donc soumises au
     * `Gate::before` du compte protégé.
     */
    private const ABILITIES = [
        'user.read',
        'user.modify',
        'user.password.init',
        'user.assign.right',
        'server.admin',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        (new PermissionSeeder())->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // La projection AD des groupes n'a rien à voir avec la parité d'authz,
        // et il n'y a pas d'annuaire sur l'hôte de test.
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    /**
     * Attache `$user` à un groupe porteur du profil `$role` — le chemin réel
     * d'attribution depuis 49.1 (l'appartenance EST l'attribution).
     */
    private function joinGroupCarrying(User $user, SambaRole $role, string $groupName): void
    {
        $roleId = Role::where('name', $role->value)->where('guard_name', 'web')->value('id');
        $this->assertNotNull($roleId, "Le rôle {$role->value} doit être seedé");

        $group = UserGroup::create([
            'name' => $groupName,
            'display_name' => $groupName,
            'type' => 'classe',
            'rights_profile_id' => $roleId,
        ]);

        $user->userGroups()->attach($group->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, bool> décisions effectives pour les abilities suivies
     */
    private function decisionsFor(User $user): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();

        $decisions = [];
        foreach (self::ABILITIES as $ability) {
            $decisions[$ability] = Gate::forUser($user)->allows($ability);
        }

        return $decisions;
    }

    /**
     * @param array<string, bool> $expected
     */
    private function assertDecisions(array $expected, User $user, string $profile): void
    {
        $this->assertSame(
            $expected,
            $this->decisionsFor($user),
            "Parité d'authz rompue pour le profil « {$profile} »",
        );
    }

    // ========================================================================
    // Profil 1 — élève : membre d'un groupe porteur du profil `eleve`
    // ========================================================================

    #[Test]
    public function an_eleve_holds_no_permission(): void
    {
        $eleve = User::create(['login' => 'eleve.un', 'role' => 'eleve', 'is_active' => true]);
        $this->joinGroupCarrying($eleve, SambaRole::Eleve, 'Eleves-parite');

        $this->assertTrue($eleve->fresh()->hasRole(SambaRole::Eleve->value));

        $this->assertDecisions([
            'user.read' => false,
            'user.modify' => false,
            'user.password.init' => false,
            'user.assign.right' => false,
            'server.admin' => false,
        ], $eleve, 'élève');
    }

    // ========================================================================
    // Profil 2 — prof : `user.read` + `user.password.init`, rien d'autre
    // ========================================================================

    #[Test]
    public function a_prof_holds_exactly_read_and_password_init(): void
    {
        $prof = User::create(['login' => 'prof.parite', 'role' => 'prof', 'is_active' => true]);
        $this->joinGroupCarrying($prof, SambaRole::Prof, 'Profs-parite');

        $this->assertTrue($prof->fresh()->hasRole(SambaRole::Prof->value));

        $this->assertDecisions([
            'user.read' => true,
            'user.modify' => false,
            'user.password.init' => true,
            'user.assign.right' => false,
            'server.admin' => false,
        ], $prof, 'prof');
    }

    // ========================================================================
    // Profil 3 — prof + délégation `user-admin` : les droits SE CUMULENT
    // ========================================================================

    #[Test]
    public function a_prof_delegated_user_admin_cumulates_both_sets(): void
    {
        $delegue = User::create(['login' => 'prof.delegue', 'role' => 'prof', 'is_active' => true]);
        $this->joinGroupCarrying($delegue, SambaRole::Prof, 'Profs-delegue-parite');
        $delegue->assignRole(SambaRole::UserAdmin->value);

        $fresh = $delegue->fresh();
        $this->assertTrue($fresh->hasRole(SambaRole::Prof->value));
        $this->assertTrue($fresh->hasRole(SambaRole::UserAdmin->value));

        // `user-admin` apporte modify + assign.right ; `server.admin` n'est
        // porté par AUCUN rôle hors super-admin : il reste refusé. C'est le
        // point qu'un cumul mal implémenté ferait basculer.
        $this->assertDecisions([
            'user.read' => true,
            'user.modify' => true,
            'user.password.init' => true,
            'user.assign.right' => true,
            'server.admin' => false,
        ], $delegue, 'prof + délégation user-admin');
    }

    // ========================================================================
    // Profil 4 — compte protégé `admin` : tout, et rien de retirable
    // ========================================================================

    #[Test]
    public function the_protected_admin_holds_everything(): void
    {
        $admin = User::create([
            'login' => User::PROTECTED_ADMIN_LOGIN,
            'role' => 'autre',
            'is_active' => true,
        ]);

        // Aucun rôle, aucune permission en base : la couverture vient du
        // `Gate::before` d'`AuthServiceProvider`, borné aux `SambaPermission`.
        $this->assertDecisions([
            'user.read' => true,
            'user.modify' => true,
            'user.password.init' => true,
            'user.assign.right' => true,
            'server.admin' => true,
        ], $admin, 'admin protégé');
    }

    #[Test]
    public function the_protected_admin_keeps_everything_after_a_retracting_write(): void
    {
        $admin = User::create([
            'login' => User::PROTECTED_ADMIN_LOGIN,
            'role' => 'autre',
            'is_active' => true,
        ]);
        $admin->assignRole(SambaRole::SuperAdmin->value);

        // Écriture retranchante : le modèle réinjecte `super-admin`.
        $admin->syncRoles([SambaRole::Prof->value]);

        $this->assertTrue($admin->fresh()->hasRole(SambaRole::SuperAdmin->value));
        $this->assertDecisions([
            'user.read' => true,
            'user.modify' => true,
            'user.password.init' => true,
            'user.assign.right' => true,
            'server.admin' => true,
        ], $admin, 'admin protégé après syncRoles retranchant');
    }

    // ========================================================================
    // Profil 5 — technicien fédéré externe : inchangé par la bascule
    // ========================================================================

    #[Test]
    public function an_external_technician_keeps_its_role_and_permissions(): void
    {
        $tech = User::create([
            'login' => 'ext:tech-parite',
            'fullname' => 'Tech Externe',
            'role' => 'federated',
            'is_active' => true,
        ]);
        $tech->source = 'federated';
        $tech->save();
        $tech->assignRole(SambaRole::Technicien->value);

        $this->assertTrue($tech->fresh()->hasRole(SambaRole::Technicien->value));

        // Le technicien ne touche à AUCUNE permission utilisateur : son domaine
        // est le parc (`computer.*`, `wpkg.assign`).
        $this->assertDecisions([
            'user.read' => false,
            'user.modify' => false,
            'user.password.init' => false,
            'user.assign.right' => false,
            'server.admin' => false,
        ], $tech, 'technicien fédéré externe');

        $this->assertTrue(Gate::forUser($tech->fresh())->allows('computer.view'));
        $this->assertTrue(Gate::forUser($tech->fresh())->allows('computer.control'));
    }

    // ========================================================================
    // Verrou transverse
    // ========================================================================

    #[Test]
    public function a_user_without_anything_is_refused_everywhere(): void
    {
        $nobody = User::create(['login' => 'monsieur.rien', 'role' => 'autre', 'is_active' => true]);

        $this->assertDecisions([
            'user.read' => false,
            'user.modify' => false,
            'user.password.init' => false,
            'user.assign.right' => false,
            'server.admin' => false,
        ], $nobody, 'sans rôle ni permission');
    }

    #[Test]
    public function leaving_the_carrier_group_revokes_the_profile(): void
    {
        // Le corollaire de 49.1 sur lequel s'appuie tout le cut-over : si
        // l'appartenance n'était pas un miroir fidèle, supprimer les prédicats
        // scolaires aurait laissé des droits fantômes.
        $prof = User::create(['login' => 'prof.partant', 'role' => 'prof', 'is_active' => true]);
        $this->joinGroupCarrying($prof, SambaRole::Prof, 'Profs-partant-parite');

        $this->assertTrue($prof->fresh()->hasRole(SambaRole::Prof->value));

        $prof->userGroups()->detach();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($prof->fresh()->hasRole(SambaRole::Prof->value));
        $this->assertDecisions([
            'user.read' => false,
            'user.modify' => false,
            'user.password.init' => false,
            'user.assign.right' => false,
            'server.admin' => false,
        ], $prof, 'prof sorti de son groupe porteur');
    }
}
