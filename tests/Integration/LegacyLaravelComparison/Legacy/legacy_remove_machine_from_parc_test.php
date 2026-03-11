#!/usr/bin/env php
<?php
/**
 * Script de test Legacy - Retrait d'une machine d'un parc
 * 
 * Usage: php legacy_remove_machine_from_parc_test.php [parc] [machine]
 * 
 * Exemple:
 *   php legacy_remove_machine_from_parc_test.php "TestParc" "PC-001"
 */

// Chargement de l'environnement legacy
$legacy_base = '/var/www/sambaedu';
require_once $legacy_base . '/includes/config.inc.php';
require_once $legacy_base . '/includes/ldap.inc.php';
require_once $legacy_base . '/includes/samba-tool.inc.php';

// Récupération des arguments
$parc_nom = $argv[1] ?? 'TestParc';
$machine_nom = $argv[2] ?? 'PC-TEST';

echo "=== Test Legacy - Retrait d'une machine d'un parc ===\n";
echo "Parc: $parc_nom\n";
echo "Machine: $machine_nom\n\n";

// Chargement de la configuration
$config = get_config();

// 1. Vérifier que le parc existe
echo "1. Vérification de l'existence du parc...\n";
$parc = search_parcs($config, $parc_nom);

if (empty($parc)) {
    echo "❌ Erreur: Le parc '$parc_nom' n'existe pas dans l'AD\n";
    exit(1);
}

$parc_info = $parc[0];
echo "✓ Parc trouvé:\n";
echo "  - DN groupe: {$parc_info['gdn']}\n";
echo "  - Type: {$parc_info['type']}\n\n";

// 2. Vérifier que la machine existe
echo "2. Vérification de l'existence de la machine...\n";
$machine = search_machine($config, $machine_nom);

if (empty($machine) || !isset($machine['dn'])) {
    echo "❌ Erreur: La machine '$machine_nom' n'existe pas dans l'AD\n";
    exit(1);
}

echo "✓ Machine trouvée:\n";
echo "  - DN: {$machine['dn']}\n";
echo "  - CN: {$machine['cn']}\n\n";

// 3. Vérifier si la machine est membre du parc
echo "3. Vérification de l'appartenance actuelle...\n";
$members_before = list_members_parc($config, $parc_nom, false, true);
$is_member_before = false;
foreach ($members_before as $member) {
    if (strcasecmp($member['cn'], $machine['cn']) == 0) {
        $is_member_before = true;
        break;
    }
}

if (!$is_member_before) {
    echo "  ⚠ La machine n'est pas membre du parc\n";
    echo "  Ajout de la machine pour le test...\n";
    add_member_parc($config, $parc_nom, $machine_nom);
    sleep(1);
    $members_before = list_members_parc($config, $parc_nom, false, true);
    echo "  ✓ Machine ajoutée\n\n";
} else {
    echo "  ✓ La machine est membre du parc\n";
    echo "  - Nombre de membres actuels: " . count($members_before) . "\n\n";
}

// 4. Retirer la machine du parc
echo "4. Retrait de la machine du parc via remove_member_parc()...\n";
$result = remove_member_parc($config, $parc_nom, $machine_nom);

if ($result) {
    echo "✓ Retrait réussi\n\n";
} else {
    echo "❌ Erreur lors du retrait\n\n";
    exit(1);
}

// Attendre un peu pour que l'AD soit à jour
sleep(1);

// 5. Vérifier le retrait dans l'AD
echo "5. Vérification après retrait...\n";

// Récupérer les membres du parc
$members_after = list_members_parc($config, $parc_nom, false, true);
$is_member_after = false;
foreach ($members_after as $member) {
    if (strcasecmp($member['cn'], $machine['cn']) == 0) {
        $is_member_after = true;
        break;
    }
}

if (!$is_member_after) {
    echo "✓ La machine n'est plus membre du parc\n";
    echo "  - Nombre de membres: " . count($members_after) . "\n";
} else {
    echo "❌ La machine est encore membre du parc après le retrait\n";
    exit(1);
}

// 6. Vérifier l'attribut member du groupe CN
echo "\n6. Vérification de l'attribut 'member' du groupe CN...\n";
$group_info = search_ad($config, $parc_info['gdn'], "dn");
if (!empty($group_info)) {
    $members_dns = $group_info[0]['member'] ?? [];
    if (!is_array($members_dns)) {
        $members_dns = $members_dns ? [$members_dns] : [];
    }
    
    $machine_in_members = false;
    foreach ($members_dns as $member_dn) {
        if (strcasecmp($member_dn, $machine['dn']) == 0) {
            $machine_in_members = true;
            break;
        }
    }
    
    if (!$machine_in_members) {
        echo "✓ Le DN de la machine n'est plus dans l'attribut 'member' du groupe\n";
        echo "  - Nombre total de membres: " . count($members_dns) . "\n";
    } else {
        echo "❌ Le DN de la machine est encore dans l'attribut 'member'\n";
    }
} else {
    echo "⚠ Impossible de lire l'attribut 'member' du groupe\n";
}

echo "\n=== Test terminé ===\n";
