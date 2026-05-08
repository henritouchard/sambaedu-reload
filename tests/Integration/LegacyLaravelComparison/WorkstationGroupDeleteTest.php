<?php

namespace Tests\Integration\LegacyLaravelComparison;

use Tests\TestCase;
use App\Models\WorkstationGroup;
use App\Services\Parc\WorkstationGroupService;
use App\Services\AdSync\AdSyncService;
use App\Config\LdapDnHelper;
use App\LdapModels\DeviceGroupModel;
use App\Jobs\AdSync\WorkstationGroupAdSyncJob;
use Illuminate\Support\Facades\Queue;

/**
 * Test de suppression d'un WorkstationGroup de l'AD
 *
 * NOUVELLE ARCHITECTURE:
 * - WorkstationGroup crée/supprime une OU dans OU=Computers
 * - AppProfile crée/supprime un CN dans OU=Parcs
 *
 * Ce test vérifie que Laravel supprime correctement une OU de OU=Computers.
 */
class WorkstationGroupDeleteTest extends TestCase
{
    protected WorkstationGroupService $workstationGroupService;
    protected AdSyncService $adSyncService;
    protected LdapDnHelper $dnHelper;

    /**
     * @var array<int,string> Noms des groupes créés. Les tests les
     * suppriment eux-mêmes en chemin nominal ; ce tableau alimente un
     * filet de sécurité au tearDown si une assertion échoue avant la
     * suppression et laisse un résidu dans l'AD.
     */
    protected array $createdGroups = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->workstationGroupService = app(WorkstationGroupService::class);
        $this->adSyncService = app(AdSyncService::class);
        $this->dnHelper = app(LdapDnHelper::class);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdGroups as $name) {
            $this->cleanupGroup($name);
        }

        parent::tearDown();
    }

    /**
     * Test de suppression d'un WorkstationGroup via le service (flux complet)
     * 
     * Flux testé: Service.createGroup -> AD Sync -> Service.deleteGroup -> AD Sync
     */
    public function test_delete_workstation_group_via_service(): void
    {
        $groupName = 'TestDeleteGroup_' . uniqid();
        $this->createdGroups[] = $groupName;

        // 1. Créer le groupe via le service
        $workstationGroup = $this->workstationGroupService->createGroup([
            'name' => $groupName,
            'description' => 'Test suppression groupe',
            'app_profile_name' => null,
            'parent_id' => null,
            'is_physical' => true,
            'is_active' => true,
        ]);
        
        // 2. Vérifier que l'Observer a dispatché le job de création AD
        Queue::assertPushed(WorkstationGroupAdSyncJob::class, function ($job) use ($workstationGroup) {
            return $job->workstationGroupId === $workstationGroup->id && $job->action === 'create';
        });
        
        // 3. Synchroniser manuellement vers AD (les jobs sont fakés)
        $result = $this->adSyncService->createWorkstationGroup($workstationGroup);
        $this->assertTrue($result['success'], 'La création doit réussir');
        
        // 4. Vérifier que l'OU existe dans l'AD
        $this->assertOuExists($groupName);
        
        // Vérifier qu'aucun lien n'existe dans la table pivot
        $this->assertFalse(
            $workstationGroup->appProfiles()->exists(),
            'Aucun lien ne doit exister dans la table pivot'
        );
        
        // 5. Supprimer le groupe via le service AD puis SQL
        $deleteResult = $this->adSyncService->deleteWorkstationGroupByName($groupName);
        $this->assertTrue($deleteResult['success'], 'La suppression AD doit réussir');
        
        $groupId = $workstationGroup->id;
        $this->workstationGroupService->deleteGroup($groupId);
        
        // 6. Vérifier que l'Observer a dispatché le job de suppression AD
        // Note: Le job de suppression utilise le nom du groupe (pas l'ID) car le modèle est supprimé
        Queue::assertPushed(WorkstationGroupAdSyncJob::class, function ($job) use ($groupName) {
            return $job->action === 'delete' && ($job->params['name'] ?? '') === $groupName;
        });
        
        // 7. Vérifier la suppression dans l'AD
        $this->assertOuNotExists($groupName);
        
        // 8. Vérifier la suppression en SQL
        $this->assertNull(WorkstationGroup::find($groupId));
    }

    /**
     * Test de suppression d'un groupe avec enfants via le service (hiérarchie SQL)
     */
    public function test_delete_parent_group_with_children_via_service(): void
    {
        $parentName = 'TestDeleteParent_' . uniqid();
        $childName = 'TestDeleteChild_' . uniqid();
        $this->createdGroups[] = $parentName;
        $this->createdGroups[] = $childName;

        // 1. Créer le parent et l'enfant via le service
        $parentGroup = $this->workstationGroupService->createGroup([
            'name' => $parentName,
            'description' => 'Groupe parent',
            'app_profile_name' => null,
            'parent_id' => null,
            'is_physical' => true,
            'is_active' => true,
        ]);
        $this->adSyncService->createWorkstationGroup($parentGroup);
        
        $childGroup = $this->workstationGroupService->createGroup([
            'name' => $childName,
            'description' => 'Groupe enfant',
            'app_profile_name' => null,
            'parent_id' => $parentGroup->id,
            'is_physical' => true,
            'is_active' => true,
        ]);
        $this->adSyncService->createWorkstationGroup($childGroup);
        
        // 2. Vérifier que les jobs de création AD ont été dispatchés
        Queue::assertPushed(WorkstationGroupAdSyncJob::class, 2);
        
        // 3. Vérifier la hiérarchie SQL
        $this->assertEquals($parentGroup->id, $childGroup->parent_id);
        
        // Vérifier qu'aucun lien n'existe dans la table pivot
        $this->assertFalse($parentGroup->appProfiles()->exists());
        $this->assertFalse($childGroup->appProfiles()->exists());
        
        // 4. Supprimer l'enfant d'abord via le service
        $childId = $childGroup->id;
        $this->adSyncService->deleteWorkstationGroupByName($childName);
        $this->workstationGroupService->deleteGroup($childId);
        $this->assertOuNotExists($childName);
        $this->assertNull(WorkstationGroup::find($childId));
        
        // 5. Supprimer le parent via le service
        $parentId = $parentGroup->id;
        $this->adSyncService->deleteWorkstationGroupByName($parentName);
        $this->workstationGroupService->deleteGroup($parentId);
        $this->assertOuNotExists($parentName);
        $this->assertNull(WorkstationGroup::find($parentId));
        
        // 6. Vérifier que les jobs de suppression AD ont été dispatchés
        // 2 créations + 2 suppressions = 4 jobs au total
        Queue::assertPushed(WorkstationGroupAdSyncJob::class, 4);
    }

    /**
     * Vérifie qu'une OU existe dans OU=Computers
     */
    private function assertOuExists(string $name): void
    {
        $computersDn = $this->dnHelper->computers();
        $ou = DeviceGroupModel::in($computersDn)
            ->where('ou', '=', $name)
            ->first();

        $this->assertNotNull($ou, "L'OU '$name' doit exister dans OU=Computers");
    }

    /**
     * Vérifie qu'une OU n'existe pas dans OU=Computers
     */
    private function assertOuNotExists(string $name): void
    {
        $computersDn = $this->dnHelper->computers();
        $ou = DeviceGroupModel::in($computersDn)
            ->where('ou', '=', $name)
            ->first();

        $this->assertNull($ou, "L'OU '$name' ne doit plus exister dans OU=Computers");
    }

    /**
     * Cleanup helper — symétrique avec les autres tests Legacy/Comparison.
     * Le test supprime déjà le groupe en chemin nominal ; ce helper rattrape
     * uniquement les cas où une assertion échoue avant la suppression. Une
     * « not found » au cleanup d'un test passant est attendue (logguée mais
     * inoffensive).
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
