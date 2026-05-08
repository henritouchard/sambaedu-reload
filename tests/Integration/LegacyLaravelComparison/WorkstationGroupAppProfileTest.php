<?php

namespace Tests\Integration\LegacyLaravelComparison;

use App\Models\AppProfile;
use App\Models\WorkstationGroup;
use App\Services\AdSync\AdSyncService;
use App\Services\AdSync\AppProfileAdSyncService;
use App\Config\LdapDnHelper;
use App\LdapModels\DeviceGroupTagModel;
use App\LdapModels\DeviceGroupModel;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests de comportement automatique des AppProfiles lors des opérations sur WorkstationGroups
 * 
 * NOUVELLE ARCHITECTURE:
 * - WorkstationGroup crée une OU dans OU=Computers
 * - AppProfile crée un CN dans OU=Parcs
 * - Le lien est dans la table pivot app_profile_workstation_group
 * 
 * Vérifie que :
 * - La création d'un groupe avec app_profile_name crée automatiquement un AppProfile et le lien pivot
 * - La création d'un groupe sans app_profile_name ne crée PAS de AppProfile
 * - Le renommage d'un WorkstationGroup renomme aussi le AppProfile associé (si app_profile_name)
 * - La suppression d'un WorkstationGroup supprime aussi le AppProfile associé (si app_profile_name)
 */
class WorkstationGroupAppProfileTest extends TestCase
{
    protected AdSyncService $adSyncService;
    protected AppProfileAdSyncService $appProfileAdSyncService;
    protected LdapDnHelper $dnHelper;
    protected array $config;
    
    /** @var array Liste des noms de groupes créés pour le nettoyage */
    protected array $createdGroups = [];
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
        $this->adSyncService = app(AdSyncService::class);
        $this->appProfileAdSyncService = app(AppProfileAdSyncService::class);
        $this->dnHelper = app(LdapDnHelper::class);
        
