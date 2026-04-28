<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Permissions;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Models\Delegation;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\PermissionService;
use App\Services\Permissions\RightsMigrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Tests unitaires du RightsMigrationService (Story 7.3).
 *
 * Couvre :
 *  - Volet 1 : mapping user→rôle Spatie depuis les 5 profils seedés.
 *  - Bug Annu_is_admin fallback ignoré (matrice §8 #6).
 *  - Profils custom rapatriés en 7.2 → résolution par nom.
 *  - Volet 2 : délégations scopées positives + négatives + parse CN.
 *  - Idempotence (2e run = pas de doublon).
 *  - Cas non mappables (user introuvable, parc introuvable, perm introuvable).
 *  - Mode dry-run : aucune écriture.
 */
class RightsMigrationServiceTest extends TestCase
{
    use CreatesPermissionSchema;
    use DatabaseTransactions;

    private RightsMigrationService $service;
    private PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();
        $this->seedPermissionsAndRoles();

        Queue::fake();
        WorkstationGroupObserver::disableSync();

        $this->permissionService = app(PermissionService::class);
        $this->service = new RightsMigrationService($this->permissionService);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    // ================================================================
    // Helpers
    // ================================================================

    private function seedPermissionsAndRoles(): void
    {
        foreach (SambaPermission::cases() as $perm) {
            Permission::firstOrCreate(['name' => $perm->value, 'guard_name' => 'web']);
        }

        foreach (SambaRole::cases() as $sambaRole) {
            $role = Role::firstOrCreate(['name' => $sambaRole->value, 'guard_name' => 'web']);
            $role->syncPermissions($sambaRole->permissionNames());
        }
    }

    private function createUser(string $login, ?string $dn = null): User
    {
        return User::create([
            'login'    => $login,
            'fullname' => ucfirst($login),
            'dn'       => $dn ?? "CN={$login},OU=Utilisateurs,DC=test,DC=local",
            'role'     => 'autre',
            'is_active' => true,
        ]);
    }

    private function createWorkstationGroup(string $name): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name'        => $name,
            'is_physical' => true,
            'is_active'   => true,
        ]);
    }

    /**
     * Fabrique un fetcher `rights_rdn` qui retourne les (cn, info) fournis.
     *
     * @param  array<string,int>  $profiles
     */
    private function makeRightsFetcher(array $profiles): \Closure
    {
        return fn (): array => $profiles;
    }

    /**
     * Fabrique un fetcher de membres de groupe qui retourne une liste fixe.
     *
     * @param  array<string, list<string>>  $members
     */
    private function makeMembersFetcher(array $members): \Closure
    {
        return fn (string $cn): array => $members[$cn] ?? [];
    }

    /**
     * Fabrique un fetcher de délégations scopées.
     *
     * @param  list<array{cn: string, members: list<string>}>  $groups
     */
    private function makeDelegationsFetcher(array $groups): \Closure
    {
        return fn (): array => $groups;
    }

    // ================================================================
    // Volet 1 — assignation user→rôle depuis rights_rdn
    // ================================================================

    #[Test]
    public function it_assigns_super_admin_role_to_members_of_se3_is_admin(): void
    {
        $admin = $this->createUser('admin1');

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher(['se3_is_admin' => 0xFFFF]),
            rightsMembersFetcher: $this->makeMembersFetcher([
                'se3_is_admin' => [$admin->dn],
            ]),
            delegationsFetcher: $this->makeDelegationsFetcher([]),
        );

        $this->assertTrue($admin->fresh()->hasRole(SambaRole::SuperAdmin->value));
        $this->assertSame(1, $report['roles_assigned']);
        $this->assertSame(1, $report['users_scanned']);
        $this->assertEmpty($report['unmappable']);
    }

    #[Test]
    public function it_assigns_computer_admin_role_to_members_of_computer_is_admin(): void
    {
        $tech = $this->createUser('tech1');

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher(['computer_is_admin' => 0xEF00]),
            rightsMembersFetcher: $this->makeMembersFetcher([
                'computer_is_admin' => [$tech->dn],
            ]),
            delegationsFetcher: $this->makeDelegationsFetcher([]),
        );

        $this->assertTrue($tech->fresh()->hasRole(SambaRole::ComputerAdmin->value));
        $this->assertSame(1, $report['roles_assigned']);
    }

    #[Test]
    public function it_assigns_referent_numerique_role_to_members_of_ref_num(): void
    {
        $ref = $this->createUser('ref1');

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher(['RefNum' => 0x90B]),
            rightsMembersFetcher: $this->makeMembersFetcher(['RefNum' => [$ref->dn]]),
            delegationsFetcher: $this->makeDelegationsFetcher([]),
        );

        $this->assertTrue($ref->fresh()->hasRole(SambaRole::ReferentNumerique->value));
        $this->assertSame(1, $report['roles_assigned']);
    }

    #[Test]
    public function it_handles_annu_is_admin_with_missing_info_as_user_admin_not_computer_admin(): void
    {
        // Matrice §8 #6 : bug fallback annu/profiles.php:58 qui remappait
        // Annu_is_admin sans info vers SE_COMPUTER_ADMIN. On NE reproduit PAS.
        $admin = $this->createUser('annuAdmin');

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher(['Annu_is_admin' => 0]), // info absente
            rightsMembersFetcher: $this->makeMembersFetcher([
                'Annu_is_admin' => [$admin->dn],
            ]),
            delegationsFetcher: $this->makeDelegationsFetcher([]),
        );

        $fresh = $admin->fresh();
        $this->assertTrue($fresh->hasRole(SambaRole::UserAdmin->value), 'Doit être UserAdmin (seed d\'origine 0xFF)');
        $this->assertFalse($fresh->hasRole(SambaRole::ComputerAdmin->value), 'Ne doit PAS être ComputerAdmin (bug ignoré)');
        $this->assertSame(1, $report['fallbacks_ignored']);
        $this->assertNotEmpty($report['warnings']);
    }

    #[Test]
    public function it_logs_explicit_warning_message_when_annu_is_admin_lacks_info(): void
    {
        // Review #7 : le bug fallback Annu_is_admin doit produire un log warning
        // explicite avec le fragment "fallback buggé ignoré, assignation alignée
        // sur le seed d'origine". On capture les warnings via Log::spy() et on
        // vérifie le message exact attendu.
        $admin = $this->createUser('annuLogged');

        Log::spy();

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher(['Annu_is_admin' => 0]),
            rightsMembersFetcher: $this->makeMembersFetcher([
                'Annu_is_admin' => [$admin->dn],
            ]),
            delegationsFetcher: $this->makeDelegationsFetcher([]),
        );

        // 1. Le rôle attribué doit être UserAdmin (seed d'origine), PAS ComputerAdmin.
        $fresh = $admin->fresh();
        $this->assertTrue(
            $fresh->hasRole(SambaRole::UserAdmin->value),
            'Annu_is_admin sans info doit retomber sur UserAdmin (seed d\'origine 0xFF)'
        );
        $this->assertFalse(
            $fresh->hasRole(SambaRole::ComputerAdmin->value),
            'Le bug legacy annu/profiles.php:58 (mapping vers ComputerAdmin) ne doit PAS être reproduit'
        );

        // 2. Le warning doit être émis avec le message exact.
        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message) {
                return is_string($message)
                    && str_contains($message, 'Annu_is_admin')
                    && str_contains($message, "fallback buggé ignoré, assignation alignée sur le seed d'origine");
            })
            ->once();

        // 3. Le rapport doit refléter le fallback ignoré.
        $this->assertSame(1, $report['fallbacks_ignored']);
    }

    #[Test]
    public function it_grants_direct_user_password_init_for_password_is_admin_without_role_escalation(): void
    {
        // Story 7.3 — review #1 (décision Henri 2026-04-25) :
        // `password_is_admin` (0x01) doit migrer vers la permission DIRECTE
        // `user.password.init` via `givePermissionTo`, PAS vers `SambaRole::UserAdmin`
        // (0xFF). Cela évite l'escalade : un user qui n'avait que le droit
        // de réinitialiser les MDP ne doit pas se retrouver avec 7 droits
        // supplémentaires (assign.right, delegate, modify, etc.).
        $user = $this->createUser('passadm');

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher(['password_is_admin' => 0x01]),
            rightsMembersFetcher: $this->makeMembersFetcher([
                'password_is_admin' => [$user->dn],
            ]),
            delegationsFetcher: $this->makeDelegationsFetcher([]),
        );

        $fresh = $user->fresh();

        // 1. Permission directe Spatie posée.
        $this->assertTrue(
            $fresh->hasDirectPermission(SambaPermission::UserPasswordInit->value),
            "Le user doit avoir la permission DIRECTE 'user.password.init' (pas via un rôle)"
        );

        // 2. AUCUN rôle Spatie (pas d'escalade UserAdmin).
        $this->assertSame(
            0,
            $fresh->roles()->count(),
            'password_is_admin ne doit PAS attribuer de rôle (anti-escalade #1)'
        );
        $this->assertFalse(
            $fresh->hasRole(SambaRole::UserAdmin->value),
            'password_is_admin ne doit JAMAIS recevoir UserAdmin (escalade 0xFF)'
        );

        // 3. Les autres permissions de UserAdmin ne doivent PAS être présentes.
        $this->assertFalse(
            $fresh->can(SambaPermission::UserAssignRight->value),
            'password_is_admin ne doit PAS pouvoir assigner des droits'
        );
        $this->assertFalse(
            $fresh->can(SambaPermission::UserDelegate->value),
            'password_is_admin ne doit PAS pouvoir déléguer'
        );

        // 4. Compteur fonctionnel rapporté.
        $this->assertSame(1, $report['roles_assigned']);
    }

    #[Test]
    public function password_is_admin_migration_is_idempotent_across_multiple_runs(): void
    {
        $user = $this->createUser('passidem');

        $fetcher = $this->makeRightsFetcher(['password_is_admin' => 0x01]);
        $members = $this->makeMembersFetcher(['password_is_admin' => [$user->dn]]);

        $this->service->migrate(dryRun: false, rightsFetcher: $fetcher, rightsMembersFetcher: $members, delegationsFetcher: $this->makeDelegationsFetcher([]));
        $this->service->migrate(dryRun: false, rightsFetcher: $fetcher, rightsMembersFetcher: $members, delegationsFetcher: $this->makeDelegationsFetcher([]));

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasDirectPermission(SambaPermission::UserPasswordInit->value));
        $this->assertSame(1, $fresh->permissions()->count(), 'Pas de doublon dans model_has_permissions après 2 runs');
    }

    #[Test]
    public function it_maps_custom_profile_to_role_by_name_when_created_in_db(): void
    {
        // Profil custom déjà rapatrié en 7.2 : Role DB existe avec ce nom.
        $customRole = Role::firstOrCreate(['name' => 'Animateur_CDI', 'guard_name' => 'web']);
        $customRole->syncPermissions([
            SambaPermission::UserRead->value,
            SambaPermission::UserPasswordInit->value,
        ]);

        $user = $this->createUser('anim1');

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher(['Animateur_CDI' => 0x03]),
            rightsMembersFetcher: $this->makeMembersFetcher([
                'Animateur_CDI' => [$user->dn],
            ]),
            delegationsFetcher: $this->makeDelegationsFetcher([]),
        );

        // Le user doit avoir le rôle custom EXACT (cas 3 du resolveRoleNameForProfile :
        // résolution par nom DB, PAS le fallback fromBitmask). C'est ce que le test
        // est censé valider — l'assertion `assertGreaterThan(0)` initiale était trop
        // faible et passait pour la mauvaise raison (Review #6).
        $fresh = $user->fresh();
        $this->assertTrue(
            $fresh->hasRole('Animateur_CDI'),
            "Le user doit avoir le rôle custom 'Animateur_CDI' (résolution par nom DB)"
        );
        $this->assertSame(1, $fresh->roles()->count(), 'Un seul rôle attribué (le custom)');
        $this->assertSame(1, $report['roles_assigned']);
    }

    #[Test]
    public function it_is_idempotent_across_multiple_runs(): void
    {
        $admin = $this->createUser('admin2');

        $fetch = $this->makeRightsFetcher(['se3_is_admin' => 0xFFFF]);
        $members = $this->makeMembersFetcher(['se3_is_admin' => [$admin->dn]]);

        $this->service->migrate(dryRun: false, rightsFetcher: $fetch, rightsMembersFetcher: $members, delegationsFetcher: $this->makeDelegationsFetcher([]));
        $this->service->migrate(dryRun: false, rightsFetcher: $fetch, rightsMembersFetcher: $members, delegationsFetcher: $this->makeDelegationsFetcher([]));
        $this->service->migrate(dryRun: false, rightsFetcher: $fetch, rightsMembersFetcher: $members, delegationsFetcher: $this->makeDelegationsFetcher([]));

        $fresh = $admin->fresh();
        $this->assertTrue($fresh->hasRole(SambaRole::SuperAdmin->value));
        // La relation model_has_roles ne doit contenir qu'une seule ligne.
        $this->assertSame(1, $fresh->roles()->count(), 'Les 3 runs ne doivent pas créer de doublons');
    }

    #[Test]
    public function it_reports_users_not_found_as_unmappable_without_crashing(): void
    {
        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher(['se3_is_admin' => 0xFFFF]),
            rightsMembersFetcher: $this->makeMembersFetcher([
                'se3_is_admin' => ['CN=inexistant,OU=Utilisateurs,DC=test'],
            ]),
            delegationsFetcher: $this->makeDelegationsFetcher([]),
        );

        $this->assertSame(1, $report['users_scanned']);
        $this->assertSame(0, $report['roles_assigned']);
        $this->assertCount(1, $report['unmappable']);
        $this->assertSame('user_not_found', $report['unmappable'][0]['kind']);
    }

    #[Test]
    public function it_does_not_write_anything_in_dry_run(): void
    {
        $admin = $this->createUser('dryadmin');

        $report = $this->service->migrate(
            dryRun: true,
            rightsFetcher: $this->makeRightsFetcher(['se3_is_admin' => 0xFFFF]),
            rightsMembersFetcher: $this->makeMembersFetcher([
                'se3_is_admin' => [$admin->dn],
            ]),
            delegationsFetcher: $this->makeDelegationsFetcher([]),
        );

        // Le rapport compte toujours — mais aucune écriture DB.
        $this->assertSame(1, $report['roles_assigned']);
        $this->assertSame(0, $admin->fresh()->roles()->count(), 'Dry-run ne doit écrire aucun rôle');
    }

    // ================================================================
    // Volet 2 — délégations scopées
    // ================================================================

    #[Test]
    public function it_creates_positive_delegation_from_legacy_manage_cn(): void
    {
        // Story 7.3 — review #10/#12 : format CN legacy réel `manage_<parc>` →
        // permission Spatie `computer.elevate` (mapping `LEGACY_DELEGATION_LEVELS`).
        $user = $this->createUser('techpos');
        $wg = $this->createWorkstationGroup('salle-a12');

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher([]),
            rightsMembersFetcher: $this->makeMembersFetcher([]),
            delegationsFetcher: $this->makeDelegationsFetcher([
                [
                    'cn'      => 'manage_salle-a12',
                    'members' => [$user->dn, 'CN=salle-a12,OU=Parcs,DC=test,DC=local'],
                ],
            ]),
        );

        $this->assertSame(1, $report['delegations_created']);
        $this->assertSame(0, $report['negatives_created']);

        $delegation = Delegation::where('user_id', $user->id)
            ->where('workstation_group_id', $wg->id)
            ->where('is_negative', false)
            ->with('permission')
            ->first();
        $this->assertNotNull($delegation);
        $this->assertSame(SambaPermission::ComputerElevate->value, $delegation->permission->name);
    }

    #[Test]
    public function it_parses_parc_name_with_underscores_correctly(): void
    {
        // Story 7.3 — review #2/#10 : un parc nommé `salle_info_bat_A` ne doit
        // PAS être charcuté par le parsing : le regex strict capture le 3e
        // groupe en greedy sur tout ce qui suit le préfixe `<level>_`.
        $user = $this->createUser('techunderscore');
        $wg = $this->createWorkstationGroup('salle_info_bat_A');

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher([]),
            rightsMembersFetcher: $this->makeMembersFetcher([]),
            delegationsFetcher: $this->makeDelegationsFetcher([
                [
                    'cn'      => 'view_salle_info_bat_A',
                    'members' => [$user->dn],
                ],
            ]),
        );

        $this->assertSame(1, $report['delegations_created']);
        $this->assertEmpty($report['unmappable']);

        $delegation = Delegation::where('user_id', $user->id)
            ->where('workstation_group_id', $wg->id)
            ->where('is_negative', false)
            ->with('permission')
            ->first();
        $this->assertNotNull($delegation);
        $this->assertSame(SambaPermission::ComputerView->value, $delegation->permission->name);
    }

    #[Test]
    public function it_creates_negative_delegation_from_no_prefixed_legacy_cn(): void
    {
        // Story 7.3 — review #10 : préfixe `no_` correctement extrait par le
        // regex `(no_)?(manage|view|rdp)_(.+)`. La permission Spatie cible est
        // celle mappée par le level (ici `manage` → `computer.elevate`).
        $user = $this->createUser('techneg');
        $wg = $this->createWorkstationGroup('salle1');

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher([]),
            rightsMembersFetcher: $this->makeMembersFetcher([]),
            delegationsFetcher: $this->makeDelegationsFetcher([
                [
                    'cn'      => 'no_manage_salle1',
                    'members' => [$user->dn, 'CN=salle1,OU=Parcs,DC=test,DC=local'],
                ],
            ]),
        );

        $this->assertSame(0, $report['delegations_created']);
        $this->assertSame(1, $report['negatives_created']);

        $delegation = Delegation::where('user_id', $user->id)
            ->where('workstation_group_id', $wg->id)
            ->where('is_negative', true)
            ->with('permission')
            ->first();
        $this->assertNotNull($delegation);
        $this->assertSame(SambaPermission::ComputerElevate->value, $delegation->permission->name);
    }

    #[Test]
    public function it_creates_rdp_delegation_with_dedicated_remote_rdp_permission(): void
    {
        // Story 7.3 — review #10 (option C, décision Henri 2026-04-25) :
        // le level `rdp` legacy est migré vers la permission Spatie dédiée
        // `computer.remote.rdp` (et non `computer.control` malgré le partage
        // du bit 0x200 côté legacyRight()).
        $user = $this->createUser('techrdp');
        $wg = $this->createWorkstationGroup('salle-rdp');

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher([]),
            rightsMembersFetcher: $this->makeMembersFetcher([]),
            delegationsFetcher: $this->makeDelegationsFetcher([
                [
                    'cn'      => 'rdp_salle-rdp',
                    'members' => [$user->dn],
                ],
            ]),
        );

        $this->assertSame(1, $report['delegations_created']);
        $this->assertEmpty($report['unmappable']);

        $delegation = Delegation::where('user_id', $user->id)
            ->where('workstation_group_id', $wg->id)
            ->with('permission')
            ->first();
        $this->assertNotNull($delegation);
        $this->assertSame(
            SambaPermission::ComputerRemoteRdp->value,
            $delegation->permission->name,
            'rdp_<parc> doit être migré vers la perm dédiée computer.remote.rdp, pas computer.control'
        );
    }

    #[Test]
    public function it_reports_workstation_group_not_found_as_unmappable(): void
    {
        $user = $this->createUser('tech3');

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher([]),
            rightsMembersFetcher: $this->makeMembersFetcher([]),
            delegationsFetcher: $this->makeDelegationsFetcher([
                [
                    'cn'      => 'view_parc-fantome',
                    'members' => [$user->dn],
                ],
            ]),
        );

        $this->assertSame(0, $report['delegations_created']);
        $this->assertCount(1, $report['unmappable']);
        $this->assertSame('workstation_group_not_found', $report['unmappable'][0]['kind']);
    }

    #[Test]
    public function it_reports_unknown_legacy_cn_format_as_parse_error(): void
    {
        // Story 7.3 — review #12 : un CN qui ne matche pas le regex
        // `(no_)?(manage|view|rdp)_<parc>` est rapporté `delegation_parse_error`
        // avec un log warning, pas d'unmappable silencieux.
        $user = $this->createUser('tech4');
        $this->createWorkstationGroup('salleX');

        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher([]),
            rightsMembersFetcher: $this->makeMembersFetcher([]),
            delegationsFetcher: $this->makeDelegationsFetcher([
                [
                    'cn'      => 'fake.inexistant_salleX',
                    'members' => [$user->dn],
                ],
            ]),
        );

        $this->assertSame(0, $report['delegations_created']);
        $this->assertCount(1, $report['unmappable']);
        $this->assertSame('delegation_parse_error', $report['unmappable'][0]['kind']);
    }

    #[Test]
    public function it_logs_no_op_warning_when_delegations_branch_empty(): void
    {
        $report = $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher([]),
            rightsMembersFetcher: $this->makeMembersFetcher([]),
            delegationsFetcher: $this->makeDelegationsFetcher([]),
        );

        $this->assertSame(0, $report['delegations_created']);
        $this->assertSame(0, $report['negatives_created']);
        $this->assertNotEmpty($report['warnings']);
        $this->assertStringContainsString('no-op', $report['warnings'][0]);
    }

    #[Test]
    public function delegation_scoped_migration_is_idempotent(): void
    {
        $user = $this->createUser('techidem');
        $wg = $this->createWorkstationGroup('salle-idem');

        $fetcher = $this->makeDelegationsFetcher([
            [
                'cn'      => 'manage_salle-idem',
                'members' => [$user->dn, 'CN=salle-idem,OU=Parcs,DC=test'],
            ],
        ]);

        // Premier run
        $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher([]),
            rightsMembersFetcher: $this->makeMembersFetcher([]),
            delegationsFetcher: $fetcher,
        );

        // Deuxième run
        $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher([]),
            rightsMembersFetcher: $this->makeMembersFetcher([]),
            delegationsFetcher: $fetcher,
        );

        $count = Delegation::where('user_id', $user->id)
            ->where('workstation_group_id', $wg->id)
            ->where('is_negative', false)
            ->count();
        $this->assertSame(1, $count, 'Les deux runs ne doivent créer qu\'une seule délégation');
    }

    #[Test]
    public function it_logs_delegation_history_with_explicit_migration_source_context(): void
    {
        // Story 7.3 — review #8 (décision Henri 2026-04-25) : les entrées
        // `delegation_history` créées par la migration ont actor=null mais
        // le champ `context` JSONB embarque source='migration-7.3' +
        // un message 'Migration legacy 7.3 - aucun acteur humain' pour
        // tracer qu'aucun humain n'est responsable de cette ligne.
        $user = $this->createUser('audit');
        $wg = $this->createWorkstationGroup('salle-audit');

        $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher([]),
            rightsMembersFetcher: $this->makeMembersFetcher([]),
            delegationsFetcher: $this->makeDelegationsFetcher([
                [
                    'cn'      => 'manage_salle-audit',
                    'members' => [$user->dn],
                ],
            ]),
        );

        $history = \App\Models\DelegationHistory::where('target_user_id', $user->id)
            ->where('workstation_group_id', $wg->id)
            ->where('action', \App\Models\DelegationHistory::ACTION_GRANT)
            ->first();

        $this->assertNotNull($history, 'Une entrée delegation_history doit être créée');
        $this->assertNull($history->actor_user_id, "L'acteur doit être null (commande de migration)");

        $context = $history->context;
        $this->assertIsArray($context);
        $this->assertSame('migration-7.3', $context['source'] ?? null, "context.source = 'migration-7.3'");
        $this->assertStringContainsString(
            'aucun acteur humain',
            $context['message'] ?? '',
            "context.message doit indiquer explicitement l'absence d'acteur humain"
        );
        $this->assertSame('manage_salle-audit', $context['legacy_cn'] ?? null);
    }

    #[Test]
    public function rerun_does_not_overwrite_granted_by_of_manually_created_delegation(): void
    {
        // Story 7.3 — review #11 (décision Henri 2026-04-25) : `firstOrCreate`
        // au lieu de `updateOrCreate` pour préserver `granted_by` d'une
        // délégation posée manuellement entre deux runs de migration. Sans
        // ce correctif, le re-run écraserait l'acteur humain par null.
        $henri = $this->createUser('henri-admin');
        $bob = $this->createUser('bob');
        $wg = $this->createWorkstationGroup('salle-manual');

        // Étape 1 : Henri pose manuellement la délégation (avant le re-run).
        $this->permissionService->grantDelegation(
            $bob,
            SambaPermission::ComputerElevate->value,
            $wg,
            $henri
        );

        $manual = Delegation::where('user_id', $bob->id)
            ->where('workstation_group_id', $wg->id)
            ->where('is_negative', false)
            ->first();
        $this->assertNotNull($manual);
        $this->assertSame($henri->id, $manual->granted_by, 'Pré-condition : délégation posée par Henri');

        // Étape 2 : la migration tourne — la même délégation existe en legacy
        // (groupe `manage_salle-manual` avec Bob membre).
        $this->service->migrate(
            dryRun: false,
            rightsFetcher: $this->makeRightsFetcher([]),
            rightsMembersFetcher: $this->makeMembersFetcher([]),
            delegationsFetcher: $this->makeDelegationsFetcher([
                [
                    'cn'      => 'manage_salle-manual',
                    'members' => [$bob->dn, 'CN=salle-manual,OU=Parcs,DC=test'],
                ],
            ]),
        );

        // Étape 3 : `granted_by` doit toujours pointer sur Henri (pas null).
        $afterMigration = $manual->fresh();
        $this->assertSame(
            $henri->id,
            $afterMigration->granted_by,
            'firstOrCreate ne doit PAS écraser granted_by sur une délégation préexistante'
        );
    }
}
