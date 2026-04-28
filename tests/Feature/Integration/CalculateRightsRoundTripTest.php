<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Enums\LegacyRight;
use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Models\Delegation;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Permissions\RightsMigrationService;
use App\Services\PermissionService;
use App\Services\RightsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Tests d'intégration Story 7.3 — round-trip identité bitmask.
 *
 * Vérifie que pour chaque profil seedé + profil custom + délégations scopées,
 * le bitmask produit par `RightsService::calculateRights()` **après** migration
 * (Spatie-source) est identique au bitmask attendu **avant** migration
 * (LDAP-source), modulo le filtre `SE_COMPUTER_VIEW`.
 *
 * Étapes de chaque test :
 *  1. Préparer un scénario LDAP fictif (fetcher fixture).
 *  2. Capturer le bitmask attendu (LDAP-source, valeur `info` brute).
 *  3. Lancer la migration via `RightsMigrationService`.
 *  4. Capturer le bitmask retourné par `RightsService::calculateRightsForUser()`
 *     (Spatie-source, reconstruit depuis les rôles Spatie).
 *  5. Comparer : identiques à `SE_COMPUTER_VIEW` près.
 */
class CalculateRightsRoundTripTest extends TestCase
{
    use CreatesPermissionSchema;
    use DatabaseTransactions;

    private RightsMigrationService $migrationService;
    private RightsService $rightsService;

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

        $permissionService = app(PermissionService::class);
        $this->migrationService = new RightsMigrationService($permissionService);
        $this->rightsService = new RightsService();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

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

    private function createUser(string $login): User
    {
        return User::create([
            'login'    => $login,
            'fullname' => ucfirst($login),
            'role'     => 'autre',
            'dn'       => "CN={$login},OU=Utilisateurs,DC=test",
            'is_active' => true,
        ]);
    }

    /**
     * Filtre le bit `SE_COMPUTER_VIEW` : c'est un droit web pur jamais
     * bitmasqué par `calculateRights()` (cf. shim `legacy/ldap.inc.php:50`).
     */
    private function stripComputerView(int $bitmask): int
    {
        return $bitmask & ~LegacyRight::ComputerView->value;
    }

    // ================================================================
    // Round-trip profils seedés (matrice §5.3)
    // ================================================================

    /**
     * Sémantique d'assertion par profil :
     *  - 'equality'  : le bitmask Spatie produit doit être STRICTEMENT EGAL au bitmask legacy
     *                  (modulo ComputerView). C'est le cas attendu pour les profils où la
     *                  matrice §5.3 prescrit un mapping 1:1 sans bit ajouté/retiré.
     *  - 'subset'    : le bitmask Spatie peut être plus large (bits ajoutés intentionnellement,
     *                  ex. WpkgAssign 0x1000 dans ComputerAdmin) ou plus étroit (ex. ServerAdmin
     *                  0x8000 exclu de ComputerAdmin par modélisation plus granulaire).
     *                  L'assertion vérifie seulement que les bits legacy attendus sont présents.
     *
     * Review #3 : avant cette refonte, TOUS les profils étaient en subset, ce qui masquait
     * le bug d'escalade #1 (password_is_admin → UserAdmin 0xFF au lieu de 0x01). On bascule
     * en equality stricte par défaut, et on skip explicitement password_is_admin tant que
     * Henri n'a pas tranché #1.
     *
     * @return list<array{0:string, 1:int, 2:string, 3:string}>
     */
    public static function seededProfilesProvider(): array
    {
        return [
            'se3_is_admin (SuperAdmin 0xFFFF)'         => ['se3_is_admin',       0xFFFF, SambaRole::SuperAdmin->value,        'equality'],
            'computer_is_admin (ComputerAdmin 0xEF00)' => ['computer_is_admin',  0xEF00, SambaRole::ComputerAdmin->value,     'subset'],
            'Annu_is_admin (UserAdmin 0xFF)'           => ['Annu_is_admin',      0xFF,   SambaRole::UserAdmin->value,         'equality'],
            'password_is_admin (UserAdmin 0xFF)'       => ['password_is_admin',  0x01,   SambaRole::UserAdmin->value,         'equality'],
            'RefNum (ReferentNumerique 0x90B)'         => ['RefNum',             0x90B,  SambaRole::ReferentNumerique->value, 'equality'],
        ];
    }

