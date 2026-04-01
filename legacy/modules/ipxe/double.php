<?php
/*
 * Met /enleve le double boot
 *
 *
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once ('ipxe_functions.inc.php');

$ipxe = "#!ipxe\n";
$menu_timeout = '10000';
$ipxe .= "console --x 1024 --y 768 --picture png/ipxe-se4.png\n";

$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$session_ipxe = $_POST['session_ipxe'] ?? "NOTHING";
$mac = $_POST['mac'] ?? "";
$double = $_POST['double'] ?? 0;
if (! empty($uuid)) {
    $machine = get_action($config, $uuid);
    if (count($machine) > 0) {
        if ($double == 1) {
            add_dual_boot($config, $machine['cn']);
        } else {
            remove_dual_boot($config, $machine['cn']);
        }
    }
}
$ipxe .= "params\n";
$ipxe .= "param menu-default parcs\n";
$ipxe .= "param uuid " . $uuid . "\n";
$ipxe .= "param session_ipxe " . $session_ipxe . "\n";
$ipxe .= "param mac " . $mac . "\n";
$ipxe .= "chain --replace --autofree admin.php##params\n";
ipxe_out($config, $ipxe);
?>