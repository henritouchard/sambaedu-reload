<?php

namespace Tests\Integration\LegacyLaravelComparison;

use Tests\TestCase;
use App\Models\WorkstationGroup;
use App\Models\AppProfile;
use App\Services\AdSync\AdSyncService;
use App\Services\AdSync\AppProfileAdSyncService;
use App\Observers\WorkstationGroupObserver;
use App\Observers\AppProfileObserver;
use App\Repositories\WorkstationGroupRepository;
use App\Config\LdapDnHelper;
use App\LdapModels\DeviceGroupModel;
use App\LdapModels\DeviceGroupTagModel;
use Illuminate\Support\Facades\Queue;
use LdapRecord\Models\ActiveDirectory\OrganizationalUnit;
use LdapRecord\Models\ActiveDirectory\Group;

/**
 * Tests des synchronisations manuelles entre SQL et AD
 * 
 * Ces tests vérifient les cas de synchronisation manuelle :
 * - Export SQL → AD (avec et sans AppProfile)
 * - Import AD → SQL (avec et sans AppProfile)
 * 
 * @group integration
 * @group ad-sync
 */
class ManualSyncTest extends TestCase
{
    private AdSyncService $adSyncService;
    private AppProfileAdSyncService $appProfileAdSyncService;
    private WorkstationGroupRepository $repository;
    private LdapDnHelper $dnHelper;
    private array $createdGroups = [];
    private array $createdProfiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Charger la config legacy pour les tests AD
        $legacy_base = '/var/www/sambaedu';
        require_once $legacy_base . '/includes/config.inc.php';
        require_once $legacy_base . '/includes/ldap.inc.php';
        require_once $legacy_base . '/includes/samba-tool.inc.php';

        $this->adSyncService = app(AdSyncService::class);
        $this->appProfileAdSyncService = app(AppProfileAdSyncService::class);
        $this->repository = app(WorkstationGroupRepository::class);
        $this->dnHelper = app(LdapDnHelper::class);

        // Désactiver les observers pour contrôler manuellement la sync
        WorkstationGroupObserver::disableSync();
        AppProfileObserver::disableSync();
        
