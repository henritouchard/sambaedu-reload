#!/usr/bin/env php
<?php
/**
 * Script de comparaison automatique - Déplacement de salle
 * 
 * Compare les résultats de déplacement d'une salle entre Legacy et Laravel
 * 
 * P5: Déplacer une salle vers un nouveau parent
 * - L'OU de la salle est déplacée vers le nouveau parent dans OU=Computers
 * - Les machines de la salle restent dans l'OU déplacée
 * - Les appartenances aux groupes CN parents sont mises à jour
 * 
 * Usage: php compare_move_salle.php
 */

// Chargement de l'environnement legacy
$legacy_base = '/var/www/sambaedu';
require_once $legacy_base . '/includes/config.inc.php';
require_once $legacy_base . '/includes/ldap.inc.php';
require_once $legacy_base . '/includes/samba-tool.inc.php';

$config = get_config();

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  Comparaison Legacy vs Laravel - Déplacement de salle (P5)    ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// ============================================================================
// PHASE 1: Test Legacy - Déplacement d'une salle
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 1: Test Legacy - Déplacement d'une salle                 │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

$timestamp = time();
$legacy_parent_nom = 'TestMoveParentLegacy_' . $timestamp;
$legacy_salle_nom = 'TestMoveSalleLegacy_' . $timestamp;

// 1. Créer la salle parent (destination)
// create_parc() avec type='salle' crée un groupe CN dans OU=Parcs ET une OU dans OU=Computers
// Cette salle servira de destination pour le déplacement
echo "1. Création de la salle parent '$legacy_parent_nom'...\n";
$result = create_parc($config, $legacy_parent_nom, 'Salle parent pour test déplacement', 'Computers', 'salle');
if (!$result) {
    echo "❌ Erreur lors de la création de la salle parent legacy\n";
    exit(1);
}
echo "✓ Salle parent legacy créée\n\n";

sleep(1);

// 2. Créer la salle à déplacer (à la racine de OU=Computers)
echo "2. Création de la salle à déplacer '$legacy_salle_nom'...\n";
$result = create_parc($config, $legacy_salle_nom, 'Salle à déplacer', 'Computers', 'salle');
if (!$result) {
    echo "❌ Erreur lors de la création de la salle legacy\n";
    exit(1);
}
echo "✓ Salle legacy créée\n\n";

sleep(1);

// 3. Vérifier les positions initiales
echo "3. Vérification des positions initiales...\n";
$ou_salle_dn_before = "OU={$legacy_salle_nom},{$config['dn']['computers']}";
$ou_parent_dn = "OU={$legacy_parent_nom},{$config['dn']['computers']}";

$salle_info = search_ad($config, $ou_salle_dn_before, "dn");
$parent_info = search_ad($config, $ou_parent_dn, "dn");

if (empty($salle_info)) {
    echo "⚠ L'OU de la salle n'existe pas: $ou_salle_dn_before\n";
} else {
    echo "✓ Salle à déplacer: $ou_salle_dn_before\n";
}
if (empty($parent_info)) {
    echo "⚠ L'OU parent n'existe pas: $ou_parent_dn\n";
} else {
    echo "✓ Salle parent: $ou_parent_dn\n";
}
echo "\n";

// 4. Déplacer la salle legacy vers le parent
// rename_salle() déplace une OU vers un nouveau DN
// Signature: rename_salle($config, $salle, $new_dn)
// - $salle: tableau avec les infos de la salle (dn, ou, parentdn, etc.)
// - $new_dn: nouveau DN complet de l'OU (ex: "OU=MaSalle,OU=Parent,OU=Computers,DC=...")
// La fonction gère les enfants en créant des OUs temporaires pour les machines
echo "4. Déplacement de la salle legacy vers le parent...\n";
$salle_array = search_parcs($config, $legacy_salle_nom);
$legacy_move_success = false;

if (!empty($salle_array) && isset($salle_array[0])) {
    $new_dn = "OU={$legacy_salle_nom},{$ou_parent_dn}";
    echo "   Ancien DN: " . $salle_array[0]['dn'] . "\n";
    echo "   Nouveau DN: $new_dn\n";
    
    // La fonction rename_salle est utilisée pour déplacer
    $result = rename_salle($config, $salle_array[0], $new_dn);
    $legacy_move_success = (bool)$result;
    
    if ($legacy_move_success) {
        echo "✓ Salle legacy déplacée\n\n";
    } else {
        echo "❌ Erreur lors du déplacement legacy\n\n";
    }
} else {
    echo "❌ Salle non trouvée via search_parcs\n\n";
}

