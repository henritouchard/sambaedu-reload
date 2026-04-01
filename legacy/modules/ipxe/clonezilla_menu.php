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
$mac = $_POST['mac'] ?? $_GET['mac'] ?? "";

$machine = get_action($config, $mac);
$name = $machine['cn'] ?? "";
$ip = $machine['iphostnumber'] ?? "";

$ipxe .= "params\n";
$ipxe .= "param uuid " . $uuid . "\n";
$ipxe .= "param mac " . $mac . "\n";

$ipxe .= "console --x 1024 --y 768 --picture png/clonezilla.png\n";
$ipxe .= ":menu\n";
$ipxe .= "menu maintenance pour ${ip}\n";
$ipxe .= "set menu-default exit\n";
$ipxe .= "set menu-timeout $menu_timeout\n";

$ipxe .= title("Clonezilla");
$ipxe .= "item  clonezilla_prevert Restauration images sur partimag \n";
$ipxe .= "item  live32 Utilisation de Clonezilla-livecd 32 bits \n";
$ipxe .= "item  live64 Utilisation de Clonezilla-livecd 64 bits \n";
$ipxe .= "item  sav_locale32 Sauvegarde locale (sda1 vers sda2) 32 bits \n";
$ipxe .= "item  sav_locale64 Sauvegarde locale (sda1 vers sda2) 64 bits \n";
$ipxe .= "item  rest_locale32 Restauration locale 32 bits (sda2 vers sda1) \n";
$ipxe .= "item  rest_locale64 Restauration locale 64 bits (sda2 vers sda1) \n";
$ipxe .= title("Autres options");
$ipxe .= "item --key s shell  (s) iPXE shell\n";
$ipxe .= "item --key r retour (r) Retour au menu precedent\n";
$ipxe .= "item --key x exit (x) Boot sur disque dur\n";
$ipxe .= "choose --default \${menu-default} --timeout \${menu-timeout} selected && goto \${selected} || exit 0\n";

$ipxe .= ":retour\n";
$ipxe .= "chain --replace --autofree admin.php##params\n";

$ipxe .= ":shell\n";
$ipxe .= "echo iPXE shell...\n";
$ipxe .= "shell\n";

$ipxe .= ":exit\n";
$ipxe .= "echo Booting harddisk ...\n";
$ipxe .= boot_disk();

$ipxe .= ":clonezilla_prevert\n";
$ipxe .= "param  action rest_prevert\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":live32\n";
$ipxe .= "param  action live32\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":live64\n";
$ipxe .= "param  action live64\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":sav_locale64\n";
$ipxe .= "param  action sav_locale64\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":sav_locale32\n";
$ipxe .= "param  action sav_local32\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":rest_locale32\n";
$ipxe .= "param  action rest_locale32\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":rest_locale64\n";
$ipxe .= "param  action rest_locale64\n";
$ipxe_out($config, $ipxe);

?>