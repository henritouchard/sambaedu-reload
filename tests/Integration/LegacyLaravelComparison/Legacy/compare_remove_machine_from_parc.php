#!/usr/bin/env php
<?php
/**
 * Script de comparaison automatique - Retrait d'une machine d'un parc
 * 
 * Compare les résultats de retrait entre Legacy et Laravel
 * 
 * Usage: php compare_remove_machine_from_parc.php
 */

// Chargement de l'environnement legacy
$legacy_base = '/var/www/sambaedu';
require_once $legacy_base . '/includes/config.inc.php';
require_once $legacy_base . '/includes/ldap.inc.php';
require_once $legacy_base . '/includes/samba-tool.inc.php';

$config = get_config();

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  Comparaison Legacy vs Laravel - Retrait machine d'un parc     ║\n";
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
// PHASE 1: Test Legacy - Retrait d'une machine d'un parc
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 1: Test Legacy - Retrait d'une machine d'un parc         │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

$legacy_parc_nom = 'TestRemoveMachineLegacy_' . time();

// Créer le parc legacy
// create_parc() crée un groupe CN dans OU=Parcs
// Ce groupe contiendra l'attribut 'member' listant les machines membres
echo "1. Création du parc legacy '$legacy_parc_nom'...\n";
$result = create_parc($config, $legacy_parc_nom, 'Test Remove Machine Legacy', '', 'parc');
if (!$result) {
    echo "❌ Erreur lors de la création du parc legacy\n";
    exit(1);
}
echo "✓ Parc legacy créé\n\n";

sleep(1);

// Ajouter la machine au parc legacy
// add_member_parc() ajoute le DN de la machine à l'attribut 'member' du groupe CN
// L'objectif est d'ajouter la machine au parc pour simuler une opération de gestion de parc
echo "2. Ajout de la machine au parc legacy...\n";
$result = add_member_parc($config, $legacy_parc_nom, $machine_nom);
if (!$result) {
    echo "❌ Erreur lors de l'ajout de la machine au parc legacy\n";
    exit(1);
}
echo "✓ Machine ajoutée au parc legacy\n\n";

sleep(1);

// Vérifier l'état avant retrait
echo "3. Vérification avant retrait...\n";
$members_before_legacy = list_members_parc($config, $legacy_parc_nom, false, true);
$is_member_before_legacy = false;
foreach ($members_before_legacy as $member) {
    $member_cn = is_array($member) ? $member['cn'] : $member;
    if (strcasecmp($member_cn, $machine_nom) == 0) {
        $is_member_before_legacy = true;
        break;
    }
}
echo "✓ Machine membre: " . ($is_member_before_legacy ? "Oui" : "Non") . "\n";
echo "  - Membres actuels: " . count($members_before_legacy) . "\n\n";

// Retirer la machine du parc legacy
// remove_member_parc() retire le DN de la machine de l'attribut 'member' du groupe CN
// Signature: remove_member_parc($config, $parc, $member)
// - Pour une salle: si la machine est dans l'OU de la salle, appelle move_member_parc()
// - Pour un parc: utilise groupdelmemberbydn() pour modifier l'attribut LDAP
// - Idempotent: retourne true si la machine n'est pas membre
echo "4. Retrait de la machine du parc legacy...\n";
$result = remove_member_parc($config, $legacy_parc_nom, $machine_nom);
if (!$result) {
    echo "❌ Erreur lors du retrait de la machine du parc legacy\n";
    exit(1);
}
echo "✓ Machine retirée du parc legacy\n\n";

sleep(1);

// Vérifier le retrait
echo "5. Vérification après retrait...\n";
$members_after_legacy = list_members_parc($config, $legacy_parc_nom, false, true);
$legacy_machine_removed = true;
foreach ($members_after_legacy as $member) {
    $member_cn = is_array($member) ? $member['cn'] : $member;
    if (strcasecmp($member_cn, $machine_nom) == 0) {
        $legacy_machine_removed = false;
        break;
    }
}

if ($legacy_machine_removed) {
    echo "✓ Machine retirée du parc\n";
    echo "  - Nombre de membres: " . count($members_after_legacy) . "\n";
} else {
    echo "❌ Machine encore membre du parc\n";
}

// Vérifier l'attribut member du groupe
$parc_info_legacy = search_parcs($config, $legacy_parc_nom);
$group_info_legacy = search_ad($config, $parc_info_legacy[0]['gdn'], "dn");
$legacy_member_not_in_ad = true;

if (!empty($group_info_legacy)) {
    $members_dns = $group_info_legacy[0]['member'] ?? [];
    if (!is_array($members_dns)) {
        $members_dns = $members_dns ? [$members_dns] : [];
    }
    
    foreach ($members_dns as $member_dn) {
        if (strcasecmp($member_dn, $test_machine['dn']) == 0) {
            $legacy_member_not_in_ad = false;
            break;
        }
    }
}

