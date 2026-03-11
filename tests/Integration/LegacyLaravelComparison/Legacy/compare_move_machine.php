<?php
/**
 * Test de comparaison P8 : Déplacer une machine vers une salle
 * 
 * Ce script compare le comportement de la fonction legacy move_member_parc()
 * avec la méthode Laravel AdSyncService::moveMachineToSalle()
 * 
 * Objectif de move_member_parc() :
 * - Déplacer physiquement la machine (CN) vers l'OU de la salle cible
 * - Retirer la machine des groupes CN des anciennes salles parentes
 * - Ajouter la machine aux groupes CN des nouvelles salles parentes
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Charger les includes legacy (utiliser le symlink pour éviter les conflits)
$legacyRoot = '/var/www/sambaedu';
require_once $legacyRoot . '/includes/config.inc.php';
require_once $legacyRoot . '/includes/ldap.inc.php';
require_once $legacyRoot . '/includes/functions.inc.php';

$config = get_config();

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ P8 - Test de comparaison : Déplacer une machine vers une salle │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// ============================================================================
// PHASE 1 : PRÉPARATION - Créer les salles et trouver une machine
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 1: Préparation de l'environnement de test                │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Utiliser des salles existantes dans l'AD pour éviter les problèmes de timing
// On cherche deux salles existantes
echo "1. Recherche de salles existantes dans l'AD...\n";
$all_salles = search_parcs($config, '*', 'salle');

if (count($all_salles) < 2) {
    echo "❌ Pas assez de salles dans l'AD pour le test (minimum 2 requises)\n";
    echo "   Salles trouvées: " . count($all_salles) . "\n";
    exit(1);
}

// Prendre deux salles existantes (éviter les salles de test)
$salle_source_nom = null;
$salle_dest_nom = null;
foreach ($all_salles as $salle) {
    if (strpos($salle['name'], 'Test') === false && strpos($salle['name'], 'test') === false) {
        if (!$salle_source_nom) {
            $salle_source_nom = $salle['name'];
        } elseif (!$salle_dest_nom && $salle['name'] !== $salle_source_nom) {
            $salle_dest_nom = $salle['name'];
            break;
        }
    }
}

if (!$salle_source_nom || !$salle_dest_nom) {
    // Fallback sur les premières salles disponibles
    $salle_source_nom = $all_salles[0]['name'];
    $salle_dest_nom = $all_salles[1]['name'];
}

echo "✓ Salles sélectionnées:\n";
echo "  - Source: $salle_source_nom\n";
echo "  - Destination: $salle_dest_nom\n\n";

// 3. Trouver ou créer une machine pour le test
echo "3. Recherche d'une machine existante dans l'AD...\n";
$machines = list_members_parc($config, 'Computers', true);
$machine_nom = null;
$machine_original_dn = null;
$machine_created = false;

// Chercher une machine qui n'est pas dans une salle de test
foreach ($machines as $m) {
    if (strpos($m['cn'], 'TestMove') === false && !empty($m['cn'])) {
        $machine_nom = $m['cn'];
        $machine_original_dn = $m['dn'];
        break;
    }
}

if (!$machine_nom) {
    echo "⚠ Aucune machine trouvée, création d'une machine temporaire...\n";
    
    // Bootstrap Laravel pour créer la machine via LdapRecord
    require_once '/root/se4/sources/var/www/sambaedu/laravel/vendor/autoload.php';
    $app = require_once '/root/se4/sources/var/www/sambaedu/laravel/bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    
    $machine_nom = 'TESTMOVEMACHINE$';
    $dnHelper = app(\App\Config\LdapDnHelper::class);
    
    // Créer la machine dans OU=Computers
    $computer = new \LdapRecord\Models\ActiveDirectory\Computer();
    $computer->cn = $machine_nom;
    $computer->setDn("CN={$machine_nom}," . $dnHelper->computers());
    $computer->samaccountname = $machine_nom;
    $computer->userAccountControl = 4096; // WORKSTATION_TRUST_ACCOUNT
    
    try {
        $computer->save();
        $machine_created = true;
        $machine_original_dn = $computer->getDn();
        echo "✓ Machine temporaire créée: $machine_nom\n";
    } catch (\Exception $e) {
        echo "❌ Erreur création machine: " . $e->getMessage() . "\n";
        // Cleanup
        delete_parc($config, $salle_source_nom);
        delete_parc($config, $salle_dest_nom);
        exit(1);
    }
} else {
    echo "✓ Machine trouvée: $machine_nom\n";
}

echo "  DN original: $machine_original_dn\n\n";

// 4. Déplacer la machine vers la salle source pour le test
// move_member_parc() déplace la machine vers l'OU de la salle et l'ajoute au groupe CN
echo "4. Déplacement de la machine vers la salle source...\n";
$result = move_member_parc($config, $salle_source_nom, $machine_nom);
if (!$result) {
    echo "❌ Erreur lors du déplacement initial vers la salle source\n";
    // Cleanup
    delete_parc($config, $salle_source_nom);
    delete_parc($config, $salle_dest_nom);
    exit(1);
}
echo "✓ Machine déplacée vers la salle source\n\n";

sleep(1);

// ============================================================================
// PHASE 2 : TEST LARAVEL - Déplacer la machine vers la salle destination
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 2: Test Laravel - Déplacement machine                    │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Bootstrap Laravel
require_once '/root/se4/sources/var/www/sambaedu/laravel/vendor/autoload.php';
$app = require_once '/root/se4/sources/var/www/sambaedu/laravel/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\AdSync\AdSyncService;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use LdapRecord\Models\ActiveDirectory\OrganizationalUnit;
use LdapRecord\Models\ActiveDirectory\Group;

// Vérifier l'état avant Laravel
$machine_before_laravel = search_machine($config, $machine_nom);
$salle_source_members_before_laravel = list_members_parc($config, $salle_source_nom, false, true);
$salle_dest_members_before_laravel = list_members_parc($config, $salle_dest_nom, false, true);

echo "État avant déplacement Laravel:\n";
echo "  - Machine DN: " . $machine_before_laravel['dn'] . "\n";
echo "  - Membres salle source: " . count($salle_source_members_before_laravel) . "\n";
echo "  - Membres salle dest: " . count($salle_dest_members_before_laravel) . "\n\n";

// Récupérer ou créer les modèles Laravel
$workstation = Workstation::where('name', $machine_nom)->first();
if (!$workstation) {
    echo "⚠ Machine non trouvée en SQL, utilisation d'un modèle temporaire...\n";
    // Créer un modèle sans le sauvegarder - AdSyncService n'a besoin que du nom
    $workstation = new Workstation();
    $workstation->name = $machine_nom;
}

$targetSalle = WorkstationGroup::where('name', $salle_dest_nom)->first();
if (!$targetSalle) {
    echo "⚠ Salle destination non trouvée en SQL, création temporaire...\n";
    $targetSalle = WorkstationGroup::create([
        'name' => $salle_dest_nom,
        'description' => 'Salle destination pour test',
        'is_physical' => true,
        'is_active' => true,
    ]);
}

// Déplacer avec Laravel
echo "1. Déplacement Laravel de '$machine_nom' vers '$salle_dest_nom'...\n";
$adSyncService = app(AdSyncService::class);
$laravel_result = $adSyncService->moveMachineToSalle($workstation, $targetSalle);

if ($laravel_result['success']) {
    echo "✓ Déplacement Laravel réussi\n\n";
} else {
    echo "❌ Déplacement Laravel échoué: " . ($laravel_result['error'] ?? 'Erreur inconnue') . "\n\n";
}

sleep(1);

// Vérifier l'état après Laravel via LdapRecord
$machine_after_laravel = search_machine($config, $machine_nom);
$salle_source_members_after_laravel = list_members_parc($config, $salle_source_nom, false, true);
$salle_dest_members_after_laravel = list_members_parc($config, $salle_dest_nom, false, true);

echo "État après déplacement Laravel:\n";
echo "  - Machine DN: " . $machine_after_laravel['dn'] . "\n";
echo "  - Membres salle source: " . count($salle_source_members_after_laravel) . "\n";
echo "  - Membres salle dest: " . count($salle_dest_members_after_laravel) . "\n\n";

// Vérifications Laravel
$laravel_machine_moved = (strpos($machine_after_laravel['dn'], $salle_dest_nom) !== false);
$laravel_removed_from_source = !in_array($machine_nom, array_column($salle_source_members_after_laravel, 'cn'));
$laravel_added_to_dest = in_array($machine_nom, array_column($salle_dest_members_after_laravel, 'cn'));

echo "Vérifications Laravel:\n";
echo "  - Machine déplacée vers OU dest: " . ($laravel_machine_moved ? "✓" : "❌") . "\n";
echo "  - Retirée du groupe source: " . ($laravel_removed_from_source ? "✓" : "❌") . "\n";
echo "  - Ajoutée au groupe dest: " . ($laravel_added_to_dest ? "✓" : "❌") . "\n\n";

$laravel_success = $laravel_result['success'] && $laravel_machine_moved && $laravel_removed_from_source && $laravel_added_to_dest;

// ============================================================================
// PHASE 3 : NETTOYAGE
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 3: Nettoyage                                             │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Remettre la machine à la racine de Computers
echo "Remise de la machine à la racine de Computers...\n";
try {
    move_member_parc($config, 'Computers', $machine_nom);
    echo "✓ Machine remise à la racine\n\n";
} catch (\Exception $e) {
    echo "⚠ Erreur remise machine: " . $e->getMessage() . "\n\n";
}

// Supprimer la machine temporaire si créée
if ($machine_created) {
    echo "Suppression de la machine temporaire...\n";
    try {
        $computer = \LdapRecord\Models\ActiveDirectory\Computer::findBy('cn', $machine_nom);
        if ($computer) {
            $computer->delete();
            echo "✓ Machine temporaire supprimée\n\n";
        }
    } catch (\Exception $e) {
        echo "⚠ Erreur suppression machine: " . $e->getMessage() . "\n\n";
    }
}

// Ne pas supprimer les salles existantes - on les a juste utilisées pour le test
echo "✓ Salles existantes conservées (non supprimées)\n\n";

// ============================================================================
// VERDICT FINAL
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ VERDICT FINAL                                                  │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

echo "│ Laravel: " . ($laravel_success ? "✓ SUCCÈS" : "❌ ÉCHEC") . str_repeat(" ", 53 - ($laravel_success ? 8 : 7)) . "│\n";

if ($laravel_success) {
    echo "\n🎉 TEST LARAVEL RÉUSSI!\n";
    echo "Le déplacement de machine vers une salle fonctionne correctement.\n";
    exit(0);
} else {
    echo "\n❌ TEST LARAVEL ÉCHOUÉ\n";
    if (!$laravel_result['success']) {
        echo "  - Erreur: " . ($laravel_result['error'] ?? 'Inconnue') . "\n";
    }
    if (!$laravel_machine_moved) {
        echo "  - Machine non déplacée vers l'OU destination\n";
    }
    if (!$laravel_removed_from_source) {
        echo "  - Machine non retirée du groupe source\n";
    }
    if (!$laravel_added_to_dest) {
        echo "  - Machine non ajoutée au groupe destination\n";
    }
    exit(1);
}