    #[Test]
    #[DataProvider('seededProfilesProvider')]
    public function round_trip_seeded_profile_produces_consistent_bitmask(
        string $ldapGroupCn,
        int $ldapInfoBitmask,
        string $expectedRoleName,
        string $assertionMode,
    ): void {
        $user = $this->createUser('user-' . strtolower(str_replace('_', '-', $ldapGroupCn)));

        // Review #1 (décision Henri 2026-04-25) : `password_is_admin` n'attribue
        // PAS le rôle Spatie `UserAdmin`. Au lieu de cela, la migration pose la
        // permission directe `user.password.init`. Le data provider conserve
        // l'entrée pour vérifier le bitmask attendu (0x01 strict, anti-escalade)
        // mais on bypass l'assertion sur le hasRole pour ce cas spécifique.
        $expectsDirectPermissionInsteadOfRole = ($ldapGroupCn === 'password_is_admin');

        // Scénario LDAP fixture : 1 groupe, 1 membre.
        $this->migrationService->migrate(
            dryRun: false,
            rightsFetcher: fn () => [$ldapGroupCn => $ldapInfoBitmask],
            rightsMembersFetcher: fn (string $cn) => $cn === $ldapGroupCn ? [$user->dn] : [],
            delegationsFetcher: fn () => [],
        );

        if ($expectsDirectPermissionInsteadOfRole) {
            // Pour `password_is_admin` : la migration pose une permission DIRECTE
            // (matrice §5.3) plutôt qu'un rôle. On vérifie l'absence d'escalade
            // ET la présence de la permission directe.
            $fresh = $user->fresh();
            $this->assertSame(0, $fresh->roles()->count(), 'password_is_admin ne doit créer AUCUN rôle (anti-escalade #1)');
            $this->assertTrue(
                $fresh->hasDirectPermission(SambaPermission::UserPasswordInit->value),
                'password_is_admin doit poser la permission directe user.password.init'
            );
        } else {
            // Cas standard : le rôle attendu doit être attribué.
            $this->assertTrue(
                $user->fresh()->hasRole($expectedRoleName),
                "Le user doit avoir le rôle '{$expectedRoleName}' après migration"
            );
        }

        // Round-trip : le bitmask Spatie-source est reconstruit depuis les rôles
        // assignés via RightsService::calculateRightsForUser().
        $spatieSourceBitmask = $this->rightsService->calculateRightsForUser($user->fresh());

        // Bits "legacy attendus" : on strip ComputerView (jamais bitmasqué) et
        // ServerAdmin (composé par le role SuperAdmin uniquement côté Spatie).
        $expectedBits = $this->stripComputerView($ldapInfoBitmask) & ~LegacyRight::ServerAdmin->value;

        if ($assertionMode === 'equality') {
            // Egalité stricte : le bitmask Spatie produit doit être EXACTEMENT
            // celui attendu — aucun bit en plus, aucun bit en moins (modulo
            // ComputerView et ServerAdmin déjà strippés). C'est cette assertion
            // qui aurait détecté le bug #1 (password_is_admin → 0xFF au lieu de 0x01).
            $actualBits = $this->stripComputerView($spatieSourceBitmask) & ~LegacyRight::ServerAdmin->value;
            $this->assertSame(
                $expectedBits,
                $actualBits,
                sprintf(
                    "Egalité stricte attendue : bitmask Spatie (0x%X) doit être identique au bitmask legacy attendu (0x%X) pour le rôle '%s'",
                    $actualBits,
                    $expectedBits,
                    $expectedRoleName
                )
            );

            return;
        }

        // Mode 'subset' : tolère que le bitmask Spatie soit plus large (ex.
        // ComputerAdmin embarque WpkgAssign 0x1000 absent du legacy 0xEF00,
        // décision modélisation matrice §5.2). On vérifie seulement que tous
        // les bits attendus sont présents.
        $this->assertSame(
            $expectedBits,
            $expectedBits & $spatieSourceBitmask,
            sprintf(
                "Tous les bits attendus (0x%X) doivent être présents dans le bitmask Spatie (0x%X) pour le rôle '%s'",
                $expectedBits,
                $spatieSourceBitmask,
                $expectedRoleName
            )
        );
    }

    // ================================================================
    // Délégation positive scopée : round-trip
    // ================================================================

