<?php

namespace Tests\Integration\LegacyLaravelComparison;

use Tests\TestCase;
use App\Models\WorkstationGroup;
use App\Models\AppProfile;
use App\Models\Workstation;
use App\Services\Parc\WorkstationGroupService;
use App\Services\AdSync\AdSyncService;
use App\Services\AdSync\AppProfileAdSyncService;
use App\Config\LdapDnHelper;
use App\LdapModels\DeviceGroupModel;
use App\LdapModels\DeviceGroupTagModel;
use App\Jobs\AdSync\WorkstationGroupAdSyncJob;
use Illuminate\Support\Facades\Queue;

/**
 * Test d'ajout d'une machine à un WorkstationGroup
 * 
 * ARCHITECTURE:
 * - WorkstationGroup crée une OU dans OU=Computers (salle physique)
 * - AppProfile crée un CN dans OU=Parcs (groupe de sécurité pour WPKG)
 * - L'appartenance des machines aux groupes est gérée UNIQUEMENT en SQL
 * - Pas de sync AD pour les membres (supprimée car redondante avec SQL)
 * 
 * Flux testé: Service -> SQL (pivot table)
 */
class WorkstationMembershipAddTest extends TestCase
{
    protected WorkstationGroupService $workstationGroupService;
    protected AdSyncService $adSyncService;
    protected AppProfileAdSyncService $appProfileAdSyncService;
    protected LdapDnHelper $dnHelper;
    protected array $config;

    /** @var array<int,string> Noms des groupes créés, nettoyés en tearDown (filet de sécurité). */
    protected array $createdGroups = [];
    /** @var array<int,string> Noms des profils créés, nettoyés en tearDown (filet de sécurité). */
    protected array $createdProfiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Charger la config legacy pour les tests AD
        $legacy_base = '/var/www/sambaedu';
        require_once $legacy_base . '/includes/config.inc.php';
        require_once $legacy_base . '/includes/ldap.inc.php';
        require_once $legacy_base . '/includes/samba-tool.inc.php';

