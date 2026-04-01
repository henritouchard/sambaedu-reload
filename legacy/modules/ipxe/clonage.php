<?php
/*
 * Definition manuelle d'un clonage
 *
 * @TODO
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once ('ipxe_functions.inc.php');

$ipxe = "#!ipxe\n";
$menu_timeout = '10000';
$ipxe .= "console --x 1024 --y 768 --picture png/ipxe-se4.png\n";
$index = $_POST['index'] ?? "";
$mode = $_POST['mode'] ?? "";
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$session_ipxe = $_POST['session_ipxe'] ?? "NOTHING";
$mac = $_POST['mac'] ?? $_GET['mac'] ?? "";

$actions = fetch_action();
$liste = array();
foreach ($actions as $id => $action) {
    if ($action['type'] == 'clonage') {
        foreach ($action as $membre) {
            if (isset($membre['role'])) {
                if ($membre['role'] == 'modele') {
                    $liste[] = $id;
                }
            }
        }
    }
}

if (auth_action($config, $mac, $session_ipxe)) {
    $machine = get_action($config, $mac);
    if (count($machine) > 0) {
        if (! empty($liste[$index]) || ! empty($mode)) {
            if ($mode == "modele") {
                $script = "default";
            } else {
                $script = "rescuecd";
            }
            @set_action($config, $uuid, [
                'type' => "clonage",
                'id' => $liste[$index],
                'etape' => "ipxe",
                'role' => $mode,
                'script' => $script
            ]);
            $ipxe .= "params\n";
            $ipxe .= "param uuid " . $uuid . "\n";
            $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
            $ipxe .= "param mac " . $mac . "\n";
            $ipxe .= "param action " . $script . "\n";
            $ipxe .= "param mode " . $mode . "\n";
            $ipxe .= "chain --replace --autofree " . $script_url . "/action.php##params\n || sleep 10\n";
            ipxe_out($config, $ipxe);
            exit();
        } else {
            // if (count($liste) > 0) {
            $ipxe .= "params\n";
            $ipxe .= "param uuid " . $uuid . "\n";
            $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
            $ipxe .= "param mac " . $mac . "\n";

            $ipxe .= ":menu\n";
            $ipxe .= "menu Clonage : choix d'une action : \n";

            $ipxe .= title("Nouveau");
            $ipxe .= "item --key m modele  (m) Nouveau clonage (ce poste sera le modele)\n";
            $ipxe .= title("Choix d'un clonage programme");
            if (count($liste) > 0) {
                $i = 0;
                foreach ($liste as $id) {
                    $ipxe .= "item " . $i ++ . "   $id\n";
                }
            }
            $ipxe .= "item --key x exit   (x) Quitter le menu pour demarrer le poste normalement\n";

            $ipxe .= "choose --default \${menu-default} --timeout \${menu-timeout} selected && goto \${selected} || exit 0\n";
            $i = 0;
            foreach ($liste as $id) {
                $i ++;
                $ipxe .= ":$i\n";
                $ipxe .= "param index $i\n";
                $ipxe .= "param mode clone\n";
                $ipxe .= "goto end\n";
            }
            $ipxe .= ":modele\n";
            $ipxe .= "param mode modele\n";
            $ipxe .= "goto end\n";

            $ipxe .= ":end\n";
            $ipxe .= "chain --replace --autofree " . $script_url . "/clonage.php##params\n || sleep 10\n";
            ipxe_out($config, $ipxe);
            exit();
            // }
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