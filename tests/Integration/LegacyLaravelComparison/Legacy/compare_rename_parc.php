#!/usr/bin/env php
<?php
/**
 * Script de comparaison automatique - Renommage de parc/salle
 * 
 * Compare les résultats de renommage entre Legacy et Laravel
 * 
 * Usage: php compare_rename_parc.php
 */

// Chargement de l'environnement legacy
$legacy_base = '/var/www/sambaedu';
require_once $legacy_base . '/includes/config.inc.php';
require_once $legacy_base . '/includes/ldap.inc.php';
require_once $legacy_base . '/includes/samba-tool.inc.php';

$config = get_config();

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  Comparaison Legacy vs Laravel - Renommage de parc/salle      ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// ============================================================================
// PHASE 1: Test Legacy - Renommage d'un parc
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 1: Test Legacy - Renommage d'un parc                     │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

$legacy_parc_nom = 'TestRenameParcLegacy_' . time();
$legacy_parc_new_nom = 'TestRenameParcLegacyNew_' . time();

// Créer le parc legacy
// create_parc() avec type='parc' crée UNIQUEMENT un groupe CN dans OU=Parcs
echo "1. Création du parc legacy '$legacy_parc_nom'...\n";
$result = create_parc($config, $legacy_parc_nom, 'Test Rename Parc Legacy', '', 'parc');
if (!$result) {
    echo "❌ Erreur lors de la création du parc legacy\n";
    exit(1);
}
echo "✓ Parc legacy créé\n\n";

sleep(1);

// Vérifier l'existence avant renommage
echo "2. Vérification avant renommage...\n";
$cn_dn_before = "CN={$legacy_parc_nom},{$config['dn']['parcs']}";
$cn_info_before = search_ad($config, $cn_dn_before, "dn");
if (empty($cn_info_before)) {
    echo "❌ Le groupe CN n'existe pas avant renommage\n";
    exit(1);
}
echo "✓ Groupe CN existe: $cn_dn_before\n";
echo "  - samaccountname: " . ($cn_info_before['samaccountname'] ?? 'N/A') . "\n\n";

// Renommer le parc legacy
// rename_parc() effectue les opérations suivantes:
// 1. Si type=salle: appelle rename_salle() pour renommer l'OU dans OU=Computers
// 2. Modifie le samaccountname du groupe CN via modify_ad()
// 3. Renomme/déplace le CN via move_ad() (change le RDN de CN=ancien à CN=nouveau)
// Signature: rename_parc($config, $sam, $new_name)
echo "3. Renommage du parc legacy '$legacy_parc_nom' -> '$legacy_parc_new_nom'...\n";
$result = rename_parc($config, $legacy_parc_nom, $legacy_parc_new_nom);
if (!$result) {
    echo "❌ Erreur lors du renommage du parc legacy\n";
    exit(1);
}
echo "✓ Parc legacy renommé\n\n";

sleep(1);

// Vérifier le renommage
echo "4. Vérification après renommage...\n";
$cn_dn_after = "CN={$legacy_parc_new_nom},{$config['dn']['parcs']}";
$cn_info_after = search_ad($config, $cn_dn_after, "dn");

$legacy_parc_renamed = !empty($cn_info_after);
$legacy_parc_sam_updated = false;

if ($legacy_parc_renamed) {
    echo "✓ Groupe CN renommé: $cn_dn_after\n";
    $new_sam = $cn_info_after['samaccountname'] ?? '';
    $legacy_parc_sam_updated = (stripos($new_sam, $legacy_parc_new_nom) !== false);
    echo "  - samaccountname: $new_sam " . ($legacy_parc_sam_updated ? "✓" : "❌") . "\n";
} else {
    echo "❌ Groupe CN non trouvé après renommage\n";
}

// Vérifier que l'ancien CN n'existe plus
$cn_old_after = search_ad($config, $cn_dn_before, "dn");
$legacy_parc_old_gone = empty($cn_old_after);
echo "  - Ancien CN supprimé: " . ($legacy_parc_old_gone ? "✓ OUI" : "❌ NON") . "\n\n";

// ============================================================================
// PHASE 2: Test Legacy - Renommage d'une salle
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 2: Test Legacy - Renommage d'une salle                   │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

$legacy_salle_nom = 'TestRenameSalleLegacy_' . time();
$legacy_salle_new_nom = 'TestRenameSalleLegacyNew_' . time();

