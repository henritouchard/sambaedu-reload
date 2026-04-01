<?php
/*
 * page pour signaler la fin d'une install linux
 *
 * Auteur : denis bonnenfant
 *
 *
 * $_POST['name'] : nom
 * $_POST['uuid'] : action en cours
 * $_POST['ret'] : code sortie ($?)
 *
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require "ipxe_functions.inc.php";
require "templates.inc.php";

$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$name = $_POST['name'] ?? $_GET['name'] ?? "";
$ret = $_POST['ret'] ?? $_GET['ret'] ?? ""; // code sortie du script
header("Content-type: text/plain");

if (auth_action($config, $name, $uuid)) {
    $machine = get_action($config, $name, $uuid);
    $ou = ldap_dn2oudn($machine['dn']);
    $type = $machine['action']['type'];
    $role = $machine['action'][$uuid]['role'] ?? "";
    $etape = $machine['action'][$uuid]['ret'];
    $script = $machine['action'][$uuid]['script'] ?? "default";
    $id = $machine['id'];
    $disks = @$machine['action']['disks'];
    if ($ret == 0) {
        set_progress($id, "100%");
        set_statut($id, "installation Linux terminée");
        // delete_action($id);
    }
} else {
    echo "echo erreur $uuid $name\n";
}
?>