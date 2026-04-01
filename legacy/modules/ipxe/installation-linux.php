<?php
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once('ipxe_functions.inc.php');

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

if ($machine['action']['type'] != "byod") {
    $ipxe .= "console --x 1024 --y 768 --picture png/linux2.png\n";
    $ipxe .= ":menu\n";

    $ipxe .= "menu installation clients-linux pour (nom : $name ip : ${ip})\n";
    $ipxe .= "set menu-default deb_gnome\n";
    $ipxe .= "set menu-timeout $menu_timeout\n";

    $ipxe .= title("DEBIAN " . @$config['version_debian'] . " SIMPLE BOOT");
    $ipxe .= "item  deb_base Installation de Debian automatisee (Base)\n";
    $ipxe .= "item  deb_lxde Installation de Debian automatisee (LXDE)\n";
    $ipxe .= "item  deb_gnome Installation de Debian automatisee (Gnome)\n";
    $ipxe .= "item  deb_kde  Installation de Debian automatisée (KDE)\n";
    $ipxe .= "item  deb_mate  Installation de Debian automatisee (Mate)\n";
    $ipxe .= "item  deb_xfce  Installation de Debian automatisee (XFCE)\n";
    $ipxe .= "item  deb_cinnamon  Installation de Debian automatisee (Cinnamon)\n";
    
    $ipxe .= title("DEBIAN " . @$config['version_debian'] . " Personelle");
    $ipxe .= "item  deb_gnome_perso  Installation hors domaine pour pc perso (Gnome)\n";
 
    $ipxe .= title("Distribution NIRD");
    $ipxe .= "item  nird  Installation hors domaine pour pc perso (NIRD)\n";

    $ipxe .= title("Distribution PRIMTUX");
    $ipxe .= "item  primtux  Installation hors domaine pour pc perso (PRIMTUX)\n";

    $ipxe .= title("UBUNTU FOCAL 20.04");
    $ipxe .= "item  ubuntu64  Installation hors domaine pour pc perso\n";

    $ipxe .= title("DEBIAN " . @$config['version_debian'] . " Serveurs");
    $m = [];
    if (preg_match("/^se4fs-(([0-9]{0,3})([0-9]{4}[a-zA-Z]))$/", $name, $m)) {
        $ipxe .= "item  se4fs Installation d'un SE4FS local d'etablissement " . $m[1] . " " . etab_to_name($config, $m[1]) . "\n";
    } else {
        $ipxe .= "item  se4fs Installation d'un SE4FS local\n";
    }
    $m = [];
    if (preg_match("/^se4ad-(([0-9]{0,3})([0-9]{4}[a-zA-Z]))$/", $name, $m)) {
        $ipxe .= "item  se4ad Installation d'un SE4AD local d'etablissement " . $m[1] . " " . etab_to_name($config, $m[1]) . "\n";
    } else {
        $ipxe .= "item  se4ad Installation d'un SE4AD secondaire local\n";
    }

    $ipxe .= "item  deb_serv Installation de Debian automatisee (Serveur de base hors domaine)\n";
    $ipxe .= "item  deb_nextcloud Installation d'un serveur NextCloud\n";
    $ipxe .= "item  deb_kiosk Installation afficheur pour affichage dynamique (hors domaine)\n";
} elseif ($action['type'] == "byod") {
    $ipxe .= "console --x 1024 --y 768 --picture png/nird.png\n";
    $ipxe .= ":menu\n";

    $ipxe .= "menu       BYOM      : installation machine perso Linux pour (nom : $name\n";
    $ipxe .= "set menu-default deb_gnome_perso\n";
    $ipxe .= "set menu-timeout $menu_timeout\n";

    $ipxe .= title("DEBIAN " . @$config['debian_version'] . " SIMPLE BOOT");
    $ipxe .= "item  deb_gnome_perso  Installation hors domaine pour pc perso (NIRD)\n";

    $ipxe .= title("Distribution NIRD");
    $ipxe .= "item  nird  Installation hors domaine pour pc perso (NIRD)\n";

    $ipxe .= title("Distribution PRIMTUX");
    $ipxe .= "item  primtux  Installation hors domaine pour pc perso (PRIMTUX)\n";

    $ipxe .= title("UBUNTU ");
    $ipxe .= "item  ubuntu64  Installation hors domaine pour pc perso\n";
}
$ipxe .= title("Autres options");
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

$ipxe .= ":retour\n";
$ipxe .= "echo Retour au menu précédent ...\n";
$ipxe .= "chain --replace admin.php##params\n";

$ipxe .= ":deb_base\n";
$ipxe .= "param  action deb_base\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":deb_lxde\n";
$ipxe .= "param  action deb_lxde\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":deb_gnome\n";
$ipxe .= "param  action deb_gnome\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":deb_kde\n";
$ipxe .= "param  action deb_kde\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":deb_mate\n";
$ipxe .= "param  action deb_mate\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":deb_xfce\n";
$ipxe .= "param  action deb_xfce\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":deb_cinnamon\n";
$ipxe .= "param  action deb_cinnamon\n";
$ipxe .= "chain --replace --autofree action.php##params\n";


$ipxe .= ":nird\n";
$ipxe .= "param  action nird\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":primtux\n";
$ipxe .= "param  action primtux\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

/*
 *
 * $ipxe .= ":ubuntu32\n";
 * $ipxe .= "param action ubuntu32\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":ubuntu64\n";
 * $ipxe .= "param action ubuntu64\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":ubuntu32_dboot\n";
 * $ipxe .= "param action ubuntu32_dboot\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 */
$ipxe .= ":ubuntu64\n";
$ipxe .= "param  action ubuntu64\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":se4ad\n";
$ipxe .= "param  action se4ad\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":se4fs\n";
$ipxe .= "param  action se4fs\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":deb_serv\n";
$ipxe .= "param  action deb_serv\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":deb_nextcloud\n";
$ipxe .= "param  action deb_nextcloud\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

$ipxe .= ":deb_kiosk\n";
$ipxe .= "param  action deb_kiosk\n";
$ipxe .= "chain --replace --autofree action.php##params\n";

/*
 * $ipxe .= ":lubuntu32\n";
 * $ipxe .= "param action lubuntu32\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":lubuntu64\n";
 * $ipxe .= "param action lubuntu64\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":lubuntu32_dboot\n";
 * $ipxe .= "param action lubuntu32_dboot\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":lubuntu64_dboot\n";
 * $ipxe .= "param action lubuntu64_dboot\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":xubuntu32\n";
 * $ipxe .= "param action xubuntu32\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":xubuntu64\n";
 * $ipxe .= "param action xubuntu64\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":xubuntu32_dboot\n";
 * $ipxe .= "param action xubuntu32_dboot\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":xubuntu64_dboot\n";
 * $ipxe .= "param action xubuntu64_dboot\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":mubuntu32\n";
 * $ipxe .= "param action mubuntu32\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":mubuntu64\n";
 * $ipxe .= "param action mubuntu64\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":mubuntu32_dboot\n";
 * $ipxe .= "param action mubuntu32_dboot\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 *
 * $ipxe .= ":mubuntu64_dboot\n";
 * $ipxe .= "param action mubuntu64_dboot\n";
 * $ipxe .= "chain --replace --autofree action.php##params\n";
 */
ipxe_out($config, $ipxe);