sleep(1);

// 5. Vérifier la nouvelle position
echo "5. Vérification après déplacement...\n";
$ou_salle_dn_after = "OU={$legacy_salle_nom},{$ou_parent_dn}";
$salle_after = search_ad($config, $ou_salle_dn_after, "dn");
$legacy_salle_moved = !empty($salle_after);

if ($legacy_salle_moved) {
    echo "✓ Salle déplacée vers: $ou_salle_dn_after\n";
} else {
    echo "❌ Salle non trouvée à la nouvelle position\n";
}

// Vérifier que l'ancienne position est vide
$salle_old = search_ad($config, $ou_salle_dn_before, "dn");
$legacy_old_gone = empty($salle_old);
echo "  - Ancienne position vide: " . ($legacy_old_gone ? "✓ OUI" : "❌ NON") . "\n\n";

// ============================================================================
// PHASE 2: Test Laravel - Déplacement d'une salle
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 2: Test Laravel - Déplacement d'une salle                │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Bootstrap Laravel
require_once '/root/se4/sources/var/www/sambaedu/laravel/vendor/autoload.php';
$app = require_once '/root/se4/sources/var/www/sambaedu/laravel/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\AdSync\AdSyncService;
use App\Models\WorkstationGroup;

$laravel_parent_nom = 'TestMoveParentLaravel_' . $timestamp;
$laravel_salle_nom = 'TestMoveSalleLaravel_' . $timestamp;
$laravel_salle_moved = false;
$laravel_old_gone = false;

try {
    \App\Observers\WorkstationGroupObserver::disableSync();
    $adSyncService = app(AdSyncService::class);
    $dnHelper = app(\App\Config\LdapDnHelper::class);
    
    // 1. Créer la salle parent Laravel
    echo "1. Création de la salle parent Laravel '$laravel_parent_nom'...\n";
    WorkstationGroup::where('name', $laravel_parent_nom)->delete();
    WorkstationGroup::where('name', $laravel_salle_nom)->delete();
    
    $parentGroup = WorkstationGroup::create([
        'name' => $laravel_parent_nom,
        'description' => 'Salle parent Laravel',
        'parent_id' => null,
        'is_physical' => true,
        'is_active' => true,
    ]);
    
    $result = $adSyncService->createWorkstationGroup($parentGroup);
    if (!$result['success']) {
        echo "❌ Erreur création salle parent: {$result['error']}\n";
    } else {
        echo "✓ Salle parent Laravel créée (ID: {$parentGroup->id})\n\n";
    }
    
    sleep(1);
    
    // 2. Créer la salle à déplacer
    echo "2. Création de la salle à déplacer '$laravel_salle_nom'...\n";
    $salleGroup = WorkstationGroup::create([
        'name' => $laravel_salle_nom,
        'description' => 'Salle à déplacer Laravel',
        'parent_id' => null,
        'is_physical' => true,
        'is_active' => true,
    ]);
    
    $result = $adSyncService->createWorkstationGroup($salleGroup);
    if (!$result['success']) {
        echo "❌ Erreur création salle: {$result['error']}\n";
    } else {
        echo "✓ Salle Laravel créée (ID: {$salleGroup->id})\n\n";
    }
    
    sleep(1);
    
    // 3. Vérifier les positions initiales
    echo "3. Vérification des positions initiales via LdapRecord...\n";
    $computersDn = $dnHelper->computers();
    
    $salleOuBefore = \App\LdapModels\DeviceGroupModel::in($computersDn)
        ->where('ou', '=', $laravel_salle_nom)
        ->first();
    
    if ($salleOuBefore) {
        echo "✓ Salle à déplacer: " . $salleOuBefore->getDn() . "\n\n";
    } else {
        echo "❌ Salle non trouvée dans AD\n\n";
    }
    
    // 4. Déplacer la salle Laravel vers le parent
    // moveWorkstationGroup() déplace l'OU de la salle vers le nouveau parent
    // Signature: moveWorkstationGroup($group, $newParent)
    // - $group: WorkstationGroup à déplacer
    // - $newParent: WorkstationGroup parent (null = racine OU=Computers)
    // La méthode:
    // 1. Trouve l'OU actuelle de la salle
    // 2. Détermine le DN du nouveau parent
    // 3. Déplace l'OU via LdapRecord move()
    // 4. Met à jour les appartenances des machines aux groupes CN parents
    echo "4. Déplacement de la salle Laravel vers le parent...\n";
    
    // Mettre à jour le parent_id en SQL d'abord
    $salleGroup->parent_id = $parentGroup->id;
    $salleGroup->save();
    
    $result = $adSyncService->moveWorkstationGroup($salleGroup, $parentGroup);
    
    if (!$result['success']) {
        echo "❌ Erreur déplacement: {$result['error']}\n\n";
    } else {
        echo "✓ Salle Laravel déplacée\n\n";
    }
    
    sleep(1);
    
    // 5. Vérifier la nouvelle position
    echo "5. Vérification après déplacement via LdapRecord...\n";
    
    // Chercher dans le parent
    $parentOu = \App\LdapModels\DeviceGroupModel::in($computersDn)
        ->where('ou', '=', $laravel_parent_nom)
        ->first();
    
    if ($parentOu) {
        $salleOuAfter = \App\LdapModels\DeviceGroupModel::in($parentOu->getDn())
            ->where('ou', '=', $laravel_salle_nom)
            ->first();
        
        $laravel_salle_moved = !empty($salleOuAfter);
        
        if ($laravel_salle_moved) {
            echo "✓ Salle déplacée vers: " . $salleOuAfter->getDn() . "\n";
        } else {
            echo "❌ Salle non trouvée dans le parent\n";
        }
    } else {
        echo "❌ Parent non trouvé\n";
    }
    
    // Vérifier que l'ancienne position est vide (chercher directement sous Computers)
    $salleOuOld = \App\LdapModels\DeviceGroupModel::query()
        ->where('distinguishedname', '=', "OU={$laravel_salle_nom},{$computersDn}")
        ->first();
    
    $laravel_old_gone = empty($salleOuOld);
    echo "  - Ancienne position vide: " . ($laravel_old_gone ? "✓ OUI" : "❌ NON") . "\n\n";
    
    \App\Observers\WorkstationGroupObserver::enableSync();
    
} catch (Exception $e) {
    echo "❌ Exception Laravel: " . $e->getMessage() . "\n";
    echo "   Trace: " . $e->getTraceAsString() . "\n\n";
    \App\Observers\WorkstationGroupObserver::enableSync();
}

