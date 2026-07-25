<?php

namespace Tests\Integration\LegacyLaravelComparison;

use Tests\TestCase;
use App\Models\WorkstationGroup;
use App\Models\AppProfile;
use App\Services\Parc\WorkstationGroupService;
use App\Services\AdSync\AdSyncService;
use App\Config\LdapDnHelper;
use App\Config\SambaEduConfig;
use App\LdapModels\DeviceGroupTagModel;
use App\LdapModels\DeviceGroupModel;
use App\Jobs\AdSync\WorkstationGroupAdSyncJob;
use Illuminate\Support\Facades\Queue;

/**
 * Test de création d'un WorkstationGroup dans l'AD
 *
 * NOUVELLE ARCHITECTURE:
 * - WorkstationGroup crée une OU dans OU=Computers (salle physique)
 * - AppProfile crée un CN dans OU=Parcs (profil applicatif)
 *
 * Ce test vérifie que Laravel crée correctement une OU dans OU=Computers.
 *
 * =============================================================================
 * OPÉRATION: Création de l'OU dans OU=Computers
 * =============================================================================
 * DN: OU={nom},OU=Computers,DC=sambaedu,DC=org
 *
 * Attributs écrits:
 *   - ou: "{nom}"
 *   - objectclass: ["top", "organizationalUnit"]
 *   - description: "{description}" (optionnel)
 */
class WorkstationGroupCreateTest extends TestCase
{
    private WorkstationGroupService $workstationGroupService;
    private AdSyncService $adSyncService;
    private LdapDnHelper $dnHelper;
    private SambaEduConfig $config;
    private array $createdGroups = [];
    private array $createdProfiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->workstationGroupService = app(WorkstationGroupService::class);
        $this->adSyncService = app(AdSyncService::class);
        $this->dnHelper = app(LdapDnHelper::class);
        $this->config = app(SambaEduConfig::class);

        // Story 38.7 — test d'intégration AD réel : se skippe hors annuaire (HÔTE).
        $this->skipUnlessAdReachable();

