#!/usr/bin/env php
<?php
/**
 * Script de comparaison automatique - Suppression de parc/salle
 * 
 * Compare les résultats de suppression entre Legacy et Laravel
 * 
 * Usage: php compare_delete_parc.php
 */

// Chargement de l'environnement legacy
$legacy_base = '/var/www/sambaedu';
require_once $legacy_base . '/includes/config.inc.php';
require_once $legacy_base . '/includes/ldap.inc.php';
require_once $legacy_base . '/includes/samba-tool.inc.php';

$config = get_config();

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  Comparaison Legacy vs Laravel - Suppression de parc/salle    ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// ============================================================================
// PHASE 1: Test Legacy - Suppression d'un parc
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 1: Test Legacy - Suppression d'un parc                   │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

$legacy_parc_nom = 'TestDeleteParcLegacy_' . time();

// Créer le parc legacy
// create_parc() avec type='parc' crée UNIQUEMENT un groupe CN dans OU=Parcs
// Ce test vérifie que delete_parc() supprime correctement ce CN
echo "1. Création du parc legacy '$legacy_parc_nom'...\n";
$result = create_parc($config, $legacy_parc_nom, 'Test Delete Parc Legacy', '', 'parc');
if (!$result) {
    echo "❌ Erreur lors de la création du parc legacy\n";
    exit(1);
}
echo "✓ Parc legacy créé\n\n";

sleep(1);

// Vérifier l'existence avant suppression
echo "2. Vérification avant suppression...\n";
$parc_info_legacy = search_parcs($config, $legacy_parc_nom);
if (empty($parc_info_legacy) || !isset($parc_info_legacy[0]['gdn'])) {
    echo "❌ Le groupe CN n'existe pas avant suppression\n";
    exit(1);
}
$cn_dn_legacy = $parc_info_legacy[0]['gdn'];
echo "✓ Groupe CN existe: $cn_dn_legacy\n\n";

// Supprimer le parc legacy
// delete_parc() supprime le groupe CN de OU=Parcs
// Signature: delete_parc($config, $parc)
// - Recherche le parc via search_parcs()
// - Supprime le CN via groupdel()
// - Pour une salle: supprime aussi l'OU et gère les délégations
echo "3. Suppression du parc legacy...\n";
$result = delete_parc($config, $legacy_parc_nom);
if (!$result) {
    echo "❌ Erreur lors de la suppression du parc legacy\n";
    exit(1);
}
echo "✓ Parc legacy supprimé\n\n";

sleep(1);

// Vérifier la suppression
echo "4. Vérification après suppression...\n";
$cn_after_legacy = search_ad($config, $cn_dn_legacy, "dn");
$legacy_cn_deleted = empty($cn_after_legacy);

if ($legacy_cn_deleted) {
    echo "✓ Groupe CN supprimé\n";
} else {
    echo "❌ Groupe CN existe encore\n";
}

echo "\n";

// ============================================================================
// PHASE 2: Test Legacy - Suppression d'une salle
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 2: Test Legacy - Suppression d'une salle                 │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

$legacy_salle_nom = 'TestDeleteSalleLegacy_' . time();

// Créer la salle legacy
// create_parc() avec type='salle' crée:
// - Un groupe CN dans OU=Parcs (pour WPKG et délégations)
// - Une OU dans OU=Computers (pour GPO et organisation des machines)
// Le paramètre 'Computers' indique le parent de l'OU (racine OU=Computers)
echo "1. Création de la salle legacy '$legacy_salle_nom'...\n";
$result = create_parc($config, $legacy_salle_nom, 'Test Delete Salle Legacy', 'Computers', 'salle');
if (!$result) {
    echo "❌ Erreur lors de la création de la salle legacy\n";
    exit(1);
}
echo "✓ Salle legacy créée\n\n";

sleep(1);

// Vérifier l'existence avant suppression - construire les DN directement
echo "2. Vérification avant suppression...\n";
$cn_dn_legacy_salle = "CN={$legacy_salle_nom},{$config['dn']['parcs']}";
$ou_dn_legacy_salle = "OU={$legacy_salle_nom},{$config['dn']['computers']}";