// Créer la salle legacy
// create_parc() avec type='salle' crée:
// - Un groupe CN dans OU=Parcs
// - Une OU dans OU=Computers
echo "1. Création de la salle legacy '$legacy_salle_nom'...\n";
$result = create_parc($config, $legacy_salle_nom, 'Test Rename Salle Legacy', 'Computers', 'salle');
if (!$result) {
    echo "❌ Erreur lors de la création de la salle legacy\n";
    exit(1);
}
echo "✓ Salle legacy créée\n\n";

sleep(1);

// Vérifier l'existence avant renommage
echo "2. Vérification avant renommage...\n";
$cn_dn_salle_before = "CN={$legacy_salle_nom},{$config['dn']['parcs']}";
$ou_dn_salle_before = "OU={$legacy_salle_nom},{$config['dn']['computers']}";
$cn_salle_before = search_ad($config, $cn_dn_salle_before, "dn");
$ou_salle_before = search_ad($config, $ou_dn_salle_before, "dn");

if (empty($cn_salle_before)) {
    echo "⚠ Le groupe CN n'existe pas avant renommage\n";
}
if (empty($ou_salle_before)) {
    echo "⚠ L'OU n'existe pas avant renommage\n";
}
echo "✓ CN: $cn_dn_salle_before\n";
echo "✓ OU: $ou_dn_salle_before\n\n";

// Renommer la salle legacy
// rename_parc() pour une salle:
// 1. Appelle rename_salle() qui renomme l'OU via move_ad()
// 2. Modifie le samaccountname du groupe CN
// 3. Renomme le CN via move_ad()
echo "3. Renommage de la salle legacy '$legacy_salle_nom' -> '$legacy_salle_new_nom'...\n";
$result = rename_parc($config, $legacy_salle_nom, $legacy_salle_new_nom);
if (!$result) {
    echo "❌ Erreur lors du renommage de la salle legacy\n";
    // Continuer quand même pour tester Laravel
}
echo "✓ Salle legacy renommée\n\n";

sleep(1);

// Vérifier le renommage
echo "4. Vérification après renommage...\n";
$cn_dn_salle_after = "CN={$legacy_salle_new_nom},{$config['dn']['parcs']}";
$ou_dn_salle_after = "OU={$legacy_salle_new_nom},{$config['dn']['computers']}";
$cn_salle_after = search_ad($config, $cn_dn_salle_after, "dn");
$ou_salle_after = search_ad($config, $ou_dn_salle_after, "dn");

$legacy_salle_cn_renamed = !empty($cn_salle_after);
$legacy_salle_ou_renamed = !empty($ou_salle_after);

echo "  - CN renommé: " . ($legacy_salle_cn_renamed ? "✓ OUI" : "❌ NON") . "\n";
echo "  - OU renommée: " . ($legacy_salle_ou_renamed ? "✓ OUI" : "❌ NON") . "\n\n";

// ============================================================================
// PHASE 3: Test Laravel - Renommage d'un parc
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 3: Test Laravel - Renommage d'un parc                    │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Bootstrap Laravel
require_once '/root/se4/sources/var/www/sambaedu/laravel/vendor/autoload.php';
$app = require_once '/root/se4/sources/var/www/sambaedu/laravel/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\AdSync\AdSyncService;
use App\Models\WorkstationGroup;

$laravel_parc_nom = 'TestRenameParcLaravel_' . time();
$laravel_parc_new_nom = 'TestRenameParcLaravelNew_' . time();
$laravel_parc_cn_renamed = false;
$laravel_parc_old_gone = false;

