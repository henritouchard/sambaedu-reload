#!/usr/bin/env php
<?php
/**
 * Script de test Legacy - Suppression d'un parc/salle
 * 
 * Usage: php legacy_delete_parc_test.php [nom] [parent]
 * 
 * Exemple:
 *   php legacy_delete_parc_test.php "TestParc" ""
 *   php legacy_delete_parc_test.php "TestSalle" "Computers"
 */

// Chargement de l'environnement legacy
$legacy_base = '/var/www/sambaedu';
require_once $legacy_base . '/includes/config.inc.php';
require_once $legacy_base . '/includes/ldap.inc.php';
require_once $legacy_base . '/includes/samba-tool.inc.php';

// Récupération des arguments
$nom = $argv[1] ?? 'TestDeleteParc';
$parent = $argv[2] ?? '';

echo "=== Test Legacy - Suppression de parc/salle ===\n";
echo "Nom: $nom\n";
echo "Parent: " . ($parent ?: 'Computers (parc racine)') . "\n\n";

// Chargement de la configuration
$config = get_config();

// Recherche du parc/salle avant suppression
echo "1. Recherche du parc/salle avant suppression...\n";
$parc_before = search_parcs($config, $nom);

if (empty($parc_before)) {
    echo "❌ Erreur: Le parc/salle '$nom' n'existe pas dans l'AD\n";
    exit(1);
}

$parc_info = $parc_before[0];
echo "✓ Parc/salle trouvé:\n";
echo "  - Type: {$parc_info['type']}\n";
echo "  - DN groupe: {$parc_info['gdn']}\n";
echo "  - DN OU: {$parc_info['dn']}\n";
echo "  - Parent: {$parc_info['parent']}\n\n";

// Vérification des machines dans le parc
echo "2. Vérification des machines dans le parc...\n";
$machines = list_members_parc($config, $nom, true);
echo "  - Nombre de machines: " . count($machines) . "\n";
if (!empty($machines)) {
    echo "  - Machines:\n";
    foreach ($machines as $machine) {
        echo "    * {$machine['cn']}\n";
    }
}
echo "\n";

// Vérification des délégations
echo "3. Vérification des délégations...\n";
$delegations = list_delegations($config, $nom);
if ($delegations) {
    echo "  - Nombre de délégations: " . count($delegations) . "\n";
    foreach ($delegations as $delegation) {
        echo "    * {$delegation['cn']}\n";
    }
} else {
    echo "  - Aucune délégation\n";
}
echo "\n";

// Suppression du parc/salle
echo "4. Suppression du parc/salle via delete_parc()...\n";
$result = delete_parc($config, $nom);

if ($result) {
    echo "✓ Suppression réussie\n\n";
} else {
    echo "❌ Erreur lors de la suppression\n\n";
    exit(1);
}

// Vérification après suppression
echo "5. Vérification après suppression...\n";

// Vérification du groupe CN
echo "  a) Vérification du groupe CN...\n";
$cn_dn = "CN={$nom},OU=Parcs,OU={$config['etab']},{$config['ldap_base_dn']}";
$cn_exists = search_ad($config, $cn_dn, "dn");
if (empty($cn_exists)) {
    echo "  ✓ Groupe CN supprimé\n";
} else {
    echo "  ❌ Groupe CN existe encore: $cn_dn\n";
}

// Vérification de l'OU (si c'était une salle)
if ($parc_info['type'] == 'salle') {
    echo "  b) Vérification de l'OU...\n";
    $ou_exists = search_ad($config, $parc_info['dn'], "dn");
    if (empty($ou_exists)) {
        echo "  ✓ OU supprimé\n";
    } else {
        echo "  ❌ OU existe encore: {$parc_info['dn']}\n";
    }
}

// Vérification des délégations
if ($delegations) {
    echo "  c) Vérification des délégations...\n";
    foreach ($delegations as $delegation) {
        $del_exists = search_ad($config, $delegation['dn'], "dn");
        if (empty($del_exists)) {
            echo "  ✓ Délégation supprimée: {$delegation['cn']}\n";
        } else {
            echo "  ❌ Délégation existe encore: {$delegation['cn']}\n";
        }
    }
}

echo "\n=== Test terminé ===\n";