$cn_before = search_ad($config, $cn_dn_legacy_salle, "dn");
$ou_before = search_ad($config, $ou_dn_legacy_salle, "dn");

if (empty($cn_before) || empty($ou_before)) {
    echo "❌ Le groupe CN ou l'OU n'existe pas avant suppression\n";
    echo "  - CN attendu: $cn_dn_legacy_salle (trouvé: " . (!empty($cn_before) ? "Oui" : "Non") . ")\n";
    echo "  - OU attendu: $ou_dn_legacy_salle (trouvé: " . (!empty($ou_before) ? "Oui" : "Non") . ")\n";
    // Continuer quand même pour tester Laravel
} else {
    echo "✓ Groupe CN: $cn_dn_legacy_salle\n";
    echo "✓ OU: $ou_dn_legacy_salle\n\n";
}

// Supprimer la salle legacy
// delete_parc() pour une salle:
// - Supprime le groupe CN de OU=Parcs
// - Supprime l'OU de OU=Computers (après déplacement des machines enfants)
// - Gère les délégations associées
echo "3. Suppression de la salle legacy...\n";
$result = delete_parc($config, $legacy_salle_nom);
if (!$result) {
    echo "❌ Erreur lors de la suppression de la salle legacy\n";
    exit(1);
}
echo "✓ Salle legacy supprimée\n\n";

sleep(1);

// Vérifier la suppression
echo "4. Vérification après suppression...\n";
$cn_after_legacy_salle = search_ad($config, $cn_dn_legacy_salle, "dn");
$ou_after_legacy_salle = search_ad($config, $ou_dn_legacy_salle, "dn");

$legacy_salle_cn_deleted = empty($cn_after_legacy_salle);
$legacy_salle_ou_deleted = empty($ou_after_legacy_salle);

if ($legacy_salle_cn_deleted) {
    echo "✓ Groupe CN supprimé\n";
} else {
    echo "❌ Groupe CN existe encore\n";
}

if ($legacy_salle_ou_deleted) {
    echo "✓ OU supprimé\n";
} else {
    echo "❌ OU existe encore\n";
}

echo "\n";

// ============================================================================
// PHASE 3: Test Laravel - Suppression d'un parc
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 3: Test Laravel - Suppression d'un parc                  │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Bootstrap Laravel
require_once '/root/se4/sources/var/www/sambaedu/laravel/vendor/autoload.php';
$app = require_once '/root/se4/sources/var/www/sambaedu/laravel/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\AdSync\AdSyncService;
use App\Services\AdSync\AppProfileAdSyncService;
use App\Models\WorkstationGroup;
use App\Models\AppProfile;
use App\Observers\WorkstationGroupObserver;
use App\Observers\AppProfileObserver;

$laravel_parc_nom = 'TestDeleteParcLaravel_' . time();
$laravel_parc_success = false;

// Désactiver les observers pour contrôler manuellement la sync
WorkstationGroupObserver::disableSync();
AppProfileObserver::disableSync();

