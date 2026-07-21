<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Exceptions\ProtectedAdminRightsException;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Verrou du compte d'administration protégé.
 *
 * Invariant : le compte `admin` porte l'intégralité des droits déclarés dans le
 * code et aucun ne peut lui être retiré. Sa déchéance serait irréversible —
 * plus aucun acteur ne détiendrait `user.assign.right` pour le restaurer.
 */
class ProtectedAdminRightsTest extends TestCase
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
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (SambaPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
        Role::findOrCreate(SambaRole::SuperAdmin->value, 'web');
        Role::findOrCreate(SambaRole::Prof->value, 'web');
    }

    protected function tearDown(): void
    {
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeUser(string $login): User
    {
        return User::create(['login' => $login, 'is_active' => true]);
    }

    public function test_le_compte_admin_est_identifie_comme_protege(): void
    {
        $this->assertTrue($this->makeUser('admin')->isProtectedAdmin());
        $this->assertFalse($this->makeUser('adminfoo')->isProtectedAdmin());
        $this->assertFalse($this->makeUser('dupont')->isProtectedAdmin());
    }

    public function test_retirer_un_role_au_compte_protege_est_refuse(): void
    {
        $admin = $this->makeUser('admin');
        $admin->assignRole(SambaRole::SuperAdmin->value);

        $this->expectException(ProtectedAdminRightsException::class);
        $admin->removeRole(SambaRole::SuperAdmin->value);
    }

    public function test_revoquer_une_permission_directe_au_compte_protege_est_refuse(): void
    {
        $admin = $this->makeUser('admin');
        $admin->givePermissionTo(SambaPermission::UserAssignRight->value);

        $this->expectException(ProtectedAdminRightsException::class);
        $admin->revokePermissionTo(SambaPermission::UserAssignRight->value);
    }

    /**
     * `syncRoles` est retranchant par nature : c'est le chemin PAR DÉFAUT du
     * drawer de droits (« assigner ce rôle » = « n'avoir plus que ce rôle »).
     */
    public function test_sync_roles_ne_peut_pas_dechoir_le_compte_protege(): void
    {
        $admin = $this->makeUser('admin');
        $admin->assignRole(SambaRole::SuperAdmin->value);

        $admin->syncRoles([SambaRole::Prof->value]);

        $admin->unsetRelation('roles');
        $this->assertTrue($admin->hasRole(SambaRole::SuperAdmin->value));
        $this->assertTrue($admin->hasRole(SambaRole::Prof->value));
    }

    public function test_sync_permissions_restitue_l_integralite_des_droits_au_compte_protege(): void
    {
        $admin = $this->makeUser('admin');

        // Tentative de réduction à un seul droit.
        $admin->syncPermissions([SambaPermission::UserRead->value]);

        $admin->unsetRelation('permissions');
        $this->assertCount(
            count(SambaPermission::cases()),
            $admin->getDirectPermissions(),
            'Le compte protégé doit conserver TOUS les droits déclarés dans le code.'
        );
    }

    public function test_un_compte_ordinaire_reste_modifiable(): void
    {
        $user = $this->makeUser('dupont');
        $user->assignRole(SambaRole::Prof->value);
        $user->givePermissionTo(SambaPermission::UserRead->value);

        $user->removeRole(SambaRole::Prof->value);
        $user->revokePermissionTo(SambaPermission::UserRead->value);

        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        $this->assertFalse($user->hasRole(SambaRole::Prof->value));
        $this->assertFalse($user->hasDirectPermission(SambaPermission::UserRead->value));
    }

    /**
     * Un droit ajouté dans le code doit bénéficier au compte protégé
     * IMMÉDIATEMENT, sans attendre la synchronisation AD ni un reseed : le
     * `Gate::before` ne consulte pas les lignes en base.
     */
    public function test_un_droit_non_attache_en_base_est_quand_meme_accorde(): void
    {
        $admin = $this->makeUser('admin');
        // Aucun rôle, aucune permission attachée.

        $this->assertTrue(Gate::forUser($admin)->allows(SambaPermission::ServerAdmin->value));
        $this->assertTrue(Gate::forUser($admin)->allows(SambaPermission::UserAssignRight->value));
    }

    public function test_un_compte_ordinaire_ne_beneficie_pas_du_bypass(): void
    {
        $user = $this->makeUser('dupont');

        $this->assertFalse(Gate::forUser($user)->allows(SambaPermission::ServerAdmin->value));
    }

    /**
     * Garde-fou : le bypass ne couvre QUE les droits. `modify-capability`
     * (CapabilityPolicy) encode le verrou amont du contrat managé — l'autorité
     * amont prime sur tout acteur local, compte protégé compris.
     */
    public function test_le_bypass_ne_couvre_pas_les_gates_de_regle_metier(): void
    {
        $admin = $this->makeUser('admin');

        Gate::define('gate-de-regle-metier', fn () => false);

        $this->assertFalse(Gate::forUser($admin)->allows('gate-de-regle-metier'));
        $this->assertNull(
            SambaPermission::tryFrom('modify-capability'),
            'modify-capability ne doit pas être un droit, sans quoi le bypass le couvrirait.'
        );
    }
}