        // Les observers restent actifs pour tester le comportement normal
        // Queue::fake() pour éviter l'exécution async des jobs
        Queue::fake();
    }

    /**
     * Skip si l'annuaire n'est pas joignable (les tests HÔTE n'ont pas d'AD).
     */
    private function skipUnlessAdReachable(): void
    {
        try {
            DeviceGroupModel::in($this->dnHelper->computers())->limit(1)->get();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Annuaire AD injoignable (test d\'intégration OU=Computers).');
        }
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
     * Test: Création d'un WorkstationGroup via le service (flux complet)
     * 
     * Flux testé: Service -> Observer -> Job (faké) -> AD Sync manuel
     */
    public function test_create_workstation_group_via_service(): void
    {
        $groupName = 'TestGroupCreate_' . uniqid();
        $this->createdGroups[] = $groupName;
        $description = 'Groupe de test créé via service';

        // 1. Créer le WorkstationGroup via le service (sans app_profile_name)
        $workstationGroup = $this->workstationGroupService->createGroup([
            'name' => $groupName,
            'description' => $description,
            'app_profile_name' => null,
            'parent_id' => null,
            'is_physical' => true,
            'is_active' => true,
        ]);

        // 2. Vérifier que le groupe a été créé en SQL
        $this->assertNotNull($workstationGroup->id, 'Le groupe doit avoir un ID');
        $this->assertEquals($groupName, $workstationGroup->name);
        $this->assertEquals($description, $workstationGroup->description);

        // 3. Vérifier que l'Observer a dispatché le job de synchronisation AD
        // Queue::assertPushed() vérifie que le job a été mis en file d'attente par l'Observer
        // Même si le job n'est pas exécuté (Queue::fake()), on s'assure que le flux est correct
        Queue::assertPushed(WorkstationGroupAdSyncJob::class, function ($job) use ($workstationGroup) {
            return $job->workstationGroupId === $workstationGroup->id && $job->action === 'create';
        });

        // 4. Vérifier qu'aucun AppProfile n'a été créé (pas d'app_profile_name)
        $this->assertFalse(
            $workstationGroup->appProfiles()->exists(),
            'Aucun lien ne doit exister dans la table pivot car app_profile_name est null'
        );

        // 5. Synchroniser manuellement vers AD (les jobs sont fakés, donc on exécute la sync nous-mêmes)
        $result = $this->adSyncService->createWorkstationGroup($workstationGroup);
        $this->assertTrue($result['success'], 'La création AD doit réussir: ' . ($result['error'] ?? ''));
        $this->assertNotEmpty($result['guid'], 'Le GUID de l\'OU doit être retourné');
        $this->assertNotEmpty($result['dn'], 'Le DN de l\'OU doit être retourné');

        // 6. Vérifier que l'OU existe dans OU=Computers
        $this->assertOuExistsInComputers($groupName);
    }

    /**
     * Test: Création d'un groupe avec parent via le service (hiérarchie SQL)
     */
    public function test_create_workstation_group_with_parent_via_service(): void
    {
        $parentName = 'TestGroupParent_' . uniqid();
        $childName = 'TestGroupChild_' . uniqid();
        $this->createdGroups[] = $parentName;
        $this->createdGroups[] = $childName;

        // 1. Créer le groupe parent via le service
        $parentGroup = $this->workstationGroupService->createGroup([
            'name' => $parentName,
            'description' => 'Groupe parent',
            'app_profile_name' => null,
            'parent_id' => null,
            'is_physical' => true,
            'is_active' => true,
        ]);
        $this->adSyncService->createWorkstationGroup($parentGroup);

        // 2. Créer le groupe enfant via le service
        $childGroup = $this->workstationGroupService->createGroup([
            'name' => $childName,
            'description' => 'Groupe enfant',
            'app_profile_name' => null,
            'parent_id' => $parentGroup->id,
            'is_physical' => true,
            'is_active' => true,
        ]);
        $result = $this->adSyncService->createWorkstationGroup($childGroup);

        // 3. Vérifier le succès
        $this->assertTrue($result['success'], 'La création doit réussir');

        // 4. Vérifier que les jobs de sync AD ont été dispatchés pour les deux groupes
        Queue::assertPushed(WorkstationGroupAdSyncJob::class, 2);

        // 5. Vérifier la hiérarchie SQL
        $this->assertEquals($parentGroup->id, $childGroup->parent_id);
        $this->assertNull($parentGroup->parent_id);

        // 6. Les deux OU doivent exister dans OU=Computers (structure plate dans AD)
        $this->assertOuExistsInComputers($parentName);
        $this->assertOuExistsInComputers($childName);

        // 7. Vérifier qu'aucun lien n'existe dans la table pivot
        $this->assertFalse($parentGroup->appProfiles()->exists());
        $this->assertFalse($childGroup->appProfiles()->exists());
    }

    /**
     * Story 38.7 — Création d'un groupe avec `app_profile_name` rempli : l'Observer
     * ne crée PLUS d'AppProfile ni de lien pivot, et aucun CN n'est écrit dans
     * OU=Parcs (conteneur en lecture seule). Seule l'OU sous OU=Computers est créée.
     */
    public function test_create_workstation_group_with_app_profile_name_creates_no_profile_and_no_cn(): void
    {
        $groupName = 'TestGroupWithProfile_' . uniqid();
        $this->createdGroups[] = $groupName;

        $workstationGroup = $this->workstationGroupService->createGroup([
            'name' => $groupName,
            'display_name' => $groupName,
            'description' => 'Groupe de test avec profil applicatif',
            'app_profile_name' => $groupName,
            'parent_id' => null,
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->assertNotNull($workstationGroup->id);
        // Le champ reste stocké (inerte), mais aucun AppProfile n'en découle.
        $this->assertEquals($groupName, $workstationGroup->app_profile_name);

        // Groupe physique → job de sync AD pour l'OU (OU=Computers).
        Queue::assertPushed(WorkstationGroupAdSyncJob::class, function ($job) use ($workstationGroup) {
            return $job->workstationGroupId === $workstationGroup->id && $job->action === 'create';
        });

        // Aucune création automatique d'AppProfile ni de lien pivot (AC8).
        $this->assertNull(AppProfile::where('name', $groupName)->first(), 'Aucun AppProfile ne doit être créé automatiquement (38.7).');
        $this->assertFalse($workstationGroup->appProfiles()->exists(), 'Aucun lien pivot ne doit être posé.');

        // Sync AD : l'OU est créée dans OU=Computers…
        $resultGroup = $this->adSyncService->createWorkstationGroup($workstationGroup);
        $this->assertTrue($resultGroup['success'], 'Création OU doit réussir: ' . ($resultGroup['error'] ?? ''));
        $this->assertOuExistsInComputers($groupName);

        // …mais AUCUN CN n'est écrit dans OU=Parcs (lecture seule — 38.7).
        $this->assertCnNotExistsInParcs($groupName);
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

        // Vérifier le nom de l'OU
        $ouName = $ou->ou[0] ?? $ou->ou;
        $this->assertEquals($name, $ouName, 'OU doit correspondre au nom');
    }

    /**
     * Story 38.7 — vérifie qu'AUCUN CN n'existe dans OU=Parcs (lecture seule).
     */
    private function assertCnNotExistsInParcs(string $name): void
    {
        $parcsDn = $this->dnHelper->parcs();
        $cn = DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $name)
            ->first();

        $this->assertNull($cn, "Aucun CN '$name' ne doit exister dans OU=Parcs (conteneur en lecture seule — 38.7)");
    }

    /**
     * Nettoie un groupe de test. Les exceptions sont logguées sur STDERR
     * (visibles en CI / PHPUnit) sans échouer le tearDown, pour qu'un
     * cleanup partiel n'avale plus silencieusement les résidus AD.
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

    /**
     * Nettoie un profil de test (idem politique de logging).
     */
    private function cleanupProfile(string $name): void
    {
        try {
            // Story 38.7 — plus de service d'écriture AD : suppression directe du
            // CN résiduel s'il en subsiste un (créé avant 38.7).
            DeviceGroupTagModel::in($this->dnHelper->parcs())
                ->where('cn', '=', $name)
                ->first()?->delete();
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