    #[Test]
    public function round_trip_positive_scoped_delegation_adds_permission_to_bitmask(): void
    {
        $user = $this->createUser('deleg-pos-user');
        $wg = WorkstationGroup::create([
            'name' => 'parc-roundtrip-pos',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->migrationService->migrate(
            dryRun: false,
            rightsFetcher: fn () => [],
            rightsMembersFetcher: fn () => [],
            delegationsFetcher: fn () => [
                [
                    // Story 7.3 — format CN legacy réel : `manage` → computer.elevate.
                    'cn'      => 'manage_parc-roundtrip-pos',
                    'members' => [$user->dn],
                ],
            ],
        );

        // Sans scope : aucun bit.
        $without = $this->rightsService->calculateRightsForUser($user->fresh());
        $this->assertSame(0, $without);

        // Avec scope : le bit computer.elevate est OR-agrégé.
        $with = $this->rightsService->calculateRightsForUser($user->fresh(), $wg);
        $this->assertSame(LegacyRight::ComputerElevate->value, $with);
    }

    // ================================================================
    // Délégation négative scopée : round-trip AND-NOT
    // ================================================================

    #[Test]
    public function round_trip_negative_scoped_delegation_removes_permission_and_not(): void
    {
        $user = $this->createUser('deleg-neg-user');
        $user->assignRole(SambaRole::ComputerAdmin->value);

        $wg = WorkstationGroup::create([
            'name' => 'parc-roundtrip-neg',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->migrationService->migrate(
            dryRun: false,
            rightsFetcher: fn () => [],
            rightsMembersFetcher: fn () => [],
            delegationsFetcher: fn () => [
                [
                    // Story 7.3 — format CN legacy réel : `no_manage` → négative
                    // sur `computer.elevate` (0x400). Le legacy ne supporte pas
                    // un négatif spécifique sur `install`, le mapping `level →
                    // permission` est figé à manage/view/rdp (cf. `LEGACY_DELEGATION_LEVELS`).
                    'cn'      => 'no_manage_parc-roundtrip-neg',
                    'members' => [$user->dn],
                ],
            ],
        );

        // Sans scope : ComputerAdmin → computer.elevate (0x400) présent.
        $without = $this->rightsService->calculateRightsForUser($user->fresh());
        $this->assertNotSame(0, $without & LegacyRight::ComputerElevate->value);

        // Avec scope : la négative retire le bit via AND-NOT.
        $with = $this->rightsService->calculateRightsForUser($user->fresh(), $wg);
        $this->assertSame(0, $with & LegacyRight::ComputerElevate->value);
    }

    // ================================================================
    // Profil custom avec bitmask composite
    // ================================================================

    #[Test]
    public function round_trip_custom_profile_composite_bitmask(): void
    {
        // Profil custom 0x302 = user.read (0x02) | user.modify (0x04) | computer.view (0x100) | computer.control (0x200)
        // wait: 0x302 = 0x200 | 0x100 | 0x02 = computer.control | computer.view | user.read
        $compositeBitmask = LegacyRight::UserRead->value
            | LegacyRight::ComputerView->value
            | LegacyRight::ComputerControl->value;

        // Simule le rôle custom créé par 7.2 (importCustomProfilesFromAd).
        $customRole = Role::firstOrCreate(['name' => 'custom_0x302', 'guard_name' => 'web']);
        $customRole->syncPermissions([
            SambaPermission::UserRead->value,
            SambaPermission::ComputerView->value,
            SambaPermission::ComputerControl->value,
        ]);

        $user = $this->createUser('custom-user');

        $this->migrationService->migrate(
            dryRun: false,
            rightsFetcher: fn () => ['custom_0x302' => $compositeBitmask],
            rightsMembersFetcher: fn (string $cn) => $cn === 'custom_0x302' ? [$user->dn] : [],
            delegationsFetcher: fn () => [],
        );

        // Le user doit avoir au moins un rôle.
        $this->assertGreaterThan(0, $user->fresh()->roles()->count());

        $bitmask = $this->rightsService->calculateRightsForUser($user->fresh());
        $expected = $this->stripComputerView($compositeBitmask);

        // Tous les bits attendus sont présents (UserRead + ComputerControl).
        $this->assertSame($expected, $bitmask & $expected);
    }
}
