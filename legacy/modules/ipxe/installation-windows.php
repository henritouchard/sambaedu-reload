<?php
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once ('ipxe_functions.inc.php');

$ipxe = "#!ipxe\n";
$menu_timeout = '10000';
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$session_ipxe = $_POST['session_ipxe'] ?? "NOTHING";
$mac = $_POST['mac'] ?? $_GET['mac'] ?? "";

$machine = get_action($config, $uuid);
$name = $machine['cn'] ?? "aleatoire";
$ip = $machine['iphostnumber'] ?? $_SERVER['REMOTE_ADDR'];
$version = $_POST['version'] ?? "Win10";
$ipxe .= "params\n";
$ipxe .= "param uuid " . $uuid . "\n";
$ipxe .= "param session_ipxe " . $session_ipxe . "\n";
$ipxe .= "param mac " . $mac . "\n";

$ipxe .= "console --x 800 --y 480 --picture png/windows10.png\n";
$ipxe .= ":menu\n";
$ipxe .= "menu installation clients Windows pour $mac\n";
if (! @is_dual_boot($config, $machine)) {
    $ipxe .= "set menu-default installw11\n";
} else {
    $ipxe .= "set menu-default Win10disk\n";
}
$ipxe .= "set menu-timeout $menu_timeout\n";

$ipxe .= title("Menu Windows");
$ipxe .= "item  installw10 Installation de Windows 10 (nom :  $name ) \n";
// $ipxe .= "item Win10u Installation W10 uefi (experimental!!!)\n";
$ipxe .= "item  Win10man  Installation W10 en mode debug des drivers\n";
$ipxe .= "item  Win10disk  Installation W10 avec choix du partitionnement (double boot)\n";
$ipxe .= "item  Win10perso  Installation W10 pour pc perso (hors domaine)\n";
// $ipxe .= "item Win10l2 boot W10 diskless(experimental!!!)\n";
$ipxe .= title("Menu Windows 11 (experimental)");
$ipxe .= "item  installw11 Installation de Windows 11 (nom :  $name ) \n";
if (is_dir("/var/sambaedu/unattended/install/os/Win11-old")) {
    $ipxe .= "item  installw11old Installation de Windows 11 version precedente (nom :  $name ) \n";
}
$ipxe .= "item  installw11disk Installation de Windows 11 avec choix partitionnement (nom :  $name ) \n";
$ipxe .= "item  Win11perso  Installation W11 pour pc perso (hors domaine)\n";
$ipxe .= title("Autres options");
$ipxe .= "item --key r retour (r) Retour au menu precedent\n";
$ipxe .= "item --key x exit (x) Boot sur disque dur\n";
$ipxe .= "choose --default \${menu-default} --timeout \${menu-timeout} selected && goto \${selected} || exit 0\n";

$ipxe .= ":shell\n";
$ipxe .= "echo iPXE shell...\n";
$ipxe .= "shell\n";

$ipxe .= ":exit\n";
$ipxe .= "echo Booting harddisk ...\n";
$ipxe .= boot_disk();

$ipxe .= ":installw10\n";
$ipxe .= "param action wimboot10\n";
$ipxe .= "param debug 0\n";
$ipxe .= "param version Win10\n";
$ipxe .= "chain --replace --autofree action.php##params\n";
$ipxe .= ":Win10perso\n";
$ipxe .= "param action wimboot10\n";
$ipxe .= "param version Win10\n";
$ipxe .= "param perso 1\n";
$ipxe .= "chain --replace --autofree action.php##params\n";
$ipxe .= ":Win10man\n";
$ipxe .= "param action  wimboot10\n";
$ipxe .= "param debug 1\n";
$ipxe .= "param version Win10\n";
$ipxe .= "chain --replace --autofree action.php##params\n";
$ipxe .= ":Win10disk\n";
$ipxe .= "param action  wimboot10\n";
$ipxe .= "param debug 0\n";
$ipxe .= "param version Win10\n";
$ipxe .= "param disk 1\n";
$ipxe .= "chain --replace --autofree action.php##params\n";
$ipxe .= ":Win10l2\n";
$ipxe .= "param action win10diskless\n";
$ipxe .= "chain --replace --autofree action.php##params\n";
$ipxe .= ":installw11\n";
$ipxe .= "param action wimboot11\n";
$ipxe .= "param version Win11\n";
$ipxe .= "param debug 0\n";
$ipxe .= "chain --replace --autofree action.php##params\n";
$ipxe .= ":installw11old\n";
$ipxe .= "param action wimboot11old\n";
$ipxe .= "param version Win11-old\n";
$ipxe .= "param debug 0\n";
$ipxe .= "chain --replace --autofree action.php##params\n";
$ipxe .= ":installw11disk\n";
$ipxe .= "param action wimboot11\n";
$ipxe .= "param version Win11\n";
$ipxe .= "param debug 0\n";
$ipxe .= "param disk 1\n";
$ipxe .= "chain --replace --autofree action.php##params\n";
$ipxe .= ":Win11perso\n";
$ipxe .= "param action wimboot11\n";
$ipxe .= "param version Win11\n";
$ipxe .= "param perso 1\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":retour\n";
$ipxe .= "chain --replace --autofree admin.php##params\n";
ipxe_out($config, $ipxe);
?>
