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
$name = $machine['cn'] ?? "";
$ip = $machine['iphostnumber'] ?? "";

$ipxe .= "params\n";
$ipxe .= "param uuid " . $uuid . "\n";
$ipxe .= "param session_ipxe " . $session_ipxe . "\n";
$ipxe .= "param mac " . $mac . "\n";

$ipxe .= "console --x 1024 --y 768 --picture png/sysrescuecd.png\n";
$ipxe .= ":menu\n";
$ipxe .= "menu maintenance pour \${ip}\n";
$ipxe .= "set menu-default exit\n";
$ipxe .= "set menu-timeout $menu_timeout\n";

$ipxe .= title("Sysrescuecd");
$ipxe .= "item  --key c rescuecd (c) Utilisation de sysrescuecd 6 \n";
$ipxe .= title("Autres options");
$ipxe .= "item  --key w winpe (w) reparation Windows\n";
$ipxe .= "item clonezilla Distribution Linux live Clonezilla \n";

$ipxe .= "item --key s shell  (s) iPXE shell\n";
$ipxe .= "item --key r retour (r) Retour au menu precedent\n";
$ipxe .= "item --key x exit (x) Boot sur disque dur\n";
$ipxe .= "choose --default \${menu-default} --timeout \${menu-timeout} selected && goto \${selected} || exit 0\n";

$ipxe .= ":shell\n";
$ipxe .= "echo iPXE shell...\n";
$ipxe .= "shell\n";

$ipxe .= ":exit\n";
$ipxe .= "echo Booting harddisk ...\n";
$ipxe .= boot_disk();

$ipxe .= ":rescuecd\n";
$ipxe .= "param  action rescuecd\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":winpe\n";
$ipxe .= "param  action winpe\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":clonezilla\n";
$ipxe .= "param  action clonezilla_live\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":retour\n";
$ipxe .= "chain --replace --autofree admin.php##params\n";
ipxe_out($config, $ipxe);
?>