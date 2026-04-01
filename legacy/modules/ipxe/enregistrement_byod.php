<?php
/*
 * choix du nom de la machine pour le byom
 * on n'enregistre rien dans l'AD
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once('ipxe_functions.inc.php');

$ipxe = "#!ipxe\n";
$menu_timeout = '10000';
$ipxe .= "console --x 1024 --y 768 --picture png/nird.png\n";
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$session_ipxe = $_POST['session_ipxe'] ?? "NOTHING";
$mac = $_POST['mac'] ?? $_GET['mac'] ?? "none";
$name = $_POST['name'] ?? "";
$platform = $_POST['platform'] ?? "legacy";
$machine = search_machine($config, $uuid);

if (auth_action($config, $mac, $session_ipxe) && ! isset($machine['cn'])) {
    // nouvelle machine
    $registered_name = "";
    if (! empty($_POST['new_name'])) {
        $new_name = $_POST['new_name'];
        if (count(search_machine($config, $new_name)) == 0) {
            // on crée une machine avec l'uuid, mais on ne définit pas d'ip réservée à ce stade.
            set_action($config, $uuid, [
                'type' => "byod",
                'etape' => "ipxe",
                'hostname' => $new_name
            ]);
            $ipxe .= "params\n";
            $ipxe .= "param uuid " . $uuid . "\n";
            $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
            $ipxe .= "param mac " . $mac . "\n";
            $ipxe .= "param name " . $new_name . "\n";
            $ipxe .= "param menu-default install-linux\n";
            $ipxe .= "param platform " . $platform . "\n";
            $ipxe .= "echo OK ! nom " . $new_name . " reserve pour " . $uuid . "\n";
            $ipxe .= "sleep 3\n";

            $ipxe .= "chain --replace --autofree install-linux.php##params\n";
        } else {
            $ipxe .= "params\n";
            $ipxe .= "param uuid " . $uuid . "\n";
            $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
            $ipxe .= "param mac " . $mac . "\n";
            $ipxe .= "param name " . $new_name . "\n";
            $ipxe .= "param platform " . $platform . "\n";
            $ipxe .= "param menu-default set-name\n";
            $ipxe .= "echo La machine est deja enregistree sous ce nom " . $new_name . "\n";
            $ipxe .= "sleep 3\n";
            $ipxe .= "chain --replace --autofree admin.php##params\n";
        }
    } else {

        $ipxe .= "echo BYOD : Enregistrement du nom pour nom : " . $name . ", uuid : " . $uuid . "\n";
        $ipxe .= "echo -n Entrez le nom de la machine : \n";
        $ipxe .= "set name " . $name . "\n";
        $ipxe .= "read name\n";
        $ipxe .= "params\n";
        $ipxe .= "param uuid " . $uuid . "\n";
        $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
        $ipxe .= "param mac " . $mac . "\n";
        $ipxe .= "param new_name \${name}\n";
        $ipxe .= "param platform " . $platform . "\n";
        $ipxe .= "chain --replace --autofree enregistrement_byod.php##params\n";
    }
} else {
    $ipxe .= "params\n";
    $ipxe .= "param mac '" . $mac . "'\n";
    $ipxe .= "param uuid " . $uuid . "\n";
    $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
    $ipxe .= "param platform " . $platform . "\n";
    $ipxe .= "echo ERREUR ! acces refuse\n";
    $ipxe .= "sleep 3\n";
    $ipxe .= "chain --replace --autofree boot.php##params\n";
}
ipxe_out($config, $ipxe);
