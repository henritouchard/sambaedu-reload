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
 * Test de retrait d'une machine d'un WorkstationGroup
 * 
 * ARCHITECTURE:
 * - WorkstationGroup crée une OU dans OU=Computers (salle physique)
 * - AppProfile crée un CN dans OU=Parcs (groupe de sécurité pour WPKG)
 * - L'appartenance des machines aux groupes est gérée UNIQUEMENT en SQL
 * - Pas de sync AD pour les membres (supprimée car redondante avec SQL)
 * 
 * Flux testé: Service -> SQL (pivot table)
 */
class WorkstationMembershipRemoveTest extends TestCase
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
     * Test: Retrait d'une machine d'un WorkstationGroup AVEC AppProfile lié
     * 
     * Vérifie que la relation SQL est supprimée correctement.
     * L'appartenance aux groupes est gérée uniquement en SQL (pas de sync AD).
     */
    public function test_remove_machine_from_group_with_app_profile(): void
    {
        $groupName = 'TestRemoveMachineWithProfile_' . uniqid();
        $this->createdGroups[] = $groupName;
        $this->createdProfiles[] = $groupName;

        // 0. Nettoyer
        WorkstationGroup::where('name', 'like', 'TestRemoveMachineWithProfile_%')->delete();
        AppProfile::where('name', 'like', 'TestRemoveMachineWithProfile_%')->delete();
        
        // 1. Créer le WorkstationGroup AVEC app_profile_name
        $workstationGroup = $this->workstationGroupService->createGroup([
            'name' => $groupName,
            'description' => 'Test retrait avec AppProfile',
            'app_profile_name' => $groupName,
            'parent_id' => null,
            'is_physical' => true,
            'is_active' => true,
        ]);
        
        // 2. Vérifier que l'AppProfile a été créé
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
        
        // 6. Ajouter la machine au groupe (SQL uniquement)
        $this->workstationGroupService->addMachineToGroup($workstation->id, $workstationGroup->id);
        $this->assertTrue(
            $workstationGroup->workstations()->where('workstations.id', $workstation->id)->exists(),
            'La relation pivot doit exister après ajout'
        );
        
        // 7. Retirer la machine du groupe (SQL uniquement)
        $this->workstationGroupService->removeMachineFromGroup($workstation->id, $workstationGroup->id);
        $this->assertFalse(
            $workstationGroup->workstations()->where('workstations.id', $workstation->id)->exists(),
            'La relation pivot ne doit plus exister après retrait'
        );
        
        // 8. Vérifier que l'OU et le CN existent toujours
        $this->assertOuExistsInComputers($groupName);
        $this->assertCnExistsInParcs($groupName);
        
        // 9. Nettoyage
        $this->appProfileAdSyncService->deleteAppProfile($groupName);
        $this->adSyncService->deleteWorkstationGroupByName($groupName);
        $this->workstationGroupService->deleteGroup($workstationGroup->id);
    }

    /**
     * Test: Retrait d'une machine d'un WorkstationGroup SANS AppProfile lié
     * 
     * Vérifie que la relation SQL est supprimée correctement même sans AppProfile.
     * L'appartenance aux groupes est gérée uniquement en SQL.
     */
    public function test_remove_machine_from_group_without_app_profile(): void
    {
        $groupName = 'TestRemoveMachineNoProfile_' . uniqid();
        $this->createdGroups[] = $groupName;
        // Idem MembershipAdd : le groupe physique produit aussi un CN dans
        // OU=Parcs via syncToAd, à nettoyer en filet de sécurité.
        $this->createdProfiles[] = $groupName;

        // 0. Nettoyer
        WorkstationGroup::where('name', 'like', 'TestRemoveMachineNoProfile_%')->delete();
        
        // 1. Créer le WorkstationGroup SANS app_profile_name
        $workstationGroup = $this->workstationGroupService->createGroup([
            'name' => $groupName,
            'description' => 'Test retrait sans AppProfile',
            'app_profile_name' => null,
            'parent_id' => null,
            'is_physical' => true,
            'is_active' => true,
        ]);
        
        // 2. Synchroniser l'OU vers AD
        $this->adSyncService->createWorkstationGroup($workstationGroup);
        
        // 3. Trouver une machine dans l'AD
        $machineName = $this->findAvailableMachine();
        if (!$machineName) {
            $this->markTestSkipped("Aucune machine disponible dans l'AD");
            return;
        }
        
        // 4. Créer le Workstation en SQL
        $workstation = Workstation::firstOrCreate(
            ['name' => $machineName],
            ['os' => 'Windows', 'status' => 'active']
        );
        
        // 5. Ajouter la machine au groupe (SQL uniquement)
        $this->workstationGroupService->addMachineToGroup($workstation->id, $workstationGroup->id);
        $this->assertTrue(
            $workstationGroup->workstations()->where('workstations.id', $workstation->id)->exists(),
            'La relation pivot doit exister après ajout'
        );
        
        // 6. Retirer la machine du groupe (SQL uniquement)
        $this->workstationGroupService->removeMachineFromGroup($workstation->id, $workstationGroup->id);
        $this->assertFalse(
            $workstationGroup->workstations()->where('workstations.id', $workstation->id)->exists(),
            'La relation pivot ne doit plus exister après retrait'
        );
        
        // 7. Nettoyage
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
     * Cleanup helpers — symétriques avec les autres tests Legacy/Comparison.
     * Les exceptions sont logguées sur STDERR plutôt qu'avalées.
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
