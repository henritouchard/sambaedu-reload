<?php
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once('ipxe_functions.inc.php');
require_once('ent.inc.php');

header("Content-type: text/plain");
$ipxe = "#!ipxe\n";
$mac = $_POST['mac'] ?? "none";
$url = "http://{$_SERVER["SERVER_ADDR"]}:{$_SERVER["SERVER_PORT"]}/ipxe/";
$header = "menu Preboot eXecution Environment\n";
$menu_timeout = 30000;
$menu_default = $_POST['menu-default'] ?? "installation-windows";
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$platform = $_POST['platform'] ?? "legacy";
$session_ipxe = $_POST['session_ipxe'] ?? "NOTHING";
if (get_action($config, $uuid)['action']['type'] == "byod") {
    $admin = false;
    $byod = true;
} else {
    $admin = true;
    $byod = false;
}
if ($session_ipxe != $uuid or $session_ipxe == "NOTHING") {
    if (! isset($_POST['username']) || ! isset($_POST['password'])) {
        $ipxe .= "params\n";
        $ipxe .= "param mac " . $mac . "\n";
        $ipxe .= "param uuid " . $uuid . "\n";
        $ipxe .= "param platform " . $platform . "\n";
        $ipxe .= "chain --replace --autofree " . $url . "boot.php##params\n";
        ipxe_out($config, $ipxe);
        exit();
    } else {
        $username = $_POST['username'];
        $password = base64_decode($_POST['password']);
        $res = login_action($config, $uuid, q2a($username, $platform), q2a($password, $platform));

        if (! $res) {
            $ipxe .= "echo Authentication failed! && sleep 2\n";
            $ipxe .= "params\n";
            $ipxe .= "param mac " . $mac . "\n";
            $ipxe .= "param uuid " . $uuid . "\n";
            $ipxe .= "param platform " . $platform . "\n";
            $ipxe .= "chain --replace --autofree " . $url . "boot.php##params\n";
            ipxe_out($config, $ipxe);
            exit();
        } else {
            $session_ipxe = $uuid;
            $admin = have_right($config, SE_COMPUTER_INSTALL, q2a($username, $platform));
        }
    }
}
// check_system_accounts($config); // désactivé : gestion des comptes AD système, non pertinent dans le contexte shimmé
$machine = search_machine($config, $uuid);

if ($admin) {
    if (count($machine) == 0) {
        $menu_default = "set-name";
    }
} else {
    $machine = get_action($config, $uuid);
}
$name = $machine['cn'] ?? "";
$ip = $machine['iphostnumber'] ?? $_SERVER['REMOTE_ADDR'];
$state = $machine['dhcp_state'] ?? "dhcp";
$ipxe .= "params\n";
$ipxe .= "param uuid " . $uuid . "\n";
$ipxe .= "param session_ipxe " . $session_ipxe . "\n";
$ipxe .= "param mac " . $mac . "\n";
$ipxe .= "param name " . $name . "\n";
$ipxe .= "param platform " . $platform . "\n";
// set resolution and background
$ipxe .= "console --x 1024 --y 768 --picture png/ipxe-se4.png\n";
$ipxe .= ":menu\n";
$ipxe .= "menu Preboot eXecution Environment pour mac : $mac ($uuid)\n";
$ipxe .= "set menu-default $menu_default\n";
$ipxe .= "set menu-timeout $menu_timeout\n";

$ipxe .= title("Menu");
$ipxe .= "item --key x exit   (x) Quitter le menu pour demarrer le poste normalement\n";

$ipxe .= title("Enregistrement du poste");
if ($admin) {
    if (count($machine) > 0 || !empty($name)) {
        $ipxe .= "item --key n set-name  (n) Renommer le poste : $name\n";
        if (@is_dual_boot($config, $machine)) {
            $ipxe .= "item --key n single (n) Passer en simple boot \n";
        } else {
            $ipxe .= "item --key d double (d) Passer en double boot \n";
        }
    } else {
        $ipxe .= "item --key n set-name  (n) Nommer le poste\n";
    }
    $ipxe .= "item --key a salle (a) Ajouter a une salle\n";
    // $ipxe .= "item --key d dhcp (d) Reserver une IP : $ip : " . $state . "\n";
    $ipxe .= "item --key p parcs (p) Ajouter a un parc\n";
    $ipxe .= "item --key e enleveparc (e) Enlever d'un parc\n";
    $ipxe .= title("Maintenance du poste");
    $ipxe .= "item --key m maintenance  (m) Outils de maintenance (syrescuecd,clonezilla,etc...)\n";
    $ipxe .= title("Clonage");
    $ipxe .= "item --key c clonage  (c) Clonage en direct des postes \n";
    $ipxe .= title("Installation");
    $ipxe .= "item --key i installation-windows  (i) Installation automatique de Windows\n";
    $ipxe .= "item --key l installation-linux  (l) Installation de Linux Debian et Ubuntu\n";
} elseif (count($machine) ==  0 || empty($name) || $name == $ip || $byod) {
    $ipxe .= "item --key n set-byod (n) Nommer le poste\n";
    $ipxe .= title("Installation");
    $ipxe .= "item --key l installation-linux  (l) Installation de Linux Debian et Ubuntu\n";
} else {
    $ipxe .= title("vous n'avez pas le droit d'installer !");
}
$ipxe .= title("Autres options");
$ipxe .= "item --key s shell  (s) iPXE shell\n";

$ipxe .= "choose --default \${menu-default} --timeout \${menu-timeout} selected && goto \${selected} || exit 0\n";

$ipxe .= ":shell\n";
$ipxe .= "echo iPXE shell...\n";
$ipxe .= "shell\n";

$ipxe .= ":exit\n";
$ipxe .= boot_disk();

$ipxe .= ":maintenance\n";
$ipxe .= "chain --replace --autofree  " . $url . "maintenance.php##params\n";

$ipxe .= ":set-name\n";
$ipxe .= "chain --replace --autofree  " . $url . "enregistrement.php##params\n";

$ipxe .= ":set-byod\n";
$ipxe .= "chain --replace --autofree  " . $url . "enregistrement_byod.php##params\n";

$ipxe .= ":salle\n";
$ipxe .= "chain --replace --autofree  " . $url . "salles.php##params\n";

$ipxe .= ":parcs\n";
$ipxe .= "chain --replace --autofree  " . $url . "parcs.php##params\n";

$ipxe .= ":enleveparc\n";
$ipxe .= "chain --replace --autofree  " . $url . "enleveparc.php##params\n";

$ipxe .= ":single\n";
$ipxe .= "param double 0\n";
$ipxe .= "chain --replace --autofree  " . $url . "double.php##params\n";

$ipxe .= ":double\n";
$ipxe .= "param double 1\n";
$ipxe .= "chain --replace --autofree  " . $url . "double.php##params\n";

$ipxe .= ":dhcp\n";
$ipxe .= "chain --replace --autofree  " . $url . "reservation.php##params\n";

$ipxe .= ":clonage\n";
$ipxe .= "chain --replace --autofree  " . $url . "clonage.php##params\n";

$ipxe .= ":installation-windows\n";
$ipxe .= "chain --replace --autofree " . $url . "installation-windows.php##params\n";

$ipxe .= ":installation-linux\n";
$ipxe .= "chain --replace --autofree " . $url . "installation-linux.php##params\n";

$ipxe .= ":back\n";
$ipxe .= "chain --replace --autofree " . $url . "boot.php##params\n";
ipxe_out($config, $ipxe);
