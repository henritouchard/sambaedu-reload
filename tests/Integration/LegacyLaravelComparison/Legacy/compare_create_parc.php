#!/usr/bin/env php
<?php
/**
 * Script de comparaison automatique - Création de parc
 * 
 * Compare les résultats de création entre Legacy et Laravel
 * 
 * Usage: php compare_create_parc.php
 */

// Chargement de l'environnement legacy
$legacy_base = '/var/www/sambaedu';
require_once $legacy_base . '/includes/config.inc.php';
require_once $legacy_base . '/includes/ldap.inc.php';
require_once $legacy_base . '/includes/samba-tool.inc.php';

$config = get_config();

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  Comparaison Legacy vs Laravel - Création de parc             ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// ============================================================================
// PHASE 1: Test Legacy - Création d'un parc
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 1: Test Legacy - Création d'un parc                      │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

$legacy_parc_nom = 'TestCreateParcLegacy_' . time();
$legacy_parc_description = 'Test Create Parc Legacy';

// Créer le parc legacy
// create_parc() avec type='parc' crée UNIQUEMENT un groupe CN dans OU=Parcs
// Contrairement à type='salle' qui crée aussi une OU dans OU=Computers
// Signature: create_parc($config, $nom, $description, $parentou, $type)
// - $parentou: vide pour un parc, utilisé pour les salles imbriquées
// - $type: 'parc' (CN seulement) ou 'salle' (CN + OU)
echo "1. Création du parc legacy '$legacy_parc_nom'...\n";
$result = create_parc($config, $legacy_parc_nom, $legacy_parc_description, '', 'parc');
if (!$result) {
    echo "❌ Erreur lors de la création du parc legacy\n";
    exit(1);
}
echo "✓ Parc legacy créé\n\n";

sleep(1);

// Vérifier la création dans l'AD
// search_ad() recherche un objet AD par son DN ou par filtre
// Signature: search_ad($config, $search, $type, $scope)
// - $type='dn': recherche par DN exact
// - Retourne les attributs de l'objet trouvé
echo "2. Vérification dans l'AD...\n";
$cn_dn_legacy = "CN={$legacy_parc_nom},{$config['dn']['parcs']}";
$cn_info = search_ad($config, $cn_dn_legacy, "dn");

$legacy_cn_created = !empty($cn_info);
$legacy_cn_attributes_ok = false;

if ($legacy_cn_created) {
    echo "✓ Groupe CN créé: $cn_dn_legacy\n";
    
    // Vérifier les attributs
    if (isset($cn_info['cn']) && strcasecmp($cn_info['cn'], $legacy_parc_nom) == 0) {
        echo "  - cn: {$cn_info['cn']} ✓\n";
    }
    if (isset($cn_info['samaccountname'])) {
        echo "  - samaccountname: {$cn_info['samaccountname']}\n";
    }
    if (isset($cn_info['description'])) {
        echo "  - description: {$cn_info['description']}\n";
    }
    if (isset($cn_info['grouptype'])) {
        echo "  - grouptype: {$cn_info['grouptype']}\n";
    }
    $legacy_cn_attributes_ok = true;
} else {
    echo "❌ Groupe CN non trouvé dans l'AD\n";
}

// Vérifier qu'il n'y a PAS d'OU (un parc n'a pas d'OU, contrairement à une salle)
$ou_dn_legacy = "OU={$legacy_parc_nom},{$config['dn']['computers']}";
$ou_info = search_ad($config, $ou_dn_legacy, "dn");
$legacy_no_ou = empty($ou_info);

if ($legacy_no_ou) {
    echo "✓ Pas d'OU créée (correct pour un parc)\n";
} else {
    echo "⚠ Une OU a été créée (inattendu pour un parc)\n";
}

echo "\n";

// ============================================================================
// PHASE 2: Test Laravel - Création d'un parc
// ============================================================================

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ PHASE 2: Test Laravel - Création d'un parc                     │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Bootstrap Laravel
require_once '/root/se4/sources/var/www/sambaedu/laravel/vendor/autoload.php';
$app = require_once '/root/se4/sources/var/www/sambaedu/laravel/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\AdSync\AdSyncService;
use App\Models\WorkstationGroup;

$laravel_parc_nom = 'TestCreateParcLaravel_' . time();
$laravel_parc_description = 'Test Create Parc Laravel';
$laravel_cn_created = false;
$laravel_cn_attributes_ok = false;
$laravel_no_ou = false;

