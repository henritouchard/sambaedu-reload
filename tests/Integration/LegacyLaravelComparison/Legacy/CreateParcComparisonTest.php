<?php

namespace Tests\Integration\LegacyLaravelComparison;

use Tests\TestCase;
use App\Models\AppProfile;
use App\Services\AppProfile\AppProfileService;
use App\Services\AdSync\AppProfileAdSyncService;
use App\Config\LdapDnHelper;
use App\LdapModels\DeviceGroupTagModel;
use App\LdapModels\DeviceGroupModel;
use App\Jobs\AdSync\AppProfileAdSyncJob;
use Illuminate\Support\Facades\Queue;

/**
 * Test de création d'un AppProfile via Laravel
 * 
 * ARCHITECTURE:
 * - AppProfile crée un CN dans OU=Parcs (AD)
 * - WorkstationGroup crée une OU dans OU=Computers (AD)
 * - Le lien est dans la table pivot app_profile_workstation_group
 * 
 * Flux testé: Service -> Observer -> AD Sync
 */
class CreateParcComparisonTest extends TestCase
{
    protected AppProfileService $appProfileService;
    protected AppProfileAdSyncService $appProfileAdSyncService;
    protected LdapDnHelper $dnHelper;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->appProfileService = app(AppProfileService::class);
        $this->appProfileAdSyncService = app(AppProfileAdSyncService::class);
        $this->dnHelper = app(LdapDnHelper::class);
        
        Queue::fake();
    }

    /**
     * Test de création d'un AppProfile via le service (flux complet)
     * 
     * Flux testé: Service.createProfile -> Observer -> AD Sync manuel
     */
    public function test_create_app_profile_via_service(): void
    {
        $profileName = 'TestCreateAppProfile_' . uniqid();
        $profileDescription = 'Test Create AppProfile via Service';
        
        // 0. Nettoyer les AppProfiles de test existants
        AppProfile::where('name', 'like', 'TestCreateAppProfile_%')->delete();
        
        // 1. Créer l'AppProfile via le service
        $appProfile = $this->appProfileService->createProfile([
            'name' => $profileName,
            'display_name' => $profileName,
            'description' => $profileDescription,
            'is_active' => true,
        ]);
        
        // 2. Vérifier que l'AppProfile a été créé en SQL
        $this->assertNotNull($appProfile->id);
        $this->assertEquals($profileName, $appProfile->name);
        $this->assertEquals($profileDescription, $appProfile->description);
        
        // 3. Vérifier que l'Observer a dispatché le job de création AD
        // Queue::assertPushed() vérifie que le job a été mis en file d'attente par l'Observer
        Queue::assertPushed(AppProfileAdSyncJob::class, function ($job) use ($appProfile) {
            return $job->appProfileId === $appProfile->id && $job->action === 'create';
        });
        
        // 4. Synchroniser manuellement vers AD (les jobs sont fakés)
        $result = $this->appProfileAdSyncService->createAppProfile($appProfile);
        $this->assertTrue($result['success'], 'Échec création AppProfile AD: ' . ($result['error'] ?? ''));
        
        // 5. Vérifier la création du groupe CN dans OU=Parcs
        $this->assertCnExistsInParcs($profileName);
        
        // 6. Vérifier qu'il n'y a PAS d'OU (un AppProfile n'a pas d'OU)
        $this->assertOuNotExistsInComputers($profileName);
        
        // 7. Nettoyage
        $this->appProfileAdSyncService->deleteAppProfile($profileName);
        $this->appProfileService->deleteProfile($appProfile->id);
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
     * Vérifie qu'une OU n'existe PAS dans OU=Computers
     */
    private function assertOuNotExistsInComputers(string $name): void
    {
        $computersDn = $this->dnHelper->computers();
        $ou = DeviceGroupModel::in($computersDn)
            ->where('ou', '=', $name)
            ->first();

        $this->assertNull($ou, "L'OU '$name' ne doit PAS exister dans OU=Computers (AppProfile n'a pas d'OU)");
    }
}
