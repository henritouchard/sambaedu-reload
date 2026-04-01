<?php
/*
 * page appellée en premier au boot iPXE
 * adresse mac en argument
 *
 * si la machine est enregistrée et qu'une action est programmée, lance l'action
 * on peut durant 1 s choisir autre chose.
 *
 * si la machine n'est pas référencée, on attend 10s
 *
 * @TODO ajouter une detection de l'user-agent ?
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require "partages.inc.php";
require "ipxe_functions.inc.php";
require "logs.inc.php";

$ip = $_SERVER["REMOTE_ADDR"];
$mac = $_POST['mac'] ?? $_GET['mac'] ?? "";
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$product = $_POST['product'] ?? $_GET['product'] ?? "";
if (empty($mac) || empty($uuid)) {
    $ipxe = "#!ipxe\n";
    $ipxe .= "params\n";
    $ipxe .= "param mac \${net0/mac}\n";
    $ipxe .= "param uuid \${uuid}\n";
    $ipxe .= "param product \${product}\n";
    $ipxe .= "chain --replace --autofree boot.php##params\n || sleep 10\n";
    ipxe_out($config, $ipxe);
    exit();
}
if (empty($product)) {
    $uuids = explode("-", $uuid);
    $dm = hexdec(implode("", explode(":", $mac)));
    $finx = dechex($dm);
    $uuid = $uuids[0] . "-" . $uuids[1] . "-" . $uuids[2] . "-" . $uuids[3] . "-" . $finx;
}
$machine = get_action($config, $uuid, $mac);
if (count($machine) > 0) {
    $id = $machine['id'] ?? "";
    $menu_timeout = '5000';
    if (isset($machine['action'][$uuid]['script']) && ! empty($machine['action'][$uuid]['script'])) {
        $menu_action = "action";
        $type = $machine['action']['type'];
        $script = $machine['action'][$uuid]['script'];
        $role = $machine['action'][$uuid]['role'] ?? "";
        $ret = $machine['action'][$uuid]['ret'] ?? - 1;
    } else {
        $script = "default";
        $menu_action = "default";
    }
} else {
    $script = "default";
    $menu_timeout = '10000';
    $menu_action = "default";
}
if (empty($machine['cn'])) {
    $name = $ip;
} else {
    $name = $machine['cn'];
}
// if ($menu_action != "default") {
// check_computer($config, $name);
// }
log_connexion($config, "", $name, "ipxe", "startup");
$url = "http://{$_SERVER["SERVER_ADDR"]}:{$_SERVER["SERVER_PORT"]}/ipxe/";

$ipxe = "#!ipxe\n";
// set resolution and background
$ipxe .= "console --x 1024 --y 768 --picture {$url}png/ipxe-se4.png\n";

$ipxe .= ":menu\n";
$ipxe .= "menu Preboot eXecution Environment pour mac : $mac ($uuid)\n";
$ipxe .= "set menu-default " . $menu_action . "\n";
$ipxe .= "set menu-timeout $menu_timeout\n";

$ipxe .= title("Choisir une action :");
$ipxe .= "item --key 1 login (1) Acces au menu d'administration\n";
$ipxe .= "item --key 2 action (2) Action type : \"" . @$type . "\", script : \"" . @$script . "\", role : \"" . @$role . "\", machine : \"" . @$name . "\" etape : \"" . @$ret . "\"\n";
system("dpkg -l sambaedu-ltsp | grep -q \"ii\"", $ret);
if ($ret != 0) {
    $ipxe .= "item --key 3 default (3) Quitter iPXE et booter sur le BIOS\n";
} else {
    $ipxe .= "item --key 3 ltsp (3) Booter un client reseau LTSP\n";
    $ipxe .= "item --key 4 default (4) Quitter iPXE et booter sur le BIOS\n";
}
$ipxe .= "choose --default \${menu-default} --timeout \${menu-timeout} selected && goto \${selected} || exit 0\n";

$ipxe .= ":login\n";
$ipxe .= "login\n";
$ipxe .= "isset \${username} && isset \${password} || goto menu\n";
$ipxe .= "params\n";
$ipxe .= "param mac \${net0/mac}\n";
$ipxe .= "param uuid $uuid\n";
$ipxe .= "param username \${username}\n";
$ipxe .= "param password \${password:base64}\n";
$ipxe .= "param \${platform}\n";
$ipxe .= "chain --replace --autofree {$url}admin.php##params\n || sleep 10\n";

$ipxe .= ":ltsp\n";
$ipxe .= "params\n";
$ipxe .= "param mac \${net0/mac}\n";
$ipxe .= "param uuid $uuid\n";
$ipxe .= "param \${platform}\n";
$ipxe .= "chain --replace --autofree " . $url . "ltsp.php##params\n || sleep 10\n";

$ipxe .= ":action\n";
$ipxe .= "params\n";
$ipxe .= "param mac \${net0/mac}\n";
$ipxe .= "param uuid $uuid\n";
$ipxe .= "param session_ipxe " . $uuid . "\n";
$ipxe .= "param action " . @$script . "\n";
$ipxe .= "param role " . @$role . "\n";
$ipxe .= "param name " . @$name . "\n";
$ipxe .= "param \${platform}\n";
$ipxe .= "chain --replace --autofree " . $url . "action.php##params\n || sleep 10\n";

$ipxe .= ":default\n";
$ipxe .= "echo Demarrage sur les disques locaux...\n";
$ipxe .= "echo Boot Disque 1...\n";
$ipxe .= boot_disk();
ipxe_out($config, $ipxe);
// /////////// vérification des acls sur os ////////////////////
$acls = array(
    "user::rwx",
    "user:www-admin:rwx",
    "user:admin:rwx",
    "user:www-data:r-x",
    "group::r-x",
    "group:domain\\040admins:rwx",
    "group:domain\\040computers:r-x",
    "mask::rwx",
    "other::---",
    "default:user::rwx",
    "default:user:www-admin:rwx",
    "default:user:admin:rwx",
    "default:user:www-data:r-x",
    "default:group::r-x",
    "default:group:domain\\040admins:rwx",
    "default:group:domain\\040computers:r-x",
    "default:mask::rwx",
    "default:other::---"
);
//if (! check_acls("/var/sambaedu/unattended/install", $acls))
//    set_acls("/var/sambaedu/unattended/install", $acls, false);

//if (! check_acls("/var/sambaedu/unattended/install/os", $acls))
//    set_acls("/var/sambaedu/unattended/install/os", $acls);

?>