try {
    // 1. Créer le parc Laravel (AppProfile uniquement, pas de salle physique)
    // Un "parc" dans la nouvelle architecture = AppProfile (CN dans OU=Parcs)
    echo "1. Création du parc Laravel '$laravel_parc_nom'...\n";
    
    WorkstationGroup::where('name', $laravel_parc_nom)->delete();
    AppProfile::where('name', $laravel_parc_nom)->delete();
    
    // Créer l'AppProfile (équivalent d'un parc legacy)
    $appProfile = AppProfile::create([
        'name' => $laravel_parc_nom,
        'display_name' => $laravel_parc_nom,
        'description' => 'Test Delete Parc Laravel',
        'is_active' => true,
    ]);
    
    $appProfileAdSyncService = app(AppProfileAdSyncService::class);
    $result = $appProfileAdSyncService->createAppProfile($appProfile);
    
    if (!$result['success']) {
        echo "❌ Erreur création parc: {$result['error']}\n";
    } else {
        $appProfile->update(['ad_guid' => $result['guid']]);
        echo "✓ Parc Laravel créé (ID: {$appProfile->id})\n\n";
        
        sleep(1);
        
        // 2. Vérifier existence avant suppression
        echo "2. Vérification avant suppression...\n";
        $dnHelper = app(\App\Config\LdapDnHelper::class);
        $parcsDn = $dnHelper->parcs();
        $groupAd = \App\LdapModels\DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $laravel_parc_nom)
            ->first();
        
        if ($groupAd) {
            echo "✓ Groupe CN existe: " . $groupAd->getDn() . "\n\n";
            
            // 3. Supprimer le parc
            echo "3. Suppression du parc Laravel...\n";
            $deleteResult = $appProfileAdSyncService->deleteAppProfile($laravel_parc_nom);
            $appProfile->delete();
            
            if ($deleteResult['success']) {
                echo "✓ Parc Laravel supprimé\n\n";
                
                sleep(1);
                
                // 4. Vérifier suppression
                echo "4. Vérification après suppression...\n";
                $groupAdAfter = \App\LdapModels\DeviceGroupTagModel::in($parcsDn)
                    ->where('cn', '=', $laravel_parc_nom)
                    ->first();
                
                if (!$groupAdAfter) {
                    echo "✓ Groupe CN supprimé\n";
                    $laravel_parc_success = true;
                } else {
                    echo "❌ Groupe CN existe encore\n";
                }
            } else {
                echo "❌ Erreur suppression: {$deleteResult['error']}\n";
            }
        } else {
            echo "❌ Groupe CN non trouvé dans l'AD\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Exception Laravel: " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================================
// PHASE 4: Test Laravel - Suppression d'une salle
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 4: Test Laravel - Suppression d'une salle                │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

$laravel_salle_nom = 'TestDeleteSalleLaravel_' . time();
$laravel_salle_success = false;

try {
    // 1. Créer la salle Laravel (WorkstationGroup + AppProfile)
    // Une "salle" dans la nouvelle architecture = WorkstationGroup (OU) + AppProfile (CN)
    echo "1. Création de la salle Laravel '$laravel_salle_nom'...\n";
    
    WorkstationGroup::where('name', $laravel_salle_nom)->delete();
    AppProfile::where('name', $laravel_salle_nom)->delete();
    
    // Créer le WorkstationGroup (OU dans OU=Computers)
    $workstationGroupSalle = WorkstationGroup::create([
        'name' => $laravel_salle_nom,
        'display_name' => $laravel_salle_nom,
        'description' => 'Test Delete Salle Laravel',
        'parent_id' => null,
        'is_physical' => true,
        'is_active' => true,
        'app_profile_name' => $laravel_salle_nom,
    ]);
    
    $adSyncService = app(AdSyncService::class);
    $appProfileAdSyncService = app(AppProfileAdSyncService::class);
    
    $resultOu = $adSyncService->createWorkstationGroup($workstationGroupSalle);
    
    if (!$resultOu['success']) {
        echo "❌ Erreur création OU: {$resultOu['error']}\n";
    } else {
        $workstationGroupSalle->update(['ad_guid' => $resultOu['guid'], 'ad_dn' => $resultOu['dn']]);
        
        // Créer l'AppProfile (CN dans OU=Parcs)
        $appProfileSalle = AppProfile::create([
            'name' => $laravel_salle_nom,
            'display_name' => $laravel_salle_nom,
            'description' => 'Test Delete Salle Laravel',
            'is_active' => true,
        ]);
        
        $resultCn = $appProfileAdSyncService->createAppProfile($appProfileSalle);
        
        if (!$resultCn['success']) {
            echo "❌ Erreur création CN: {$resultCn['error']}\n";
        } else {
            $appProfileSalle->update(['ad_guid' => $resultCn['guid']]);
            echo "✓ Salle Laravel créée (ID: {$workstationGroupSalle->id})\n\n";
            
            sleep(1);
            
            // 2. Vérifier existence avant suppression
            echo "2. Vérification avant suppression...\n";
            $dnHelper = app(\App\Config\LdapDnHelper::class);
            $computersDn = $dnHelper->computers();
            $parcsDn = $dnHelper->parcs();
            
            $groupAdSalle = \App\LdapModels\DeviceGroupTagModel::in($parcsDn)
                ->where('cn', '=', $laravel_salle_nom)
                ->first();
            $ouAdSalle = \App\LdapModels\DeviceGroupModel::in($computersDn)
                ->where('ou', '=', $laravel_salle_nom)
                ->first();
            
            if ($groupAdSalle && $ouAdSalle) {
                echo "✓ Groupe CN et OU existent\n\n";
                
                // 3. Supprimer la salle (OU + CN)
                echo "3. Suppression de la salle Laravel...\n";
                $deleteResultOu = $adSyncService->deleteWorkstationGroupByName($laravel_salle_nom);
                $deleteResultCn = $appProfileAdSyncService->deleteAppProfile($laravel_salle_nom);
                $workstationGroupSalle->delete();
                $appProfileSalle->delete();
                
                if ($deleteResultOu['success'] && $deleteResultCn['success']) {
                    echo "✓ Salle Laravel supprimée\n\n";
                    
                    sleep(1);
                    
                    // 4. Vérifier suppression
                    echo "4. Vérification après suppression...\n";
                    $groupAdSalleAfter = \App\LdapModels\DeviceGroupTagModel::in($parcsDn)
                        ->where('cn', '=', $laravel_salle_nom)
                        ->first();
                    $ouAdSalleAfter = \App\LdapModels\DeviceGroupModel::in($computersDn)
                        ->where('ou', '=', $laravel_salle_nom)
                        ->first();
                    
                    $cn_deleted = !$groupAdSalleAfter;
                    $ou_deleted = !$ouAdSalleAfter;
                    
                    if ($cn_deleted) {
                        echo "✓ Groupe CN supprimé\n";
                    } else {
                        echo "❌ Groupe CN existe encore\n";
                    }
                    
                    if ($ou_deleted) {
                        echo "✓ OU supprimé\n";
                    } else {
                        echo "❌ OU existe encore\n";
                    }
                    
                    $laravel_salle_success = $cn_deleted && $ou_deleted;
                } else {
                    echo "❌ Erreur suppression: OU={$deleteResultOu['error']}, CN={$deleteResultCn['error']}\n";
                }
            } else {
                echo "❌ Groupe CN ou OU non trouvé dans l'AD\n";
                echo "  - CN: " . ($groupAdSalle ? "Oui" : "Non") . "\n";
                echo "  - OU: " . ($ouAdSalle ? "Oui" : "Non") . "\n";
            }
        }
    }
} catch (Exception $e) {
    echo "❌ Exception Laravel: " . $e->getMessage() . "\n";
}

// Réactiver les observers
WorkstationGroupObserver::enableSync();
AppProfileObserver::enableSync();

echo "\n";

// ============================================================================
// RÉSUMÉ FINAL
// ============================================================================

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      RÉSUMÉ DE LA COMPARAISON                  ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ Suppression de PARC                                            │\n";
echo "├─────────────────────────────────────────────────────────────────┤\n";
echo "│ Legacy:  " . ($legacy_cn_deleted ? "✓ SUCCÈS" : "❌ ÉCHEC") . "                                              │\n";
echo "│ Laravel: " . ($laravel_parc_success ? "✓ SUCCÈS" : "❌ ÉCHEC") . "                                              │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ Suppression de SALLE                                           │\n";
echo "├─────────────────────────────────────────────────────────────────┤\n";
echo "│ Legacy:  " . ($legacy_salle_cn_deleted && $legacy_salle_ou_deleted ? "✓ SUCCÈS" : "❌ ÉCHEC") . "                                              │\n";
echo "│ Laravel: " . ($laravel_salle_success ? "✓ SUCCÈS" : "❌ ÉCHEC") . "                                              │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Verdict final
$all_success = $legacy_cn_deleted && 
               $legacy_salle_cn_deleted && 
               $legacy_salle_ou_deleted && 
               $laravel_parc_success && 
               $laravel_salle_success;

if ($all_success) {
    echo "🎉 TOUS LES TESTS SONT PASSÉS!\n";
    echo "Les suppressions Legacy et Laravel produisent les mêmes résultats.\n";
    exit(0);
} else {
    echo "⚠️  CERTAINS TESTS ONT ÉCHOUÉ\n";
    echo "Vérifiez les logs ci-dessus pour plus de détails.\n";
    exit(1);
}