if ($legacy_member_not_in_ad) {
    echo "✓ DN de la machine absent de l'attribut 'member' du groupe\n";
} else {
    echo "❌ DN de la machine encore dans l'attribut 'member'\n";
}

echo "\n";

// ============================================================================
// PHASE 2: Test Laravel - Retrait d'une machine d'un parc
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 2: Test Laravel - Retrait d'une machine d'un parc        │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Bootstrap Laravel
require_once '/root/se4/sources/var/www/sambaedu/laravel/vendor/autoload.php';
$app = require_once '/root/se4/sources/var/www/sambaedu/laravel/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\AdSync\AdSyncService;
use App\Models\WorkstationGroup;
use App\Models\Workstation;

$laravel_parc_nom = 'TestRemoveMachineLaravel_' . time();
$laravel_machine_removed = false;
$laravel_member_absent_ad = false;

try {
    // 1. Créer le parc Laravel
    echo "1. Création du parc Laravel '$laravel_parc_nom'...\n";
    
    // Nettoyer si existe déjà
    WorkstationGroup::where('name', $laravel_parc_nom)->delete();
    
    $workstationGroup = WorkstationGroup::create([
        'name' => $laravel_parc_nom,
        'description' => 'Test Remove Machine Laravel',
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
        
        // 3. Ajouter la machine au parc d'abord
        echo "3. Ajout de la machine au parc Laravel...\n";
        $addResult = $adSyncService->addMemberToGroup($workstation, $workstationGroup);
        
        if (!$addResult['success']) {
            echo "❌ Erreur ajout machine: {$addResult['error']}\n";
        } else {
            echo "✓ Machine ajoutée au parc Laravel\n\n";
            
            sleep(1);
            
            // 4. Retirer la machine du parc
            echo "4. Retrait de la machine du parc Laravel...\n";
            $removeResult = $adSyncService->removeMemberFromGroup($workstation, $workstationGroup);
            
            if ($removeResult['success']) {
                echo "✓ Machine retirée du parc Laravel\n\n";
                $laravel_machine_removed = true;
                
                sleep(1);
                
                // 5. Vérifier dans l'AD via LdapRecord
                echo "5. Vérification dans l'AD...\n";
                
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
                    
                    $machine_still_member = false;
                    foreach ($members as $member_dn) {
                        echo "     - $member_dn\n";
                        if (stripos($member_dn, $machine_nom) !== false) {
                            $machine_still_member = true;
                        }
                    }
                    
                    $laravel_member_absent_ad = !$machine_still_member;
                    
                    if ($laravel_member_absent_ad) {
                        echo "✓ DN de la machine absent de l'attribut 'member' du groupe\n";
                    } else {
                        echo "❌ DN de la machine encore dans l'attribut 'member'\n";
                    }
                } else {
                    echo "❌ Parc non trouvé dans l'AD\n";
                }
            } else {
                echo "❌ Erreur retrait machine: {$removeResult['error']}\n";
            }
        }
        
        // Nettoyage Laravel
        echo "\n6. Nettoyage parc Laravel...\n";
        $adSyncService->deleteWorkstationGroup($workstationGroup);
        $workstationGroup->delete();
        echo "✓ Parc Laravel nettoyé\n";
    }
} catch (Exception $e) {
    echo "❌ Exception Laravel: " . $e->getMessage() . "\n";
}

$laravel_success = $laravel_machine_removed && $laravel_member_absent_ad;

echo "\n";

// ============================================================================
// NETTOYAGE
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ NETTOYAGE                                                       │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Supprimer le parc legacy
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
echo "│ Retrait de machine d'un parc                                   │\n";
echo "├─────────────────────────────────────────────────────────────────┤\n";
echo "│ Legacy:                                                         │\n";
echo "│   - Machine retirée:  " . ($legacy_machine_removed ? "✓ OUI" : "❌ NON") . "                                     │\n";
echo "│   - DN absent member: " . ($legacy_member_not_in_ad ? "✓ OUI" : "❌ NON") . "                                     │\n";
echo "│                                                                 │\n";
echo "│ Laravel: " . ($laravel_success ? "✓ SUCCÈS" : "❌ ÉCHEC") . "                                              │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Verdict final
$all_success = $legacy_machine_removed && 
               $legacy_member_not_in_ad && 
               $laravel_success;

if ($all_success) {
    echo "🎉 TOUS LES TESTS SONT PASSÉS!\n";
    echo "Les retraits Legacy et Laravel produisent les mêmes résultats.\n";
    exit(0);
} else {
    echo "⚠️  CERTAINS TESTS ONT ÉCHOUÉ\n";
    echo "Vérifiez les logs ci-dessus pour plus de détails.\n";
    exit(1);
}
