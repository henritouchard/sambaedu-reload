<?php
/*
 * Enleve la machine d un parc
 *
 *
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once ('ipxe_functions.inc.php');

$url = "http://{$_SERVER["SERVER_ADDR"]}:{$_SERVER["SERVER_PORT"]}/ipxe/";
$ipxe = "#!ipxe\n";
$menu_timeout = '10000';
$ipxe .= "console --x 1024 --y 768 --picture png/ipxe-se4.png\n";

$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$session_ipxe = $_POST['session_ipxe'] ?? "NOTHING";
$mac = $_POST['mac'] ?? "";

if (! empty($uuid)) {
    $machine = get_action($config, $uuid);
    if (count($machine) > 0) {
        $dispo = search_parcs($config, "*", "parcs");
        $parcs = list_parcs($config, $machine['cn'], "parcs");
        if (isset($_POST['parc'])) {
            $parc = $_POST['parc'];
            if (in_array($parc, array_column($parcs, "samaccountname"))) {
                $res = remove_member_parc($config, $parc, $machine['cn']);
                if ($res) {
                    $ipxe .= "echo La machine a ete enlevee du parc " . $parc . "\n";
                    $ipxe .= "sleep 3\n";
                } else {
                    $ipxe .= "echo ERREUR La machine n'a pas ete enlevee du parc " . $parc . "\n";
                    $ipxe .= "sleep 3\n";
                }
            } else {
                $ipxe .= "echo La machine n'est pas dans le parc " . $parc . "\n";
                $ipxe .= "sleep 3\n";
            }
        } else {
            $ipxe .= "menu Suppression d'un parc pour " . $machine['cn'] . "\n";
            $ipxe .= "set menu-default fin\n";
            $ipxe .= "set menu-timeout $menu_timeout\n";
            foreach ($dispo as $p) {
                if (in_array($p['samaccountname'], array_column($parcs, "samaccountname"))) {
                    $ipxe .= "item  " . $p['samaccountname'] . " " . $p['name'] . "\n";
                }
            }
            $ipxe .= "item  fin Retour au menu principal\n";
            $ipxe .= "choose --default \${menu-default} --timeout \${menu-timeout} selected && goto \${selected} || exit 0\n";
            foreach ($dispo as $p) {
                $ipxe .= ":" . $p['samaccountname'] . "\n";
                $ipxe .= "set parc " . $p['samaccountname'] . "\n";
                $ipxe .= "goto suite\n";
            }
            $ipxe .= ":suite\n";
            $ipxe .= "params\n";
            $ipxe .= "param uuid " . $uuid . "\n";
            $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
            $ipxe .= "param mac " . $mac . "\n";
            $ipxe .= "param parc \${parc}\n";
            $ipxe .= "chain --replace --autofree enleveparc.php##params\n";
        }
    }
}
$ipxe .= ":fin\n";
$ipxe .= "params\n";
$ipxe .= "param menu-default parcs\n";
$ipxe .= "param uuid " . $uuid . "\n";
$ipxe .= "param session_ipxe " . $session_ipxe . "\n";
$ipxe .= "param mac " . $mac . "\n";
$ipxe .= "chain --replace --autofree admin.php##params\n";
ipxe_out($config, $ipxe);
?>