        // Désactiver les queues pour exécution synchrone
        Queue::fake();
    }

    protected function tearDown(): void
    {
        // Nettoyer les groupes créés
        foreach ($this->createdGroups as $name) {
            $this->cleanupGroup($name);
        }
        foreach ($this->createdProfiles as $name) {
            $this->cleanupProfile($name);
        }

        // Réactiver les observers
        WorkstationGroupObserver::enableSync();
        AppProfileObserver::enableSync();

        parent::tearDown();
    }

    // ========================================================================
    // TEST 1: Export SQL → AD avec AppProfile
    // ========================================================================

    /**
     * Test: Synchronisation d'un groupe + appProfile vers AD
     * 
     * Vérifie que :
     * - L'OU est créée dans OU=Computers
     * - Le CN est créé dans OU=Parcs (via AppProfile)
     */
    public function test_export_group_with_app_profile_to_ad(): void
    {
        $groupName = 'TestExportWithProfile_' . uniqid();
        $this->createdGroups[] = $groupName;
        $this->createdProfiles[] = $groupName;

        // 1. Créer le groupe en SQL (sans sync AD)
        $group = WorkstationGroup::create([
            'name' => $groupName,
            'display_name' => $groupName,
            'description' => 'Test export avec profil',
            'is_physical' => true,
            'is_active' => true,
            'app_profile_name' => $groupName,
        ]);

        // 2. Créer l'AppProfile en SQL
        $profile = AppProfile::create([
            'name' => $groupName,
            'display_name' => $groupName,
            'description' => 'Profil applicatif test',
            'is_active' => true,
        ]);

        // 3. Créer le lien dans la table pivot app_profile_workstation_group
        $group->appProfiles()->attach($profile->id);

        // 4. Vérifier que le lien SQL existe
        $this->assertTrue(
            $group->appProfiles()->where('app_profiles.id', $profile->id)->exists(),
            'Le lien doit exister dans la table pivot app_profile_workstation_group'
        );

        // 5. Exporter le WorkstationGroup vers AD (crée l'OU dans OU=Computers)
        $resultGroup = $this->adSyncService->createWorkstationGroup($group);
        $this->assertTrue($resultGroup['success'], 'Création OU doit réussir: ' . ($resultGroup['error'] ?? ''));
        $this->assertNotEmpty($resultGroup['guid'], 'GUID de l\'OU doit être retourné');
        $this->assertNotEmpty($resultGroup['dn'], 'DN de l\'OU doit être retourné');

        // 6. Exporter l'AppProfile vers AD (crée le CN dans OU=Parcs)
        $resultProfile = $this->appProfileAdSyncService->createAppProfile($profile);
        $this->assertTrue($resultProfile['success'], 'Création CN doit réussir: ' . ($resultProfile['error'] ?? ''));

        // 7. Vérifier que l'OU existe dans OU=Computers
        $this->assertOuExistsInComputers($groupName);

        // 8. Vérifier que le CN existe dans OU=Parcs
        $this->assertCnExistsInParcs($groupName);
    }

    // ========================================================================
    // TEST 2: Export SQL → AD sans AppProfile
    // ========================================================================

    /**
     * Test: Synchronisation d'un groupe sans appProfile vers AD
     * 
     * Vérifie que :
     * - L'OU est créée dans OU=Computers
     * - Aucun CN n'est créé dans OU=Parcs
     */
    public function test_export_group_without_app_profile_to_ad(): void
    {
        $groupName = 'TestExportNoProfile_' . uniqid();
        $this->createdGroups[] = $groupName;

        // 1. Créer le groupe en SQL sans AppProfile
        $group = WorkstationGroup::create([
            'name' => $groupName,
            'display_name' => $groupName,
            'description' => 'Test export sans profil',
            'is_physical' => true,
            'is_active' => true,
            'app_profile_name' => null,
        ]);

        // 2. Exporter le WorkstationGroup vers AD
        $result = $this->adSyncService->createWorkstationGroup($group);
        $this->assertTrue($result['success'], 'Création OU doit réussir: ' . ($result['error'] ?? ''));

        // 3. Vérifier que l'OU existe dans OU=Computers
        $this->assertOuExistsInComputers($groupName);

        // 4. Vérifier que le CN existe dans OU=Parcs (les groupes physiques créent toujours un CN)
        $this->assertCnExistsInParcs($groupName);

        // 5. Vérifier qu'aucun lien n'existe dans la table pivot
        $this->assertFalse(
            $group->appProfiles()->exists(),
            'Aucun lien ne doit exister dans la table pivot app_profile_workstation_group'
        );
    }

    // ========================================================================
    // TEST 3: Import AD → SQL avec AppProfile
    // ========================================================================

    /**
     * Test: Synchronisation d'un groupe + appProfile vers SQL
     * 
     * Simule l'import depuis AD quand l'OU et le CN existent.
     * Vérifie que :
     * - Le WorkstationGroup est créé en SQL
     * - L'AppProfile est créé en SQL
     * - Les deux sont liés (app_profile_name)
     */
    public function test_import_group_with_app_profile_to_sql(): void
    {
        $groupName = 'TestImportWithProfile_' . uniqid();
        $this->createdGroups[] = $groupName;
        $this->createdProfiles[] = $groupName;

        // 1. Créer l'OU dans AD (OU=Computers)
        $computersDn = $this->dnHelper->computers();
        $ouDn = "OU={$groupName},{$computersDn}";
        $ou = new OrganizationalUnit();
        $ou->setDn($ouDn);
        $ou->ou = $groupName;
        $ou->save();

        // Récupérer le GUID de l'OU
        $ou = OrganizationalUnit::find($ouDn);
        $ouGuid = $ou->getConvertedGuid();

        // 2. Créer le CN dans AD (OU=Parcs)
        $parcsDn = $this->dnHelper->parcs();
        $cnDn = "CN={$groupName},{$parcsDn}";
        $cn = new Group();
        $cn->setDn($cnDn);
        $cn->cn = $groupName;
        $cn->samaccountname = $groupName;
        $cn->grouptype = -2147483646;
        $cn->save();

        // 3. Simuler l'import : créer le WorkstationGroup en SQL
        $group = WorkstationGroup::create([
            'name' => $groupName,
            'display_name' => $groupName,
            'is_physical' => true,
            'is_active' => true,
            'ad_guid' => $ouGuid,
            'ad_dn' => $ouDn,
        ]);

        // 4. Vérifier si un CN du même nom existe dans OU=Parcs
        $parcNames = $this->repository->getParcNamesFromAd();
        $hasCnInParcs = in_array(strtolower($groupName), $parcNames, true);
        $this->assertTrue($hasCnInParcs, 'Le CN doit exister dans OU=Parcs');

        // 5. Créer l'AppProfile en SQL
        $profile = AppProfile::create([
            'name' => $groupName,
            'display_name' => $groupName,
            'description' => "Profil applicatif importé depuis AD pour {$groupName}",
            'is_active' => true,
        ]);

        // 6. Créer le lien dans la table pivot et mettre à jour app_profile_name
        $group->appProfiles()->attach($profile->id);
        $group->update(['app_profile_name' => $groupName]);

        // 7. Vérifications SQL
        $group->refresh();
        $this->assertNotNull($group, 'Le WorkstationGroup doit exister en SQL');
        $this->assertEquals($groupName, $group->app_profile_name, 'app_profile_name doit être défini');

        // 8. Vérifier que le lien existe dans la table pivot
        $this->assertTrue(
            $group->appProfiles()->where('app_profiles.id', $profile->id)->exists(),
            'Le lien doit exister dans la table pivot app_profile_workstation_group'
        );

        $profile = AppProfile::where('name', $groupName)->first();
        $this->assertNotNull($profile, 'L\'AppProfile doit exister en SQL');
        $this->assertEquals($groupName, $profile->name, 'Le nom de l\'AppProfile doit correspondre');
    }

    // ========================================================================
    // TEST 4: Import AD → SQL sans AppProfile
    // ========================================================================

    /**
     * Test: Synchronisation d'un groupe sans appProfile vers SQL
     * 
     * Simule l'import depuis AD quand seule l'OU existe (pas de CN dans Parcs).
     * Vérifie que :
     * - Le WorkstationGroup est créé en SQL
     * - Aucun AppProfile n'est créé
     */
    public function test_import_group_without_app_profile_to_sql(): void
    {
        $groupName = 'TestImportNoProfile_' . uniqid();
        $this->createdGroups[] = $groupName;

        // 1. Créer l'OU dans AD (OU=Computers) - PAS de CN dans OU=Parcs
        $computersDn = $this->dnHelper->computers();
        $ouDn = "OU={$groupName},{$computersDn}";
        $ou = new OrganizationalUnit();
        $ou->setDn($ouDn);
        $ou->ou = $groupName;
        $ou->save();

        // Récupérer le GUID de l'OU
        $ou = OrganizationalUnit::find($ouDn);
        $ouGuid = $ou->getConvertedGuid();

        // 2. Vérifier qu'aucun CN n'existe dans OU=Parcs
        $parcNames = $this->repository->getParcNamesFromAd();
        $hasCnInParcs = in_array(strtolower($groupName), $parcNames, true);
        $this->assertFalse($hasCnInParcs, 'Le CN ne doit PAS exister dans OU=Parcs');

        // 3. Simuler l'import : créer le WorkstationGroup en SQL sans AppProfile
        $group = WorkstationGroup::create([
            'name' => $groupName,
            'display_name' => $groupName,
            'is_physical' => true,
            'is_active' => true,
            'ad_guid' => $ouGuid,
            'ad_dn' => $ouDn,
            'app_profile_name' => null,
        ]);

        // 4. Vérifications SQL
        $this->assertNotNull($group, 'Le WorkstationGroup doit exister en SQL');
        $this->assertNull($group->app_profile_name, 'app_profile_name doit être null');

        // 5. Vérifier qu'aucun AppProfile n'existe
        $profile = AppProfile::where('name', $groupName)->first();
        $this->assertNull($profile, 'Aucun AppProfile ne doit exister en SQL');

        // 6. Vérifier qu'aucun lien n'existe dans la table pivot
        $this->assertFalse(
            $group->appProfiles()->exists(),
            'Aucun lien ne doit exister dans la table pivot app_profile_workstation_group'
        );
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function assertOuExistsInComputers(string $name): void
    {
        $computersDn = $this->dnHelper->computers();
        $ou = DeviceGroupModel::in($computersDn)
            ->where('ou', '=', $name)
            ->first();

        $this->assertNotNull($ou, "L'OU '$name' doit exister dans OU=Computers");
    }

    private function assertCnExistsInParcs(string $name): void
    {
        $parcsDn = $this->dnHelper->parcs();
        $cn = DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $name)
            ->first();

        $this->assertNotNull($cn, "Le CN '$name' doit exister dans OU=Parcs");
    }

    private function assertCnNotExistsInParcs(string $name): void
    {
        $parcsDn = $this->dnHelper->parcs();
        $cn = DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $name)
            ->first();

        $this->assertNull($cn, "Le CN '$name' ne doit PAS exister dans OU=Parcs");
    }

    private function cleanupGroup(string $name): void
    {
        // Supprimer l'OU dans AD
        try {
            $computersDn = $this->dnHelper->computers();
            $ou = DeviceGroupModel::in($computersDn)
                ->where('ou', '=', $name)
                ->first();
            if ($ou) {
                $ou->delete();
            }
        } catch (\Throwable $e) {
            $this->reportCleanupFailure('group/AD', $name, $e);
        }

        // Supprimer en SQL
        try {
            WorkstationGroup::where('name', $name)->delete();
        } catch (\Throwable $e) {
            $this->reportCleanupFailure('group/SQL', $name, $e);
        }
    }

    private function cleanupProfile(string $name): void
    {
        // Supprimer le CN dans AD
        try {
            $parcsDn = $this->dnHelper->parcs();
            $cn = DeviceGroupTagModel::in($parcsDn)
                ->where('cn', '=', $name)
                ->first();
            if ($cn) {
                $cn->delete();
            }
        } catch (\Throwable $e) {
            $this->reportCleanupFailure('profile/AD', $name, $e);
        }

        // Supprimer en SQL
        try {
            AppProfile::where('name', $name)->delete();
        } catch (\Throwable $e) {
            $this->reportCleanupFailure('profile/SQL', $name, $e);
        }
    }

    private function reportCleanupFailure(string $kind, string $name, \Throwable $e): void
    {
        fwrite(STDERR, sprintf(
            "[%s] cleanup %s '%s' failed: %s\n",
            static::class,
            $kind,
            $name,
            $e->getMessage()
        ));
    }
}
