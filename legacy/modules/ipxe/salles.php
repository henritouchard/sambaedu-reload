<?php
/*
 * Met la machine dans le parcs
 *
 * @TODO
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
$mac = $_POST['mac'] ?? $_GET['mac'] ?? "";

if (! empty($uuid)) {
    $machine = get_action($config, $uuid);
    if (count($machine) > 0) {
        $salles = search_parcs($config, "*", "salle");
        $parcs = list_parcs($config, $machine['cn'], "salle");
        // $salles = array_diff($salles, $parcs);
        if (isset($_POST['parc'])) {
            $parc = $_POST['parc'];
            if (in_array($parc, array_column($parcs, "name"))) {
                $ipxe .= "echo La machine est deja dans la salle " . $parc . "\n";
                $ipxe .= "sleep 3\n";
                $ipxe .= "chain --replace --autofree salles.php##params\n";
            } else {
                $res = move_member_parc($config, $parc, $machine['cn']);
                if ($res) {
                    $ipxe .= "echo La machine a ete ajoutee a la salle " . $parc . "\n";
                    $ipxe .= "sleep 3\n";
                } else {
                    $ipxe .= "echo ERREUR La machine n'a pas ete ajoutee a la salle " . $parc . "\n";
                    $ipxe .= "sleep 3\n";
                }
            }
        } else {

            $ipxe .= "menu Enregistrement de la salle pour " . $machine['cn'] . "\n";
            $ipxe .= "set menu-default Computers\n";
            $ipxe .= "set menu-timeout $menu_timeout\n";
            foreach ($salles as $salle) {
                if (in_array($salle['name'], array_column($parcs, "name"))) {
                    $ipxe .= "item fin ** deja dans " . $salle['name'] . " **\n";
                } else {
                    $ipxe .= "item  " . $salle['name'] . " " . $salle['name'] . "\n";
                }
            }
            $ipxe .= "item  fin Retour au menu principal\n";
            $ipxe .= "choose --default \${menu-default} --timeout \${menu-timeout} selected && goto \${selected} || exit 0\n";
            foreach ($salles as $salle) {
                $ipxe .= ":" . $salle['name'] . "\n";
                $ipxe .= "set parc " . $salle['name'] . "\n";
                $ipxe .= "goto suite\n";
            }
            $ipxe .= ":suite\n";
            $ipxe .= "params\n";
            $ipxe .= "param uuid " . $uuid . "\n";
            $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
            $ipxe .= "param mac " . $mac . "\n";
            $ipxe .= "param parc \${parc}\n";
            $ipxe .= "chain --replace --autofree salles.php##params\n";
        }
    }
}
$ipxe .= ":fin\n";
$ipxe .= "params\n";
$ipxe .= "param menu-default salles\n";
$ipxe .= "param uuid " . $uuid . "\n";
$ipxe .= "param session_ipxe " . $session_ipxe . "\n";
$ipxe .= "param mac " . $mac . "\n";
$ipxe .= "chain --replace --autofree " . $url . "admin.php##params\n";

ipxe_out($config, $ipxe);
?>