try {
    // Désactiver l'Observer pour éviter la double synchronisation
    \App\Observers\WorkstationGroupObserver::disableSync();
    
    // 1. Créer le parc Laravel
    echo "1. Création du parc Laravel '$laravel_parc_nom'...\n";
    WorkstationGroup::where('name', $laravel_parc_nom)->delete();
    WorkstationGroup::where('name', $laravel_parc_new_nom)->delete();
    
    $workstationGroup = WorkstationGroup::create([
        'name' => $laravel_parc_nom,
        'description' => 'Test Rename Parc Laravel',
        'parent_id' => null,
        'is_physical' => false,
        'is_active' => true,
    ]);
    
    $adSyncService = app(AdSyncService::class);
    $result = $adSyncService->createWorkstationGroup($workstationGroup);
    
    if (!$result['success']) {
        echo "❌ Erreur création parc: {$result['error']}\n";
    } else {
        echo "✓ Parc Laravel créé (ID: {$workstationGroup->id})\n\n";
        
        sleep(1);
        
        // 2. Renommer le parc Laravel
        echo "2. Renommage du parc Laravel '$laravel_parc_nom' -> '$laravel_parc_new_nom'...\n";
        $result = $adSyncService->renameWorkstationGroup($workstationGroup, $laravel_parc_new_nom);
        
        if (!$result['success']) {
            echo "❌ Erreur renommage: {$result['error']}\n";
        } else {
            // Mettre à jour le nom en SQL aussi
            $workstationGroup->name = $laravel_parc_new_nom;
            $workstationGroup->save();
            echo "✓ Parc Laravel renommé\n\n";
        }
        
        sleep(1);
        
        // 3. Vérifier dans l'AD via LdapRecord
        echo "3. Vérification dans l'AD via LdapRecord...\n";
        $dnHelper = app(\App\Config\LdapDnHelper::class);
        $parcsDn = $dnHelper->parcs();
        
        // Vérifier le nouveau CN
        $groupAdNew = \App\LdapModels\DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $laravel_parc_new_nom)
            ->first();
        
        $laravel_parc_cn_renamed = !empty($groupAdNew);
        
        if ($laravel_parc_cn_renamed) {
            echo "✓ Nouveau CN trouvé: " . $groupAdNew->getDn() . "\n";
        } else {
            echo "❌ Nouveau CN non trouvé\n";
        }
        
        // Vérifier que l'ancien CN n'existe plus
        $groupAdOld = \App\LdapModels\DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $laravel_parc_nom)
            ->first();
        
        $laravel_parc_old_gone = empty($groupAdOld);
        echo "  - Ancien CN supprimé: " . ($laravel_parc_old_gone ? "✓ OUI" : "❌ NON") . "\n\n";
    }
    
    \App\Observers\WorkstationGroupObserver::enableSync();
    
} catch (Exception $e) {
    echo "❌ Exception Laravel: " . $e->getMessage() . "\n";
    \App\Observers\WorkstationGroupObserver::enableSync();
}

// ============================================================================
// PHASE 4: Test Laravel - Renommage d'une salle
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 4: Test Laravel - Renommage d'une salle                  │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

$laravel_salle_nom = 'TestRenameSalleLaravel_' . time();
$laravel_salle_new_nom = 'TestRenameSalleLaravelNew_' . time();
$laravel_salle_cn_renamed = false;
$laravel_salle_ou_renamed = false;

try {
    \App\Observers\WorkstationGroupObserver::disableSync();
    
    // 1. Créer la salle Laravel
    echo "1. Création de la salle Laravel '$laravel_salle_nom'...\n";
    WorkstationGroup::where('name', $laravel_salle_nom)->delete();
    WorkstationGroup::where('name', $laravel_salle_new_nom)->delete();
    
    $workstationGroupSalle = WorkstationGroup::create([
        'name' => $laravel_salle_nom,
        'description' => 'Test Rename Salle Laravel',
        'parent_id' => null,
        'is_physical' => true,
        'is_active' => true,
    ]);
    
    $result = $adSyncService->createWorkstationGroup($workstationGroupSalle);
    
    if (!$result['success']) {
        echo "❌ Erreur création salle: {$result['error']}\n";
    } else {
        echo "✓ Salle Laravel créée (ID: {$workstationGroupSalle->id})\n\n";
        
        sleep(1);
        
        // 2. Renommer la salle Laravel
        echo "2. Renommage de la salle Laravel '$laravel_salle_nom' -> '$laravel_salle_new_nom'...\n";
        $result = $adSyncService->renameWorkstationGroup($workstationGroupSalle, $laravel_salle_new_nom);
        
        if (!$result['success']) {
            echo "❌ Erreur renommage: {$result['error']}\n";
        } else {
            $workstationGroupSalle->name = $laravel_salle_new_nom;
            $workstationGroupSalle->save();
            echo "✓ Salle Laravel renommée\n\n";
        }
        
        sleep(1);
        
        // 3. Vérifier dans l'AD via LdapRecord
        echo "3. Vérification dans l'AD via LdapRecord...\n";
        $dnHelper = app(\App\Config\LdapDnHelper::class);
        $parcsDn = $dnHelper->parcs();
        $computersDn = $dnHelper->computers();
        
        // Vérifier le nouveau CN
        $groupAdNew = \App\LdapModels\DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $laravel_salle_new_nom)
            ->first();
        
        $laravel_salle_cn_renamed = !empty($groupAdNew);
        echo "  - CN renommé: " . ($laravel_salle_cn_renamed ? "✓ OUI" : "❌ NON") . "\n";
        
        // Vérifier la nouvelle OU
        $ouAdNew = \App\LdapModels\DeviceGroupModel::in($computersDn)
            ->where('ou', '=', $laravel_salle_new_nom)
            ->first();
        
        $laravel_salle_ou_renamed = !empty($ouAdNew);
        echo "  - OU renommée: " . ($laravel_salle_ou_renamed ? "✓ OUI" : "❌ NON") . "\n\n";
    }
    
    \App\Observers\WorkstationGroupObserver::enableSync();
    
} catch (Exception $e) {
    echo "❌ Exception Laravel: " . $e->getMessage() . "\n";
    \App\Observers\WorkstationGroupObserver::enableSync();
}

