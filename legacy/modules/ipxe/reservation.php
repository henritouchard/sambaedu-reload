<?php
/*
 * reservation de l'ip de la machine, et enregistrement dans l'annuaire AD
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require 'ipxe_functions.inc.php';
require "dhcpd.inc.php";
require_once "fonc_parc.inc.php";

$ipxe = "#!ipxe\n";
$menu_timeout = '10000';
$ipxe .= "dhcp\n";
$ipxe .= "console --x 1024 --y 768 --picture png/ipxe-se4.png\n";

$ip = $_SERVER['REMOTE_ADDR'];
$name = $_POST['name'] ?? $_GET['name'] ?? "";
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$session_ipxe = $_POST['session_ipxe'] ?? $_GET['session_ipxe'] ?? "NOTHING";
$mac = $_POST['mac'] ?? $_GET['mac'] ?? "";
if (auth_action($config, $mac, $session_ipxe)) {
    $machine = search_machine($config, $uuid, true);
    if (isset($machine['dhcp_state'])) {
        if ($machine['dhcp_state'] != "reservation") {
            if (! isset($_POST['newip'])) {
                // $newip = get_free_ip($config, $ip);
                $ipxe .= "set newip " . $ip . "\n";
                $ipxe .= "echo -n Adresse ip actuelle : " . $ip . " , ip a reserver : \n";
                $ipxe .= "read newip\n";
                $ipxe .= "params\n";
                $ipxe .= "param uuid " . $uuid . "\n";
                $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
                $ipxe .= "param mac " . $mac . "\n";
                $ipxe .= "param name " . $machine['cn'] . "\n";
                $ipxe .= "param newip \${newip}\n";
                $ipxe .= "chain --replace --autofree " . $script_url . "/reservation.php##params\n";
            } else {
                $ip = $_POST['newip'];
                set_dhcp_reservation($config, $name, $ip);
                $ipxe .= "params\n";
                $ipxe .= "param uuid " . $uuid . "\n";
                $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
                $ipxe .= "param mac " . $mac . "\n";
                $ipxe .= "chain --replace --autofree " . $script_url . "/reservation.php##params\n";
            }
        } elseif ($ip == $machine['iphostnumber']) {
            $ipxe .= "echo IP reservee\n";
            $ipxe .= "sleep 3\n";
            $ipxe .= "params\n";
            $ipxe .= "param uuid " . $uuid . "\n";
            $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
            $ipxe .= "param mac " . $mac . "\n";
            $ipxe .= "param menu-default parcs\n";
            $ipxe .= "chain --replace --autofree admin.php##params\n";
        } else {
            // attente de la prise en compte de la reservation, jusquà 5 minutes...
            $ipxe .= "attente de la prise en compte de la reservation\n";
            $ipxe .= "patientez...\n";
            $ipxe .= "sleep 30\n";
            $ipxe .= "params\n";
            $ipxe .= "param uuid " . $uuid . "\n";
            $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
            $ipxe .= "param mac " . $mac . "\n";
            $ipxe .= "chain --replace --autofree " . $script_url . "/reservation.php##params\n";
        }
    }
} else {
    $ipxe .= "params\n";
    $ipxe .= "param mac " . $mac . "\n";
    $ipxe .= "param uuid " . $uuid . "\n";
    $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
    $ipxe .= "chain --replace --autofree admin.php##params\n";
}
ipxe_out($config, $ipxe);
?>