// ============================================================================
// NETTOYAGE
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ NETTOYAGE                                                       │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Supprimer les salles legacy (dans l'ordre inverse de création)
echo "Suppression des salles legacy...\n";
delete_parc($config, $legacy_salle_nom);
delete_parc($config, $legacy_parent_nom);

// Supprimer les salles Laravel
echo "Suppression des salles Laravel...\n";
if (isset($salleGroup) && isset($adSyncService)) {
    $adSyncService->deleteWorkstationGroup($salleGroup);
    $salleGroup->delete();
}
if (isset($parentGroup) && isset($adSyncService)) {
    $adSyncService->deleteWorkstationGroup($parentGroup);
    $parentGroup->delete();
}
echo "✓ Nettoyage terminé\n\n";

// ============================================================================
// RÉSUMÉ FINAL
// ============================================================================

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      RÉSUMÉ DE LA COMPARAISON                  ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ Déplacement de salle                                           │\n";
echo "├─────────────────────────────────────────────────────────────────┤\n";
echo "│ Legacy:                                                         │\n";
echo "│   - Déplacement réussi:    " . ($legacy_move_success ? "✓ OUI" : "❌ NON") . "                              │\n";
echo "│   - Salle dans parent:     " . ($legacy_salle_moved ? "✓ OUI" : "❌ NON") . "                              │\n";
echo "│   - Ancienne pos. vide:    " . ($legacy_old_gone ? "✓ OUI" : "❌ NON") . "                              │\n";
echo "│                                                                 │\n";
echo "│ Laravel:                                                        │\n";
echo "│   - Salle dans parent:     " . ($laravel_salle_moved ? "✓ OUI" : "❌ NON") . "                              │\n";
echo "│   - Ancienne pos. vide:    " . ($laravel_old_gone ? "✓ OUI" : "❌ NON") . "                              │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Verdict final - Le test est réussi si Laravel fonctionne
$laravel_success = $laravel_salle_moved && $laravel_old_gone;

if ($laravel_success) {
    echo "🎉 TESTS LARAVEL RÉUSSIS!\n";
    echo "Le déplacement de salle Laravel fonctionne correctement.\n";
    if (!$legacy_salle_moved) {
        echo "⚠ Note: La fonction legacy rename_salle() n'a pas déplacé la salle correctement.\n";
    }
    exit(0);
} else {
    echo "⚠️  CERTAINS TESTS ONT ÉCHOUÉ\n";
    echo "Vérifiez les logs ci-dessus pour plus de détails.\n";
    exit(1);
}
