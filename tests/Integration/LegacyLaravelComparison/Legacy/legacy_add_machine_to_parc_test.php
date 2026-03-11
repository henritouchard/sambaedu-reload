#!/usr/bin/env php
<?php
/**
 * Script de test Legacy - Ajout d'une machine à un parc
 * 
 * Usage: php legacy_add_machine_to_parc_test.php [parc] [machine]
 * 
 * Exemple:
 *   php legacy_add_machine_to_parc_test.php "TestParc" "PC-001"
 */

// Chargement de l'environnement legacy
$legacy_base = '/var/www/sambaedu';
require_once $legacy_base . '/includes/config.inc.php';
require_once $legacy_base . '/includes/ldap.inc.php';
require_once $legacy_base . '/includes/samba-tool.inc.php';

// Récupération des arguments
$parc_nom = $argv[1] ?? 'TestParc';
$machine_nom = $argv[2] ?? 'PC-TEST';

echo "=== Test Legacy - Ajout d'une machine à un parc ===\n";
echo "Parc: $parc_nom\n";
echo "Machine: $machine_nom\n\n";

// Chargement de la configuration
$config = get_config();

// 1. Vérifier que le parc existe
echo "1. Vérification de l'existence du parc...\n";
$parc = search_parcs($config, $parc_nom);

if (empty($parc)) {
    echo "❌ Erreur: Le parc '$parc_nom' n'existe pas dans l'AD\n";
    echo "Création du parc pour le test...\n";
    $result = create_parc($config, $parc_nom, "Test Parc for Machine Add", "", "parc");
    if (!$result) {
        echo "❌ Impossible de créer le parc\n";
        exit(1);
    }
    sleep(1);
    $parc = search_parcs($config, $parc_nom);
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
    echo "Veuillez créer la machine d'abord ou utiliser une machine existante.\n";
    exit(1);
}

echo "✓ Machine trouvée:\n";
echo "  - DN: {$machine['dn']}\n";
echo "  - CN: {$machine['cn']}\n\n";

// 3. Vérifier si la machine est déjà membre du parc
echo "3. Vérification de l'appartenance actuelle...\n";
$members_before = list_members_parc($config, $parc_nom, false, true);
$is_member_before = false;
foreach ($members_before as $member) {
    if (strcasecmp($member['cn'], $machine['cn']) == 0) {
        $is_member_before = true;
        break;
    }
}

if ($is_member_before) {
    echo "  ⚠ La machine est déjà membre du parc\n\n";
} else {
    echo "  ✓ La machine n'est pas encore membre du parc\n";
    echo "  - Nombre de membres actuels: " . count($members_before) . "\n\n";
}

// 4. Ajouter la machine au parc
echo "4. Ajout de la machine au parc via add_member_parc()...\n";
$result = add_member_parc($config, $parc_nom, $machine_nom);

if ($result) {
    echo "✓ Ajout réussi\n\n";
} else {
    echo "❌ Erreur lors de l'ajout\n\n";
    exit(1);
}

// Attendre un peu pour que l'AD soit à jour
sleep(1);

// 5. Vérifier l'ajout dans l'AD
echo "5. Vérification après ajout...\n";

// Récupérer les membres du parc
$members_after = list_members_parc($config, $parc_nom, false, true);
$is_member_after = false;
foreach ($members_after as $member) {
    if (strcasecmp($member['cn'], $machine['cn']) == 0) {
        $is_member_after = true;
        break;
    }
}

if ($is_member_after) {
    echo "✓ La machine est maintenant membre du parc\n";
    echo "  - Nombre de membres: " . count($members_after) . "\n";
} else {
    echo "❌ La machine n'est pas membre du parc après l'ajout\n";
    exit(1);
}

// 6. Vérifier l'attribut member du groupe CN
echo "\n6. Vérification de l'attribut 'member' du groupe CN...\n";
$group_info = search_ad($config, $parc_info['gdn'], "dn");
if (!empty($group_info) && isset($group_info[0]['member'])) {
    $members_dns = $group_info[0]['member'];
    if (!is_array($members_dns)) {
        $members_dns = [$members_dns];
    }
    
    $machine_in_members = false;
    foreach ($members_dns as $member_dn) {
        if (strcasecmp($member_dn, $machine['dn']) == 0) {
            $machine_in_members = true;
            break;
        }
    }
    
    if ($machine_in_members) {
        echo "✓ Le DN de la machine est présent dans l'attribut 'member' du groupe\n";
        echo "  - Nombre total de membres: " . count($members_dns) . "\n";
    } else {
        echo "❌ Le DN de la machine n'est pas dans l'attribut 'member'\n";
    }
} else {
    echo "⚠ Impossible de lire l'attribut 'member' du groupe\n";
}

echo "\n=== Test terminé ===\n";
