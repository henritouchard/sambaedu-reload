#!/usr/bin/env php
<?php
/**
 * Script de comparaison automatique - Ajout d'une machine à un parc
 * 
 * Compare les résultats d'ajout entre Legacy et Laravel
 * 
 * Usage: php compare_add_machine_to_parc.php
 */

// Chargement de l'environnement legacy
$legacy_base = '/var/www/sambaedu';
require_once $legacy_base . '/includes/config.inc.php';
require_once $legacy_base . '/includes/ldap.inc.php';
require_once $legacy_base . '/includes/samba-tool.inc.php';

$config = get_config();

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  Comparaison Legacy vs Laravel - Ajout machine à un parc       ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Trouver une machine existante dans l'AD pour les tests
echo "Recherche d'une machine de test dans l'AD...\n";
$all_machines = search_ad($config, "*", "machine_fast", "all");
if (empty($all_machines)) {
    echo "❌ Aucune machine disponible dans l'AD pour le test\n";
    exit(1);
}
$test_machine = $all_machines[0];
$machine_nom = $test_machine['cn'];
echo "✓ Machine de test trouvée: $machine_nom\n\n";

// ============================================================================
// PHASE 1: Test Legacy - Ajout d'une machine à un parc
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 1: Test Legacy - Ajout d'une machine à un parc           │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

$legacy_parc_nom = 'TestAddMachineLegacy_' . time();

// Créer le parc legacy
// create_parc() crée un groupe CN dans OU=Parcs pour gérer les membres (machines)
// Avec type='parc', seul le CN est créé (pas d'OU)
// Le CN servira de conteneur pour l'attribut 'member' qui liste les machines
echo "1. Création du parc legacy '$legacy_parc_nom'...\n";
$result = create_parc($config, $legacy_parc_nom, 'Test Add Machine Legacy', '', 'parc');
if (!$result) {
    echo "❌ Erreur lors de la création du parc legacy\n";
    exit(1);
}
echo "✓ Parc legacy créé\n\n";

sleep(1);

// Vérifier l'état avant ajout
echo "2. Vérification avant ajout...\n";
$members_before_legacy = list_members_parc($config, $legacy_parc_nom, false, true);
echo "✓ Membres actuels: " . count($members_before_legacy) . "\n\n";

// Ajouter la machine au parc legacy
// add_member_parc() ajoute le DN de la machine à l'attribut 'member' du groupe CN
// Signature: add_member_parc($config, $parc, $member)
// - Utilise groupaddmemberbydn() en interne pour modifier l'attribut LDAP 'member'
// - Idempotent: retourne true si la machine est déjà membre
echo "3. Ajout de la machine au parc legacy...\n";
$result = add_member_parc($config, $legacy_parc_nom, $machine_nom);
if (!$result) {
    echo "❌ Erreur lors de l'ajout de la machine au parc legacy\n";
    exit(1);
}
echo "✓ Machine ajoutée au parc legacy\n\n";

sleep(1);

// Vérifier l'ajout
echo "4. Vérification après ajout...\n";
$members_after_legacy = list_members_parc($config, $legacy_parc_nom, false, true);
$legacy_machine_added = false;
foreach ($members_after_legacy as $member) {
    $member_cn = is_array($member) ? $member['cn'] : $member;
    if (strcasecmp($member_cn, $machine_nom) == 0) {
        $legacy_machine_added = true;
        break;
    }
}

if ($legacy_machine_added) {
    echo "✓ Machine membre du parc\n";
    echo "  - Nombre de membres: " . count($members_after_legacy) . "\n";
} else {
    echo "❌ Machine non membre du parc\n";
}

// Vérifier l'attribut member du groupe
$parc_info_legacy = search_parcs($config, $legacy_parc_nom);
$group_info_legacy = search_ad($config, $parc_info_legacy[0]['gdn'], "dn");
$legacy_member_in_ad = false;

if (!empty($group_info_legacy) && isset($group_info_legacy[0]['member'])) {
    $members_dns = $group_info_legacy[0]['member'];
    if (!is_array($members_dns)) {
        $members_dns = [$members_dns];
    }
    
    foreach ($members_dns as $member_dn) {
        if (strcasecmp($member_dn, $test_machine['dn']) == 0) {
            $legacy_member_in_ad = true;
            break;
        }
    }
}

if ($legacy_member_in_ad) {
    echo "✓ DN de la machine dans l'attribut 'member' du groupe\n";
} else {
    echo "❌ DN de la machine absent de l'attribut 'member'\n";
}

echo "\n";

// ============================================================================
// PHASE 2: Test Laravel - Ajout d'une machine à un parc
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 2: Test Laravel - Ajout d'une machine à un parc          │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Bootstrap Laravel
require_once '/root/se4/sources/var/www/sambaedu/laravel/vendor/autoload.php';
$app = require_once '/root/se4/sources/var/www/sambaedu/laravel/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\AdSync\AdSyncService;
use App\Models\WorkstationGroup;
use App\Models\Workstation;

$laravel_parc_nom = 'TestAddMachineLaravel_' . time();
$laravel_machine_added = false;
$laravel_member_in_ad = false;

