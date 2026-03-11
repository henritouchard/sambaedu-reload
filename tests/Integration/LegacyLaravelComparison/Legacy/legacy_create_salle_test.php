<?php
/**
 * Script de test Legacy pour la création d'une salle (P2)
 *
 * Ce script teste la création d'une salle avec le code legacy et documente
 * les écritures AD effectuées.
 *
 * Usage: php legacy_create_salle_test.php [nom_salle] [description] [parent]
 *
 * =============================================================================
 * DOCUMENTATION: Écritures AD effectuées par legacy pour créer une salle
 * =============================================================================
 *
 * 1. CRÉATION DU GROUPE CN dans OU=Parcs
 *    Fonction: groupadd() - /includes/samba-tool.inc.php:562-611
 *
 *    ldap_add($config['bind'], "CN={nom},OU=Parcs,OU={etab},DC=...", [
 *        "cn" => "{nom}",
 *        "objectclass" => ["top", "group"],
 *        "samaccountname" => "{nom}{suffix}",
 *        "grouptype" => 0x80000002,  // Domain Local Security Group
 *        "description" => "{description}",  // optionnel
 *    ])
 *
 * 2. CRÉATION DE L'OU dans OU=Computers
 *    Fonction: ouadd() - /includes/samba-tool.inc.php:387-431
 *
 *    ldap_add($config['bind'], "OU={nom},OU=Computers,OU={etab},DC=...", [
 *        "ou" => "{nom}",
 *        "objectClass" => "organizationalUnit",
 *    ])
 *
 *    Si parent spécifié:
 *    ldap_add($config['bind'], "OU={nom},OU={parent},OU=Computers,OU={etab},DC=...", [...])
 *
 * =============================================================================
 */

// Paramètres du test (avant les includes pour éviter les conflits)
$testName = $argv[1] ?? 'test-legacy-salle-' . uniqid();
$description = $argv[2] ?? 'Salle de test legacy';
$parent = $argv[3] ?? '';

echo "=============================================================================\n";
echo "TEST LEGACY: Création d'une salle\n";
echo "=============================================================================\n";
echo "Nom: $testName\n";
echo "Description: $description\n";
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

// Afficher les DN qui seront utilisés
$parcsDn = $config['parcs_rdn'] . "," . $config['ldap_base_dn'];
$computersDn = $config['dn']['computers'] ?? "OU=Computers," . $config['ldap_base_dn'];

echo "[2] DNs cibles:\n";
echo "    CN: CN=$testName,$parcsDn\n";
if ($parent) {
    echo "    OU: OU=$testName,OU=$parent,$computersDn\n";
} else {
    echo "    OU: OU=$testName,$computersDn\n";
}
echo "\n";

// Créer la salle avec la fonction legacy
echo "[3] Création de la salle (type=salle)...\n";
echo "    Appel: create_parc(\$config, '$testName', '$description', 'salle', '$parent')\n\n";

$startTime = microtime(true);
$result = create_parc($config, $testName, $description, 'salle', $parent);
$endTime = microtime(true);

if ($result) {
    echo "    OK - Salle créée en " . round(($endTime - $startTime) * 1000) . "ms\n\n";
} else {
    echo "    ERREUR - La création a échoué\n";
    echo "    Erreur LDAP: " . ldap_error($config['bind']) . "\n\n";
}

// Vérifier les objets créés
echo "[4] Vérification des objets créés dans l'AD...\n\n";

// Vérifier le CN
echo "    [4.1] Vérification du groupe CN dans OU=Parcs...\n";
$cnDn = "CN=$testName,$parcsDn";
$cnSearch = @ldap_read($config['bind'], $cnDn, '(objectClass=*)', ['*']);
if ($cnSearch) {
    $cnEntry = ldap_get_entries($config['bind'], $cnSearch);
    if ($cnEntry['count'] > 0) {
        echo "         OK - Groupe CN trouvé\n";
        echo "         Attributs:\n";
        $attrs = $cnEntry[0];
        echo "           - dn: " . ($attrs['dn'] ?? 'N/A') . "\n";
        echo "           - cn: " . ($attrs['cn'][0] ?? 'N/A') . "\n";
        echo "           - samaccountname: " . ($attrs['samaccountname'][0] ?? 'N/A') . "\n";
        echo "           - grouptype: " . ($attrs['grouptype'][0] ?? 'N/A');
        if (isset($attrs['grouptype'][0])) {
            echo " (0x" . dechex($attrs['grouptype'][0]) . ")";
        }
        echo "\n";
        echo "           - description: " . ($attrs['description'][0] ?? 'N/A') . "\n";
        echo "           - objectclass: " . implode(', ', array_filter($attrs['objectclass'] ?? [], fn($v) => !is_int($v))) . "\n";
        echo "           - objectguid: " . (isset($attrs['objectguid'][0]) ? bin2hex($attrs['objectguid'][0]) : 'N/A') . "\n";
    }
} else {
    echo "         ERREUR - Groupe CN non trouvé: " . ldap_error($config['bind']) . "\n";
}
echo "\n";

// Vérifier l'OU
echo "    [4.2] Vérification de l'OU dans OU=Computers...\n";
if ($parent) {
    $ouDn = "OU=$testName,OU=$parent,$computersDn";
} else {
    $ouDn = "OU=$testName,$computersDn";
}
$ouSearch = @ldap_read($config['bind'], $ouDn, '(objectClass=*)', ['*']);
if ($ouSearch) {
    $ouEntry = ldap_get_entries($config['bind'], $ouSearch);
    if ($ouEntry['count'] > 0) {
        echo "         OK - OU trouvée\n";
        echo "         Attributs:\n";
        $attrs = $ouEntry[0];
        echo "           - dn: " . ($attrs['dn'] ?? 'N/A') . "\n";
        echo "           - ou: " . ($attrs['ou'][0] ?? 'N/A') . "\n";
        echo "           - objectclass: " . implode(', ', array_filter($attrs['objectclass'] ?? [], fn($v) => !is_int($v))) . "\n";
        echo "           - objectguid: " . (isset($attrs['objectguid'][0]) ? bin2hex($attrs['objectguid'][0]) : 'N/A') . "\n";
    }
} else {
    echo "         ERREUR - OU non trouvée: " . ldap_error($config['bind']) . "\n";
}
echo "\n";

// Option de nettoyage
echo "=============================================================================\n";
echo "NETTOYAGE\n";
echo "=============================================================================\n";
echo "Pour supprimer cette salle de test, exécutez:\n";
echo "  php legacy_delete_salle_test.php $testName\n";
echo "\n";

// Fermer la connexion
ldap_close($config['bind']);

echo "Test terminé.\n";