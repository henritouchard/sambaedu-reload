<?php
/**
 * Script de test Legacy pour la suppression d'une salle (P4)
 *
 * Ce script supprime une salle de test créée par legacy_create_salle_test.php
 *
 * Usage: php legacy_delete_salle_test.php <nom_salle> [parent]
 *
 * =============================================================================
 * DOCUMENTATION: Écritures AD effectuées par legacy pour supprimer une salle
 * =============================================================================
 *
 * 1. SUPPRESSION DE L'OU dans OU=Computers
 *    Fonction: oudel() - /includes/samba-tool.inc.php
 *    ldap_delete($config['bind'], "OU={nom},OU=Computers,OU={etab},DC=...")
 *
 * 2. SUPPRESSION DU GROUPE CN dans OU=Parcs
 *    Fonction: groupdel() - /includes/samba-tool.inc.php
 *    ldap_delete($config['bind'], "CN={nom},OU=Parcs,OU={etab},DC=...")
 *
 * =============================================================================
 */

// Paramètres
$testName = $argv[1] ?? null;
$parent = $argv[2] ?? '';

if (!$testName) {
    die("Usage: php legacy_delete_salle_test.php <nom_salle> [parent]\n");
}

echo "=============================================================================\n";
echo "TEST LEGACY: Suppression d'une salle\n";
echo "=============================================================================\n";
echo "Nom: $testName\n";
echo "Parent: " . ($parent ?: '(aucun)') . "\n";
echo "=============================================================================\n\n";

// Charger config.inc.php qui initialise tout l'environnement legacy
echo "[1] Chargement de l'environnement legacy...\n";
$includesDir = '/var/www/sambaedu/includes';

// Simuler un environnement CLI minimal
$_SESSION = $_SESSION ?? [];
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';

// Charger la configuration et établir la connexion LDAP
require_once "$includesDir/config.inc.php";

// get_config() charge la config ET établit la connexion LDAP
$config = get_config([], true, false, "all");

if (empty($config['bind'])) {
    die("Erreur: Connexion LDAP non établie\n");
}
echo "    OK - Environnement chargé et connecté à l'AD\n\n";

// Charger les fonctions supplémentaires nécessaires
require_once "$includesDir/ldap.inc.php";

// Construire les DN
$parcsDn = $config['parcs_rdn'] . "," . $config['ldap_base_dn'];
$computersDn = $config['dn']['computers'] ?? "OU=Computers," . $config['ldap_base_dn'];

$cnDn = "CN=$testName,$parcsDn";
if ($parent) {
    $ouDn = "OU=$testName,OU=$parent,$computersDn";
} else {
    $ouDn = "OU=$testName,$computersDn";
}

echo "[2] DNs à supprimer:\n";
echo "    CN: $cnDn\n";
echo "    OU: $ouDn\n\n";

// Supprimer la salle avec la fonction legacy
echo "[3] Suppression de la salle...\n";
echo "    Appel: delete_parc(\$config, '$testName', '$parent')\n\n";

$startTime = microtime(true);
$result = delete_parc($config, $testName, $parent);
$endTime = microtime(true);

if ($result) {
    echo "    OK - Salle supprimée en " . round(($endTime - $startTime) * 1000) . "ms\n\n";
} else {
    echo "    ERREUR - La suppression a échoué\n";
    echo "    Erreur LDAP: " . ldap_error($config['bind']) . "\n\n";
}

// Vérifier que les objets sont supprimés
echo "[4] Vérification de la suppression...\n\n";

// Vérifier le CN
echo "    [4.1] Vérification du groupe CN...\n";
$cnSearch = @ldap_read($config['bind'], $cnDn, '(objectClass=*)', ['cn']);
if ($cnSearch && ldap_count_entries($config['bind'], $cnSearch) > 0) {
    echo "         ERREUR - Le groupe CN existe encore\n";
} else {
    echo "         OK - Groupe CN supprimé\n";
}

// Vérifier l'OU
echo "    [4.2] Vérification de l'OU...\n";
$ouSearch = @ldap_read($config['bind'], $ouDn, '(objectClass=*)', ['ou']);
if ($ouSearch && ldap_count_entries($config['bind'], $ouSearch) > 0) {
    echo "         ERREUR - L'OU existe encore\n";
} else {
    echo "         OK - OU supprimée\n";
}
echo "\n";

// Fermer la connexion
ldap_close($config['bind']);

echo "Suppression terminée.\n";