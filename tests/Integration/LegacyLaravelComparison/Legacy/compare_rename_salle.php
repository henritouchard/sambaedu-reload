#!/usr/bin/env php
<?php
/**
 * Script de comparaison automatique - Renommage de salle (WorkstationGroup)
 * 
 * Compare les résultats de renommage entre Legacy et Laravel
 * 
 * Note: Ce test ne concerne que les salles (flag_parc=1) qui ont une OU dans OU=Computers.
 * Les profils applicatifs (flag_parc=0) n'ont pas d'OU et sont gérés différemment.
 * 
 * Usage: php compare_rename_salle.php
 */

// Chargement de l'environnement legacy
$legacy_base = '/var/www/sambaedu';
require_once $legacy_base . '/includes/config.inc.php';
require_once $legacy_base . '/includes/ldap.inc.php';
require_once $legacy_base . '/includes/samba-tool.inc.php';

$config = get_config();

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  Comparaison Legacy vs Laravel - Renommage de salle (P3)      ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// ============================================================================
// PHASE 1: Test Legacy - Renommage d'une salle
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 1: Test Legacy - Renommage d'une salle                   │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

$legacy_salle_nom = 'TestRenameSalleLegacy_' . time();
$legacy_salle_new_nom = 'TestRenameSalleLegacyNew_' . time();

// Créer la salle legacy
// create_parc() avec type='salle' crée:
// - Un groupe CN dans OU=Parcs (pour WPKG et délégations)
// - Une OU dans OU=Computers (pour GPO et organisation des machines)
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
// PHASE 2: Test Laravel - Renommage d'une salle
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 2: Test Laravel - Renommage d'une salle                  │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Bootstrap Laravel
require_once '/root/se4/sources/var/www/sambaedu/laravel/vendor/autoload.php';
$app = require_once '/root/se4/sources/var/www/sambaedu/laravel/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\AdSync\AdSyncService;
use App\Models\WorkstationGroup;

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
    
    $adSyncService = app(AdSyncService::class);
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

// Supprimer les salles legacy
echo "Suppression des salles legacy...\n";
delete_parc($config, $legacy_salle_new_nom);

// Supprimer les salles Laravel
echo "Suppression des salles Laravel...\n";
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
echo "│ Renommage de SALLE (WorkstationGroup)                          │\n";
echo "├─────────────────────────────────────────────────────────────────┤\n";
echo "│ Legacy:                                                         │\n";
echo "│   - CN renommé:        " . ($legacy_salle_cn_renamed ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│   - OU renommée:       " . ($legacy_salle_ou_renamed ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│                                                                 │\n";
echo "│ Laravel:                                                        │\n";
echo "│   - CN renommé:        " . ($laravel_salle_cn_renamed ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│   - OU renommée:       " . ($laravel_salle_ou_renamed ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Verdict final - Le test est réussi si Laravel fonctionne
$laravel_success = $laravel_salle_cn_renamed && $laravel_salle_ou_renamed;

if ($laravel_success) {
    echo "🎉 TESTS LARAVEL RÉUSSIS!\n";
    echo "Le renommage de salle Laravel fonctionne correctement.\n";
    if (!$legacy_salle_ou_renamed) {
        echo "⚠ Note: La fonction legacy rename_parc() n'a pas renommé l'OU de la salle.\n";
    }
    exit(0);
} else {
    echo "⚠️  CERTAINS TESTS ONT ÉCHOUÉ\n";
    echo "Vérifiez les logs ci-dessus pour plus de détails.\n";
    exit(1);
}