try {
    // 1. Créer le parc Laravel
    echo "1. Création du parc Laravel '$laravel_parc_nom'...\n";
    
    // Nettoyer si existe déjà
    WorkstationGroup::where('name', $laravel_parc_nom)->delete();
    
    $workstationGroup = WorkstationGroup::create([
        'name' => $laravel_parc_nom,
        'description' => 'Test Add Machine Laravel',
        'parent_id' => null,
        'is_physical' => true,
        'is_active' => true,
    ]);
    
    $adSyncService = app(AdSyncService::class);
    $result = $adSyncService->createWorkstationGroup($workstationGroup);
    
    if (!$result['success']) {
        echo "❌ Erreur création parc: {$result['error']}\n";
    } else {
        echo "✓ Parc Laravel créé (ID: {$workstationGroup->id})\n\n";
        
        sleep(1);
        
        // 2. Récupérer ou créer le workstation en SQL
        echo "2. Récupération/création du workstation en SQL...\n";
        $workstation = Workstation::firstOrCreate(
            ['name' => $machine_nom],
            ['os' => 'Windows', 'is_active' => true]
        );
        echo "✓ Workstation (ID: {$workstation->id})\n\n";
        
        // 3. Ajouter la machine au parc via AdSyncService
        echo "3. Ajout de la machine au parc Laravel...\n";
        echo "   - Nom machine SQL: {$workstation->name}\n";
        echo "   - Nom parc SQL: {$workstationGroup->name}\n";
        $addResult = $adSyncService->addMemberToGroup($workstation, $workstationGroup);
        
        if ($addResult['success']) {
            echo "✓ Machine ajoutée au parc Laravel (selon AdSyncService)\n\n";
            $laravel_machine_added = true;
            
            sleep(1);
            
            // 4. Vérifier dans l'AD via LdapRecord
            echo "4. Vérification dans l'AD...\n";
            
            // Recharger le groupe depuis l'AD via LdapRecord
            $dnHelper = app(\App\Config\LdapDnHelper::class);
            $parcsDn = $dnHelper->parcs();
            $groupAd = \App\LdapModels\DeviceGroupTagModel::in($parcsDn)
                ->where('cn', '=', $laravel_parc_nom)
                ->first();
            
            if ($groupAd) {
                $members = $groupAd->member ?? [];
                if (!is_array($members)) {
                    $members = [$members];
                }
                
                echo "   - Groupe trouvé: " . $groupAd->getDn() . "\n";
                echo "   - Membres: " . count($members) . "\n";
                
                foreach ($members as $member_dn) {
                    echo "     - $member_dn\n";
                    if (stripos($member_dn, $machine_nom) !== false) {
                        $laravel_member_in_ad = true;
                    }
                }
                
                if ($laravel_member_in_ad) {
                    echo "✓ DN de la machine dans l'attribut 'member' du groupe\n";
                } else {
                    echo "❌ DN de la machine absent de l'attribut 'member'\n";
                }
            } else {
                echo "❌ Parc non trouvé dans l'AD\n";
            }
        } else {
            echo "❌ Erreur ajout machine: {$addResult['error']}\n";
        }
        
        // Nettoyage Laravel
        echo "\n5. Nettoyage parc Laravel...\n";
        $adSyncService->removeMemberFromGroup($workstation, $workstationGroup);
        $adSyncService->deleteWorkstationGroup($workstationGroup);
        $workstationGroup->delete();
        echo "✓ Parc Laravel nettoyé\n";
    }
} catch (Exception $e) {
    echo "❌ Exception Laravel: " . $e->getMessage() . "\n";
}

$laravel_success = $laravel_machine_added && $laravel_member_in_ad;

echo "\n";

// ============================================================================
// NETTOYAGE
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ NETTOYAGE                                                       │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Retirer la machine du parc legacy
// remove_member_parc() retire le DN de la machine de l'attribut 'member' du groupe CN
// Utilise groupdelmemberbydn() en interne
echo "Retrait de la machine du parc legacy...\n";
remove_member_parc($config, $legacy_parc_nom, $machine_nom);

// Supprimer le parc legacy
// delete_parc() supprime le groupe CN de OU=Parcs
// Pour une salle, supprime aussi l'OU de OU=Computers
echo "Suppression du parc legacy...\n";
delete_parc($config, $legacy_parc_nom);
echo "✓ Nettoyage terminé\n\n";

// ============================================================================
// RÉSUMÉ FINAL
// ============================================================================

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      RÉSUMÉ DE LA COMPARAISON                  ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ Ajout de machine à un parc                                     │\n";
echo "├─────────────────────────────────────────────────────────────────┤\n";
echo "│ Legacy:                                                         │\n";
echo "│   - Machine ajoutée: " . ($legacy_machine_added ? "✓ OUI" : "❌ NON") . "                                      │\n";
echo "│   - DN dans member:  " . ($legacy_member_in_ad ? "✓ OUI" : "❌ NON") . "                                      │\n";
echo "│                                                                 │\n";
echo "│ Laravel: " . ($laravel_success ? "✓ SUCCÈS" : "❌ ÉCHEC") . "                                              │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Verdict final
$all_success = $legacy_machine_added && 
               $legacy_member_in_ad && 
               $laravel_success;

if ($all_success) {
    echo "🎉 TOUS LES TESTS SONT PASSÉS!\n";
    echo "Les ajouts Legacy et Laravel produisent les mêmes résultats.\n";
    exit(0);
} else {
    echo "⚠️  CERTAINS TESTS ONT ÉCHOUÉ\n";
    echo "Vérifiez les logs ci-dessus pour plus de détails.\n";
    exit(1);
}