        $this->config = get_config();
        $this->workstationGroupService = app(WorkstationGroupService::class);
        $this->adSyncService = app(AdSyncService::class);
        $this->appProfileAdSyncService = app(AppProfileAdSyncService::class);
        $this->dnHelper = app(LdapDnHelper::class);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdGroups as $name) {
            $this->cleanupGroup($name);
        }
        foreach ($this->createdProfiles as $name) {
            $this->cleanupProfile($name);
        }

        parent::tearDown();
    }

    /**
     * Test: Ajout d'une machine à un WorkstationGroup AVEC AppProfile lié
     * 
     * Vérifie que la relation SQL est créée correctement.
     * L'appartenance aux groupes est gérée uniquement en SQL (pas de sync AD).
     */
    public function test_add_machine_to_group_with_app_profile(): void
    {
        $groupName = 'TestAddMachineWithProfile_' . uniqid();
        $this->createdGroups[] = $groupName;
        $this->createdProfiles[] = $groupName;

        // 0. Nettoyer
        WorkstationGroup::where('name', 'like', 'TestAddMachineWithProfile_%')->delete();
        AppProfile::where('name', 'like', 'TestAddMachineWithProfile_%')->delete();
        
        // 1. Créer le WorkstationGroup AVEC app_profile_name
        $workstationGroup = $this->workstationGroupService->createGroup([
            'name' => $groupName,
            'description' => 'Test avec AppProfile',
            'app_profile_name' => $groupName,
            'parent_id' => null,
            'is_physical' => true,
            'is_active' => true,
        ]);
        
        // 2. Vérifier que l'AppProfile a été créé par l'Observer
        $appProfile = AppProfile::where('name', $groupName)->first();
        $this->assertNotNull($appProfile, 'L\'AppProfile doit être créé automatiquement');
        
        // 3. Synchroniser OU et CN vers AD
        $this->adSyncService->createWorkstationGroup($workstationGroup);
        $this->appProfileAdSyncService->createAppProfile($appProfile);
        
        // 4. Trouver une machine dans l'AD
        $machineName = $this->findAvailableMachine();
        if (!$machineName) {
            $this->markTestSkipped("Aucune machine disponible dans l'AD");
            return;
        }
        
        // 5. Créer le Workstation en SQL
        $workstation = Workstation::firstOrCreate(
            ['name' => $machineName],
            ['os' => 'Windows', 'status' => 'active']
        );
        
        // 6. Ajouter la machine au groupe via le service SQL
        $this->workstationGroupService->addMachineToGroup($workstation->id, $workstationGroup->id);
        
        // 7. Vérifier que la relation pivot existe en SQL
        $this->assertTrue(
            $workstationGroup->workstations()->where('workstations.id', $workstation->id)->exists(),
            'La relation pivot doit exister en SQL'
        );
        
        // 8. Vérifier que l'OU existe dans OU=Computers
        $this->assertOuExistsInComputers($groupName);
        
        // 9. Vérifier que le CN existe dans OU=Parcs
        $this->assertCnExistsInParcs($groupName);
        
        // 10. Nettoyage
        $this->appProfileAdSyncService->deleteAppProfile($groupName);
        $this->adSyncService->deleteWorkstationGroupByName($groupName);
        $this->workstationGroupService->deleteGroup($workstationGroup->id);
    }

    /**
     * Test: Ajout d'une machine à un WorkstationGroup SANS AppProfile lié
     * 
     * Vérifie que la relation SQL est créée correctement même sans AppProfile.
     * L'appartenance aux groupes est gérée uniquement en SQL.
     */
    public function test_add_machine_to_group_without_app_profile(): void
    {
        $groupName = 'TestAddMachineNoProfile_' . uniqid();
        $this->createdGroups[] = $groupName;
        // Les groupes physiques sans app_profile_name produisent quand même
        // un CN dans OU=Parcs (créé par syncToAd) — à nettoyer aussi.
        $this->createdProfiles[] = $groupName;

        // 0. Nettoyer
        WorkstationGroup::where('name', 'like', 'TestAddMachineNoProfile_%')->delete();
        
        // 1. Créer le WorkstationGroup SANS app_profile_name
        $workstationGroup = $this->workstationGroupService->createGroup([
            'name' => $groupName,
            'description' => 'Test sans AppProfile',
            'app_profile_name' => null,
            'parent_id' => null,
            'is_physical' => true,
            'is_active' => true,
        ]);
        
        // 2. Vérifier qu'aucun AppProfile n'a été créé
        $this->assertFalse(
            $workstationGroup->appProfiles()->exists(),
            'Aucun AppProfile ne doit être lié'
        );
        
        // 3. Synchroniser l'OU vers AD
        $this->adSyncService->createWorkstationGroup($workstationGroup);
        
        // 4. Trouver une machine dans l'AD
        $machineName = $this->findAvailableMachine();
        if (!$machineName) {
            $this->markTestSkipped("Aucune machine disponible dans l'AD");
            return;
        }
        
        // 5. Créer le Workstation en SQL
        $workstation = Workstation::firstOrCreate(
            ['name' => $machineName],
            ['os' => 'Windows', 'status' => 'active']
        );
        
        // 6. Ajouter la machine au groupe via le service SQL
        $this->workstationGroupService->addMachineToGroup($workstation->id, $workstationGroup->id);
        
        // 7. Vérifier que la relation pivot existe en SQL
        $this->assertTrue(
            $workstationGroup->workstations()->where('workstations.id', $workstation->id)->exists(),
            'La relation pivot doit exister en SQL'
        );
        
        // 8. Vérifier que l'OU existe dans OU=Computers
        $this->assertOuExistsInComputers($groupName);
        
        // 9. Vérifier que le CN existe dans OU=Parcs (les groupes physiques créent toujours un CN)
        $this->assertCnExistsInParcs($groupName);
        
        // 10. Nettoyage
        $this->adSyncService->deleteWorkstationGroupByName($groupName);
        $this->workstationGroupService->deleteGroup($workstationGroup->id);
    }

    /**
     * Trouve une machine disponible dans l'AD
     */
    private function findAvailableMachine(): ?string
    {
        $all_machines = search_ad($this->config, "*", "machine_fast", "all");
        if (!empty($all_machines)) {
            return $all_machines[0]['cn'];
        }
        return null;
    }

    /**
     * Vérifie qu'une OU existe dans OU=Computers
     */
    private function assertOuExistsInComputers(string $name): void
    {
        $computersDn = $this->dnHelper->computers();
        $ou = DeviceGroupModel::in($computersDn)
            ->where('ou', '=', $name)
            ->first();

        $this->assertNotNull($ou, "L'OU '$name' doit exister dans OU=Computers");
    }

    /**
     * Vérifie qu'un CN existe dans OU=Parcs
     */
    private function assertCnExistsInParcs(string $name): void
    {
        $parcsDn = $this->dnHelper->parcs();
        $cn = DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $name)
            ->first();

        $this->assertNotNull($cn, "Le CN '$name' doit exister dans OU=Parcs");
    }

    /**
     * Vérifie qu'un CN n'existe PAS dans OU=Parcs
     */
    private function assertCnNotExistsInParcs(string $name): void
    {
        $parcsDn = $this->dnHelper->parcs();
        $cn = DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $name)
            ->first();

        $this->assertNull($cn, "Le CN '$name' ne doit PAS exister dans OU=Parcs");
    }

    /**
     * Cleanup helpers — symétriques avec WorkstationGroupCreateTest /
     * WorkstationGroupAppProfileTest. Les exceptions sont logguées sur
     * STDERR plutôt qu'avalées : un cleanup partiel est visible en CI.
     */
    private function cleanupGroup(string $name): void
    {
        try {
            $this->adSyncService->deleteWorkstationGroupByName($name);
        } catch (\Throwable $e) {
            $this->reportCleanupFailure('group/AD', $name, $e);
        }

        try {
            WorkstationGroup::where('name', $name)->delete();
        } catch (\Throwable $e) {
            $this->reportCleanupFailure('group/SQL', $name, $e);
        }
    }

    private function cleanupProfile(string $name): void
    {
        try {
            $this->appProfileAdSyncService->deleteAppProfile($name);
        } catch (\Throwable $e) {
            $this->reportCleanupFailure('profile/AD', $name, $e);
        }

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
