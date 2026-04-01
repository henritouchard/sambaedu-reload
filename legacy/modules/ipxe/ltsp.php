<?php
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once ('ipxe_functions.inc.php');

$ipxe = "#!ipxe\n";
$menu_timeout = '3000';
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$mac = $_POST['mac'] ?? $_GET['mac'] ?? "";
$role = $_POST['role'] ?? $_GET['role'] ?? "";

$machine = get_action($config, $uuid);
$name = $machine['cn'] ?? "";
$ip = $machine['iphostnumber'] ?? "";
$serveurs = list_members_parc($config, "serveurs_ltsp", false);

$ipxe .= "params\n";
$ipxe .= "param uuid " . $uuid . "\n";
$ipxe .= "param mac " . $mac . "\n";
$ipxe .= "param name " . $name . "\n";
$ipxe .= "param action ltsp\n";

$ipxe .= "console --x 1024 --y 768 --picture png/linux2.png\n";
$ipxe .= ":menu\n";
$ipxe .= "menu demarrage client ltsp : $name \n";
$ipxe .= "set menu-timeout $menu_timeout\n";

if (count($serveurs) < 1) {
    $ipxe .= title("echo pas de serveur LTSP configure, vous devez mettre au moins un client linux dans le parc serveurs_ltsp");
    $ipxe .= "set menu-default retour\n";
} else {
    foreach ($serveurs as $serveur) {
        if ((isset($machine['role']) && $machine['role'] == $serveur) || $role == $serveur) {
            $ipxe .= "set menu-default $serveur\n";
            $ipxe .= "set menu-timeout $menu_timeout\n";
        }
        $ipxe .= "item  $serveur $serveur serveur ltsp\n";
    }
}
$ipxe .= title("Autres options");
$ipxe .= "item --key r retour (r) Retour au menu precedent\n";
$ipxe .= "item --key x exit (x) Boot sur disque dur\n";
$ipxe .= "choose --default \${menu-default} --timeout \${menu-timeout} selected && goto \${selected} || exit 0\n";

$ipxe .= ":exit\n";
$ipxe .= "echo Booting harddisk ...\n";
$ipxe .= boot_disk();

$ipxe .= ":retour\n";
$ipxe .= "echo Retour au menu precedent ...\n";
$ipxe .= "chain --replace boot.php##params\n";

foreach ($serveurs as $serveur) {
    $ipxe .= ":$serveur\n";
    $ipxe .= "param  script $serveur\n";
    $ipxe .= "chain --replace --autofree action.php##params\n";
}
ipxe_out($config, $ipxe);
?>