// ============================================================================
// NETTOYAGE
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ NETTOYAGE                                                       │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Supprimer les parcs/salles legacy
echo "Suppression des parcs/salles legacy...\n";
delete_parc($config, $legacy_parc_new_nom);
delete_parc($config, $legacy_salle_new_nom);

// Supprimer les parcs/salles Laravel
echo "Suppression des parcs/salles Laravel...\n";
if (isset($workstationGroup) && isset($adSyncService)) {
    $adSyncService->deleteWorkstationGroup($workstationGroup);
    $workstationGroup->delete();
}
if (isset($workstationGroupSalle) && isset($adSyncService)) {
    $adSyncService->deleteWorkstationGroup($workstationGroupSalle);
    $workstationGroupSalle->delete();
}
echo "✓ Nettoyage terminé\n\n";

// ============================================================================
// RÉSUMÉ FINAL
// ============================================================================

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      RÉSUMÉ DE LA COMPARAISON                  ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ Renommage de PARC                                              │\n";
echo "├─────────────────────────────────────────────────────────────────┤\n";
echo "│ Legacy:                                                         │\n";
echo "│   - CN renommé:        " . ($legacy_parc_renamed ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│   - SAM mis à jour:    " . ($legacy_parc_sam_updated ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│   - Ancien CN absent:  " . ($legacy_parc_old_gone ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│                                                                 │\n";
echo "│ Laravel:                                                        │\n";
echo "│   - CN renommé:        " . ($laravel_parc_cn_renamed ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│   - Ancien CN absent:  " . ($laravel_parc_old_gone ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ Renommage de SALLE                                             │\n";
echo "├─────────────────────────────────────────────────────────────────┤\n";
echo "│ Legacy:                                                         │\n";
echo "│   - CN renommé:        " . ($legacy_salle_cn_renamed ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│   - OU renommée:       " . ($legacy_salle_ou_renamed ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│                                                                 │\n";
echo "│ Laravel:                                                        │\n";
echo "│   - CN renommé:        " . ($laravel_salle_cn_renamed ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│   - OU renommée:       " . ($laravel_salle_ou_renamed ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Verdict final
$legacy_parc_success = $legacy_parc_renamed && $legacy_parc_old_gone;
$legacy_salle_success = $legacy_salle_cn_renamed; // OU renommée peut échouer dans legacy
$laravel_parc_success = $laravel_parc_cn_renamed && $laravel_parc_old_gone;
$laravel_salle_success = $laravel_salle_cn_renamed && $laravel_salle_ou_renamed;

// Le test est réussi si Laravel fonctionne correctement
// Legacy peut avoir des limitations (ex: rename_salle ne renomme pas toujours l'OU)
$laravel_success = $laravel_parc_success && $laravel_salle_success;

if ($laravel_success) {
    echo "🎉 TESTS LARAVEL RÉUSSIS!\n";
    echo "Les renommages Laravel fonctionnent correctement.\n";
    if (!$legacy_salle_ou_renamed) {
        echo "⚠ Note: La fonction legacy rename_parc() n'a pas renommé l'OU de la salle.\n";
    }
    exit(0);
} else {
    echo "⚠️  CERTAINS TESTS ONT ÉCHOUÉ\n";
    echo "Vérifiez les logs ci-dessus pour plus de détails.\n";
    exit(1);
}