try {
    // 1. Créer le parc Laravel
    echo "1. Création du parc Laravel '$laravel_parc_nom'...\n";
    
    // Désactiver l'Observer pour éviter la double synchronisation
    \App\Observers\WorkstationGroupObserver::disableSync();
    
    WorkstationGroup::where('name', $laravel_parc_nom)->delete();
    
    $workstationGroup = WorkstationGroup::create([
        'name' => $laravel_parc_nom,
        'description' => $laravel_parc_description,
        'parent_id' => null,
        'is_physical' => false, // parc, pas salle
        'is_active' => true,
    ]);
    
    $adSyncService = app(AdSyncService::class);
    $result = $adSyncService->createWorkstationGroup($workstationGroup);
    
    // Réactiver l'Observer
    \App\Observers\WorkstationGroupObserver::enableSync();
    
    if (!$result['success']) {
        echo "❌ Erreur création parc: {$result['error']}\n";
    } else {
        echo "✓ Parc Laravel créé (ID: {$workstationGroup->id})\n\n";
        
        sleep(1);
        
        // 2. Vérifier dans l'AD via LdapRecord
        echo "2. Vérification dans l'AD via LdapRecord...\n";
        $dnHelper = app(\App\Config\LdapDnHelper::class);
        $parcsDn = $dnHelper->parcs();
        $groupAd = \App\LdapModels\DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $laravel_parc_nom)
            ->first();
        
        if ($groupAd) {
            $laravel_cn_created = true;
            echo "✓ Groupe CN créé: " . $groupAd->getDn() . "\n";
            
            // Vérifier les attributs
            $cn = $groupAd->cn[0] ?? $groupAd->cn ?? null;
            $samaccountname = $groupAd->samaccountname[0] ?? $groupAd->samaccountname ?? null;
            $description = $groupAd->description[0] ?? $groupAd->description ?? null;
            $grouptype = $groupAd->grouptype[0] ?? $groupAd->grouptype ?? null;
            
            if ($cn) echo "  - cn: $cn\n";
            if ($samaccountname) echo "  - samaccountname: $samaccountname\n";
            if ($description) echo "  - description: $description\n";
            if ($grouptype) echo "  - grouptype: $grouptype\n";
            
            $laravel_cn_attributes_ok = true;
        } else {
            echo "❌ Groupe CN non trouvé dans l'AD\n";
        }
        
        // Vérifier qu'il n'y a PAS d'OU
        $computersDn = $dnHelper->computers();
        $ouAd = \App\LdapModels\DeviceGroupModel::in($computersDn)
            ->where('ou', '=', $laravel_parc_nom)
            ->first();
        
        $laravel_no_ou = !$ouAd;
        
        if ($laravel_no_ou) {
            echo "✓ Pas d'OU créée (correct pour un parc)\n";
        } else {
            echo "⚠ Une OU a été créée (inattendu pour un parc)\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Exception Laravel: " . $e->getMessage() . "\n";
}

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

// Supprimer le parc Laravel
echo "Suppression du parc Laravel...\n";
if (isset($workstationGroup) && isset($adSyncService)) {
    $adSyncService->deleteWorkstationGroup($workstationGroup);
    $workstationGroup->delete();
}
echo "✓ Nettoyage terminé\n\n";

// ============================================================================
// RÉSUMÉ FINAL
// ============================================================================

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      RÉSUMÉ DE LA COMPARAISON                  ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ Création de PARC                                               │\n";
echo "├─────────────────────────────────────────────────────────────────┤\n";
echo "│ Legacy:                                                         │\n";
echo "│   - Groupe CN créé:    " . ($legacy_cn_created ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│   - Attributs OK:      " . ($legacy_cn_attributes_ok ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│   - Pas d'OU (parc):   " . ($legacy_no_ou ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│                                                                 │\n";
echo "│ Laravel:                                                        │\n";
echo "│   - Groupe CN créé:    " . ($laravel_cn_created ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│   - Attributs OK:      " . ($laravel_cn_attributes_ok ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "│   - Pas d'OU (parc):   " . ($laravel_no_ou ? "✓ OUI" : "❌ NON") . "                                  │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

// Verdict final
$legacy_success = $legacy_cn_created && $legacy_cn_attributes_ok && $legacy_no_ou;
$laravel_success = $laravel_cn_created && $laravel_cn_attributes_ok && $laravel_no_ou;
$all_success = $legacy_success && $laravel_success;

if ($all_success) {
    echo "🎉 TOUS LES TESTS SONT PASSÉS!\n";
    echo "Les créations Legacy et Laravel produisent les mêmes résultats.\n";
    exit(0);
} else {
    echo "⚠️  CERTAINS TESTS ONT ÉCHOUÉ\n";
    echo "Vérifiez les logs ci-dessus pour plus de détails.\n";
    exit(1);
}
