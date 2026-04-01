<?php
/*
 * Lancement d'une action, passée en POST
 * les actions sont dans actions/
 * il suffit de mettre les commandes ipxe concernant l'action à faire
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once ('ipxe_functions.inc.php');

$ipxe = "#!ipxe\n";
$menu_timeout = '10000';
$ipxe .= "console --x 1024 --y 768 --picture png/ipxe-se4.png\n";
$action = $_POST['action'] ?? $_GET['action'] ?? "default";
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$session_ipxe = $_POST['session_ipxe'] ?? $_GET['session_ipxe'] ?? "NOTHING";
$mac = $_POST['mac'] ?? $_GET['mac'] ?? "";
$name = $_POST['name'] ?? $_GET['name'] ?? "";
$script = $_POST['script'] ?? $_GET['script'] ?? "";
$debug = $_POST['debug'] ?? $_GET['debug'] ?? 0;
$disk = $_POST['disk'] ?? $_GET['disk'] ?? 0;
$perso = $_POST['perso'] ?? $_GET['perso'] ?? 0;
$version = $_POST['version'] ?? $_GET['version'] ?? "Win10";

if (auth_action($config, $mac, $session_ipxe) || $action == "ltsp") {
    $machine = @get_action($config, $uuid, $mac);
    if (@$machine['action'][$uuid]['role'] == "clone")
        $script = "rescuecd";
    if (file_exists(__DIR__ . "/actions/" . $action . ".php")) {
        include __DIR__ . "/actions/" . $action . ".php";
    } else {
        set_action($config, $uuid, [
            'type' => "default",
            'script' => "default",
            'etape' => "default",
            'ret' => - 1
        ]);
        $ipxe .= boot_disk();
    }
} else {
    $ipxe .= "params\n";
    $ipxe .= "param mac " . $mac . "\n";
    $ipxe .= "param uuid " . $uuid . "\n";
    $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
    $ipxe .= "param debug " . $debug . "\n";
    $ipxe .= "chain --replace --autofree admin.php##params\n";
}
ipxe_out($config, $ipxe);
?>