        // Désactiver les queues pour exécution synchrone
        Queue::fake();
    }

    protected function tearDown(): void
    {
        // Nettoyage des groupes créés pendant les tests
        foreach ($this->createdGroups as $groupName) {
            $this->cleanupGroup($groupName);
        }
        foreach ($this->createdProfiles as $profileName) {
            $this->cleanupProfile($profileName);
        }
        
        parent::tearDown();
    }

    // ========================================================================
    // TEST 1: Création groupe avec app_profile_name → AppProfile automatique
    // ========================================================================

    /**
     * Test: La création d'un groupe avec app_profile_name rempli doit créer 
     * automatiquement un AppProfile du même nom et le lien pivot
     */
    public function test_create_group_with_app_profile_name_creates_appprofile_automatically()
    {
        $groupName = 'TestGroupeAvecAppProfile_' . uniqid();
        $this->createdGroups[] = $groupName;
        $this->createdProfiles[] = $groupName;
        
        echo "\n=== Test: Création groupe avec app_profile_name → AppProfile automatique ===\n";
        echo "Groupe: $groupName\n\n";
        
        // 1. Créer le groupe avec app_profile_name (l'Observer créera le AppProfile et le lien pivot)
        echo "1. Création du groupe avec app_profile_name via Observer...\n";
        $workstationGroup = WorkstationGroup::create([
            'name' => $groupName,
            'description' => 'Test groupe avec AppProfile automatique',
            'parent_id' => null,
            'app_profile_name' => $groupName,
            'is_physical' => true,
            'is_active' => true,
        ]);
        echo "✓ Groupe créé (ID: {$workstationGroup->id})\n";
        
        // 2. Vérifier que le AppProfile a été créé automatiquement en base
        echo "\n2. Vérification du AppProfile automatique en base...\n";
        $appProfile = AppProfile::where('name', $groupName)->first();
        
        $this->assertNotNull($appProfile, "Un AppProfile du même nom doit être créé automatiquement");
        echo "✓ AppProfile trouvé en base: {$appProfile->name} (ID: {$appProfile->id})\n";
        
        // 3. Vérifier que le lien pivot existe
        echo "\n3. Vérification du lien dans la table pivot...\n";
        $this->assertTrue(
            $workstationGroup->appProfiles()->where('app_profiles.id', $appProfile->id)->exists(),
            "Le lien doit exister dans la table pivot app_profile_workstation_group"
        );
        echo "✓ Lien pivot trouvé\n";
        
        // 4. Synchroniser manuellement vers AD (les jobs sont fakés)
        echo "\n4. Synchronisation manuelle vers AD...\n";
        $resultOu = $this->adSyncService->createWorkstationGroup($workstationGroup);
        $this->assertTrue($resultOu['success'], 'Création OU doit réussir: ' . ($resultOu['error'] ?? ''));
        echo "✓ OU créée dans OU=Computers\n";
        
        $resultCn = $this->appProfileAdSyncService->createAppProfile($appProfile);
        $this->assertTrue($resultCn['success'], 'Création CN doit réussir: ' . ($resultCn['error'] ?? ''));
        echo "✓ CN créé dans OU=Parcs\n";
        
        // 5. Vérifier que l'OU existe dans OU=Computers
        echo "\n5. Vérification de l'OU dans OU=Computers...\n";
        $computersDn = $this->dnHelper->computers();
        $ouAd = DeviceGroupModel::in($computersDn)
            ->where('ou', '=', $groupName)
            ->first();
        $this->assertNotNull($ouAd, "L'OU doit exister dans OU=Computers");
        echo "✓ OU trouvée dans AD: " . $ouAd->getDn() . "\n";
        
        // 6. Vérifier que le CN existe dans OU=Parcs
        echo "\n6. Vérification du CN dans OU=Parcs...\n";
        $parcsDn = $this->dnHelper->parcs();
        $cnAd = DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $groupName)
            ->first();
        $this->assertNotNull($cnAd, "Le CN doit exister dans OU=Parcs");
        echo "✓ CN trouvé dans AD: " . $cnAd->getDn() . "\n";
        
        echo "\n=== Test terminé avec succès ===\n";
    }

    // ========================================================================
    // TEST 2: Création groupe sans app_profile_name → PAS de AppProfile
    // ========================================================================

    /**
     * Test: La création d'un groupe sans app_profile_name ne doit PAS 
     * créer de AppProfile automatiquement
     */
    public function test_create_group_without_app_profile_name_does_not_create_appprofile()
    {
        $groupName = 'TestGroupeSansAppProfile_' . uniqid();
        $this->createdGroups[] = $groupName;
        
        echo "\n=== Test: Création groupe sans app_profile_name → PAS de AppProfile ===\n";
        echo "Groupe: $groupName\n\n";
        
        // 1. Créer le groupe sans app_profile_name via Observer
        echo "1. Création du groupe sans app_profile_name via Observer...\n";
        $workstationGroup = WorkstationGroup::create([
            'name' => $groupName,
            'description' => 'Test groupe sans AppProfile',
            'parent_id' => null,
            'app_profile_name' => null,
            'is_physical' => true,
            'is_active' => true,
        ]);
        echo "✓ Groupe créé (ID: {$workstationGroup->id})\n";
        
        // 2. Vérifier qu'AUCUN AppProfile n'a été créé
        echo "\n2. Vérification qu'aucun AppProfile n'a été créé...\n";
        $appProfile = AppProfile::where('name', $groupName)->first();
        
        $this->assertNull($appProfile, "Aucun AppProfile ne doit être créé pour un groupe sans app_profile_name");
        echo "✓ Aucun AppProfile créé (comportement attendu)\n";
        
        // 3. Vérifier qu'aucun lien pivot n'existe
        echo "\n3. Vérification qu'aucun lien pivot n'existe...\n";
        $this->assertFalse(
            $workstationGroup->appProfiles()->exists(),
            "Aucun lien ne doit exister dans la table pivot"
        );
        echo "✓ Aucun lien pivot (comportement attendu)\n";
        
        // 4. Synchroniser manuellement vers AD et vérifier l'OU (pas de CN car pas d'AppProfile)
        echo "\n4. Synchronisation manuelle vers AD...\n";
        $resultOu = $this->adSyncService->createWorkstationGroup($workstationGroup);
        $this->assertTrue($resultOu['success'], 'Création OU doit réussir: ' . ($resultOu['error'] ?? ''));
        
        $computersDn = $this->dnHelper->computers();
        $ouAd = DeviceGroupModel::in($computersDn)
            ->where('ou', '=', $groupName)
            ->first();
        $this->assertNotNull($ouAd, "L'OU doit exister dans OU=Computers");
        echo "✓ OU trouvée dans AD: " . $ouAd->getDn() . "\n";
        
        // 5. Vérifier que le CN existe dans OU=Parcs (les groupes physiques créent toujours un CN)
        echo "\n5. Vérification du CN dans OU=Parcs...\n";
        $parcsDn = $this->dnHelper->parcs();
        $cnAd = DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $groupName)
            ->first();
        $this->assertNotNull($cnAd, "Le CN doit exister dans OU=Parcs pour un groupe physique");
        echo "✓ CN trouvé dans OU=Parcs (comportement attendu pour groupe physique)\n";
        
        echo "\n=== Test terminé avec succès ===\n";
    }

    // ========================================================================
    // TEST 3: Renommage groupe avec app_profile_name → Renomme AppProfile associé
    // ========================================================================

    /**
     * Test: Le renommage d'un groupe avec app_profile_name doit renommer aussi le AppProfile 
     * si celui-ci porte le même nom que l'ancien app_profile_name
     */
    public function test_rename_group_with_app_profile_name_renames_associated_appprofile()
    {
        $oldName = 'TestGroupeRename_' . uniqid();
        $newName = 'TestGroupeRenamed_' . uniqid();
        $this->createdGroups[] = $newName; // On nettoie le nouveau nom
        
        echo "\n=== Test: Renommage groupe avec app_profile_name → Renomme AppProfile ===\n";
        echo "Ancien nom: $oldName\n";
        echo "Nouveau nom: $newName\n\n";
        
        // 1. Créer le groupe avec app_profile_name (l'Observer crée automatiquement le AppProfile)
        echo "1. Création du groupe initial avec app_profile_name via Observer...\n";
        $workstationGroup = WorkstationGroup::create([
            'name' => $oldName,
            'description' => 'Test renommage groupe avec AppProfile',
            'parent_id' => null,
            'app_profile_name' => $oldName,
            'is_physical' => true,
            'is_active' => true,
        ]);
        
        // Vérifier que le AppProfile a été créé
        $appProfile = AppProfile::where('name', $oldName)->first();
        $this->assertNotNull($appProfile, "Le AppProfile doit être créé automatiquement");
        echo "✓ Groupe et AppProfile créés (AppProfile ID: {$appProfile->id})\n";
        
        sleep(1); // Attendre la sync AD
        
        // 2. Renommer le WorkstationGroup ET son app_profile_name
        echo "\n2. Renommage du WorkstationGroup et app_profile_name via Observer...\n";
        $workstationGroup->name = $newName;
        $workstationGroup->app_profile_name = $newName;
        $workstationGroup->save();
        echo "✓ WorkstationGroup renommé\n";
        
        sleep(1); // Attendre la sync AD
        
        // 3. Vérifier que le AppProfile a été renommé aussi
        echo "\n3. Vérification du renommage du AppProfile...\n";
        
        // L'ancien AppProfile ne doit plus exister
        $oldAppProfile = AppProfile::where('name', $oldName)->first();
        $this->assertNull($oldAppProfile, "L'ancien AppProfile ne doit plus exister");
        echo "✓ Ancien AppProfile renommé\n";
        
        // Le nouveau AppProfile doit exister
        $newAppProfile = AppProfile::where('name', $newName)->first();
        $this->assertNotNull($newAppProfile, "Le nouveau AppProfile doit exister");
        echo "✓ Nouveau AppProfile trouvé: {$newAppProfile->name}\n";
        
        // 4. Vérifier le lien pivot avec le nouveau profil
        echo "\n4. Vérification du lien pivot...\n";
        $workstationGroup->refresh();
        $this->assertTrue(
            $workstationGroup->appProfiles()->where('app_profiles.name', $newName)->exists(),
            "Le lien pivot doit exister avec le nouveau profil"
        );
        echo "✓ Lien pivot mis à jour\n";
        
        echo "\n=== Test terminé avec succès ===\n";
        
        // Nettoyage
        $this->createdProfiles[] = $newName;
    }

    // ========================================================================
    // TEST 4: Ajout app_profile_name à un groupe existant → Crée AppProfile
    // ========================================================================

    /**
     * Test: L'ajout d'un app_profile_name à un groupe existant doit créer un AppProfile
     */
    public function test_add_app_profile_name_to_existing_group_creates_appprofile()
    {
        $groupName = 'TestGroupeAjoutAppProfile_' . uniqid();
        $appProfileName = 'AppProfile_' . $groupName;
        $this->createdGroups[] = $groupName;
        
        echo "\n=== Test: Ajout app_profile_name à un groupe existant → Crée AppProfile ===\n";
        echo "Groupe: $groupName\n";
        echo "AppProfile à créer: $appProfileName\n\n";
        
        // 1. Créer le groupe sans app_profile_name
        echo "1. Création du groupe sans app_profile_name...\n";
        $workstationGroup = WorkstationGroup::create([
            'name' => $groupName,
            'description' => 'Test ajout app_profile_name',
            'parent_id' => null,
            'app_profile_name' => null,
            'is_physical' => true,
            'is_active' => true,
        ]);
        echo "✓ Groupe créé (ID: {$workstationGroup->id})\n";
        
        // Vérifier qu'aucun AppProfile n'existe
        $appProfile = AppProfile::where('name', $appProfileName)->first();
        $this->assertNull($appProfile, "Aucun AppProfile ne doit exister initialement");
        echo "✓ Aucun AppProfile créé initialement\n";
        
        sleep(1); // Attendre la sync AD
        
        // 2. Ajouter app_profile_name au groupe
        echo "\n2. Ajout de app_profile_name au groupe...\n";
        $workstationGroup->app_profile_name = $appProfileName;
        $workstationGroup->save();
        echo "✓ app_profile_name ajouté\n";
        
        sleep(1); // Attendre la sync AD
        
        // 3. Vérifier que le AppProfile a été créé
        echo "\n3. Vérification de la création du AppProfile...\n";
        
        $newAppProfile = AppProfile::where('name', $appProfileName)->first();
        $this->assertNotNull($newAppProfile, "Le AppProfile doit être créé");
        echo "✓ AppProfile créé: {$newAppProfile->name}\n";
        
        // 4. Vérifier le lien pivot
        echo "\n4. Vérification du lien pivot...\n";
        $workstationGroup->refresh();
        $this->assertTrue(
            $workstationGroup->appProfiles()->where('app_profiles.name', $appProfileName)->exists(),
            "Le lien pivot doit exister"
        );
        echo "✓ Lien pivot créé\n";
        
        echo "\n=== Test terminé avec succès ===\n";
        
        // Nettoyage
        $this->createdProfiles[] = $appProfileName;
    }

    // ========================================================================
    // TEST 5: Suppression WorkstationGroup avec app_profile_name → Supprime AppProfile
    // ========================================================================

    /**
     * Test: La suppression d'un WorkstationGroup avec app_profile_name conserve le AppProfile.
     * 
     * L'Observer ne supprime PAS le AppProfile lors de la suppression du groupe
     * (choix de design : l'utilisateur pourrait recréer une salle et réutiliser le profil).
     */
    public function test_delete_workstation_group_with_app_profile_name_preserves_appprofile()
    {
        $groupName = 'TestGroupeDelete_' . uniqid();
        $this->createdProfiles[] = $groupName;
        
        echo "\n=== Test: Suppression WorkstationGroup avec app_profile_name → Conserve AppProfile ===\n";
        echo "Groupe: $groupName\n\n";
        
        // 1. Créer le groupe avec app_profile_name (l'Observer crée automatiquement le AppProfile)
        echo "1. Création du groupe avec app_profile_name via Observer...\n";
        $workstationGroup = WorkstationGroup::create([
            'name' => $groupName,
            'description' => 'Test suppression avec AppProfile',
            'parent_id' => null,
            'app_profile_name' => $groupName,
            'is_physical' => true,
            'is_active' => true,
        ]);
        
        // Vérifier que le AppProfile a été créé
        $appProfile = AppProfile::where('name', $groupName)->first();
        $this->assertNotNull($appProfile, "Le AppProfile doit être créé automatiquement");
        echo "✓ Groupe et AppProfile créés (AppProfile ID: {$appProfile->id})\n";
        
        sleep(1); // Attendre la sync AD
        
        // 2. Supprimer le WorkstationGroup
        echo "\n2. Suppression du WorkstationGroup via Observer...\n";
        $workstationGroup->delete();
        echo "✓ WorkstationGroup supprimé\n";
        
        sleep(1); // Attendre la sync AD
        
        // 3. Vérifier que le AppProfile est conservé (choix de design)
        echo "\n3. Vérification que le AppProfile est conservé...\n";
        
        $preservedAppProfile = AppProfile::where('name', $groupName)->first();
        $this->assertNotNull($preservedAppProfile, "Le AppProfile doit être conservé après suppression du groupe");
        echo "✓ AppProfile conservé (comportement attendu)\n";
        
        echo "\n=== Test terminé avec succès ===\n";
    }

    // ========================================================================
    // TEST 6: Suppression groupe sans app_profile_name → Pas de suppression AppProfile
    // ========================================================================

    /**
     * Test: La suppression d'un groupe sans app_profile_name ne doit PAS supprimer 
     * un AppProfile qui aurait le même nom (créé manuellement)
     */
    public function test_delete_group_without_app_profile_name_does_not_delete_manual_appprofile()
    {
        $groupName = 'TestGroupeDeleteSansAppProfileName_' . uniqid();
        
        echo "\n=== Test: Suppression groupe sans app_profile_name → Pas de suppression AppProfile manuel ===\n";
        echo "Groupe: $groupName\n\n";
        
        // 1. Créer le groupe sans app_profile_name
        echo "1. Création du groupe sans app_profile_name via Observer...\n";
        $workstationGroup = WorkstationGroup::create([
            'name' => $groupName,
            'description' => 'Test suppression groupe sans app_profile_name',
            'parent_id' => null,
            'app_profile_name' => null,
            'is_physical' => true,
            'is_active' => true,
        ]);
        echo "✓ Groupe créé (ID: {$workstationGroup->id})\n";
        
        // Créer manuellement un AppProfile avec le même nom (indépendant)
        echo "   Création manuelle d'un AppProfile avec le même nom...\n";
        $appProfile = AppProfile::create([
            'name' => $groupName,
            'description' => 'AppProfile indépendant',
            'is_active' => true,
        ]);
        echo "✓ AppProfile créé manuellement (ID: {$appProfile->id})\n";
        
        sleep(1); // Attendre la sync AD
        
        // 2. Supprimer le WorkstationGroup
        echo "\n2. Suppression du WorkstationGroup via Observer...\n";
        $workstationGroup->delete();
        echo "✓ WorkstationGroup supprimé\n";
        
        sleep(1); // Attendre la sync AD
        
        // 3. Vérifier que le AppProfile n'a PAS été supprimé (car pas lié via app_profile_name)
        echo "\n3. Vérification que le AppProfile manuel n'a PAS été supprimé...\n";
        
        $existingAppProfile = AppProfile::where('name', $groupName)->first();
        $this->assertNotNull($existingAppProfile, "Le AppProfile manuel ne doit PAS être supprimé");
        echo "✓ AppProfile manuel toujours présent (comportement attendu)\n";
        
        // Nettoyage manuel du AppProfile
        $existingAppProfile->delete();
        
        echo "\n=== Test terminé avec succès ===\n";
    }

    // ========================================================================
    // MÉTHODES UTILITAIRES
    // ========================================================================

    /**
     * Nettoie un groupe de test (WorkstationGroup et AD). Les exceptions
     * sont logguées sur STDERR au lieu d'être avalées silencieusement,
     * pour qu'un cleanup partiel n'accumule plus de résidus dans l'AD.
     */
    private function cleanupGroup(string $name): void
    {
        try {
            // Supprimer de l'AD (OU dans OU=Computers)
            $this->adSyncService->deleteWorkstationGroupByName($name);
        } catch (\Throwable $e) {
            $this->reportCleanupFailure('group/AD', $name, $e);
        }

        try {
            // Supprimer le WorkstationGroup de la base
            WorkstationGroup::where('name', $name)->delete();
        } catch (\Throwable $e) {
            $this->reportCleanupFailure('group/SQL', $name, $e);
        }
    }

    /**
     * Nettoie un profil de test (AppProfile et AD).
     */
    private function cleanupProfile(string $name): void
    {
        try {
            // Supprimer de l'AD (CN dans OU=Parcs)
            $this->appProfileAdSyncService->deleteAppProfile($name);
        } catch (\Throwable $e) {
            $this->reportCleanupFailure('profile/AD', $name, $e);
        }

        try {
            // Supprimer le AppProfile de la base
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
