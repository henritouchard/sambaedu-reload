<?php
/*
 * choix du nom de la machine, et enregistrement dans l'annuaire AD
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once ('ipxe_functions.inc.php');

$ipxe = "#!ipxe\n";
$menu_timeout = '10000';
$ipxe .= "console --x 1024 --y 768 --picture png/ipxe-se4.png\n";
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$session_ipxe = $_POST['session_ipxe'] ?? "NOTHING";
$mac = $_POST['mac'] ?? $_GET['mac'] ?? "none";
$name = $_POST['name'] ?? "";
$platform = $_POST['platform'] ?? "legacy";

if (auth_action($config, $mac, $session_ipxe)) {
    $machine = search_machine($config, $uuid);
    if (isset($machine['cn'])) {
        // une machine existe déja avec cet uuid : on la renomme
        $registered_name = $machine['cn'];
        $move = true;
    } else {
        // nouvelle machine
        $registered_name = "";
        $move = false;
    }
    if (! empty($_POST['new_name'])) {
        $infos = explode(",", $_POST['new_name']);
        if (isset($infos[1]) && ! empty($dn = list_parcs($config, $infos[1], "salle")['dn'])) {
            $ou = preg_replace("/," . $config['ldap_base_dn'] . "/i", "", $ou);
        } else {
            $ou = $config['computers_rdn'];
        }
        if (preg_match("/se4fs|se4ad-([0-9]{7}[a-z])/i", q2a($infos[0], $platform))) {
            $new_name = q2a(substr($infos[0], 0, 15), $platform);
        } else {
            $new_name = add_hostname_suffix($config, q2a($infos[0], $platform));
        }
        if ($registered_name == $new_name) {
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
        } elseif ($move) {
            $res = move_ad($config, $registered_name, "cn=" . $new_name . "," . ldap_dn2oudn($machine['dn']), "computer");
        } elseif (count(search_machine($config, $new_name)) == 0) {
            // on crée une machine avec l'uuid, mais on ne définit pas d'ip réservée à ce stade.
            $res = create_machine($config, $new_name, $ou, "reservation iPXE");
            if ($res) {
                $machine = search_machine($config, $new_name);
                if (isset($machine['cn'])) {
                    $machine = register_machine_hardware($config, $machine, $uuid);
                } else {
                    $res = false;
                }
            }
        } else {
            $res = false;
        }
        if ($res) {
            $machine = get_action($config, $uuid);
            // changement adress mac si besoin ( adaptateurs réseau usb...)
            if (isset($machine['networkaddress'])) {
                if ($machine['networkaddress'] != $mac) {
                    $attrs = array(
                        'networkaddress' => $mac
                    );
                    $mode = "replace";
                    $res = modify_ad($config, $new_name, "machine", $attrs, $mode);
                }
            } else {
                $attrs = array(
                    'networkaddress' => $mac
                );
                $mode = "add";
                $res = modify_ad($config, $new_name, "machine", $attrs, $mode);
            }
            if (isset($infos[2])) {
                if (! empty($machine['location'])) {
                    if ($machine['location'] != $infos[2]) {
                        $attrs = array(
                            'location' => $infos[2]
                        );
                        $mode = "replace";
                        $res = modify_ad($config, $new_name, "machine", $attrs, $mode);
                    }
                } else {
                    $attrs = array(
                        'location' => $infos[2]
                    );
                    $mode = "add";
                    $res = modify_ad($config, $new_name, "machine", $attrs, $mode);
                }
            }
        }
        if ($res) {
            set_action($config, $uuid, [
                'type' => "default",
                'etape' => "ipxe"
            ]);
            $ipxe .= "params\n";
            $ipxe .= "param uuid " . $uuid . "\n";
            $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
            $ipxe .= "param mac " . $mac . "\n";
            $ipxe .= "param name " . $new_name . "\n";
            $ipxe .= "param menu-default dhcp\n";
            $ipxe .= "param platform " . $platform . "\n";
            $ipxe .= "echo OK ! nom " . $new_name . " reserve pour " . $uuid . "\n";
            $ipxe .= "sleep 3\n";

            $ipxe .= "chain --replace --autofree admin.php##params\n";
        } else {
            $ipxe .= "params\n";
            $ipxe .= "param uuid " . $uuid . "\n";
            $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
            $ipxe .= "param mac " . $mac . "\n";
            $ipxe .= "param name " . $new_name . "\n";
            $ipxe .= "param menu-default set-name\n";
            $ipxe .= "param platform " . $platform . "\n";
            $ipxe .= "echo ERREUR ! nom " . $new_name . " invalide pour " . $uuid . "\n";
            $ipxe .= "sleep 3\n";
            $ipxe .= "chain --replace --autofree admin.php##params\n";
        }
    } else {

        $ipxe .= "echo Enregistrement du nom pour nom : " . $name . ", uuid : " . $uuid . " (ne pas utiliser pour renommer sans reinstallation !)\n";
        $ipxe .= "echo -n Entrez le nom de la machine : \n";
        $ipxe .= "set name " . $name . "\n";
        $ipxe .= "read name\n";
        $ipxe .= "params\n";
        $ipxe .= "param uuid " . $uuid . "\n";
        $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
        $ipxe .= "param mac " . $mac . "\n";
        $ipxe .= "param new_name \${name}\n";
        $ipxe .= "param platform " . $platform . "\n";
        $ipxe .= "chain --replace --autofree enregistrement.php##params\n";
    }
} else {
    $ipxe .= "params\n";
    $ipxe .= "param mac '" . $mac . "'\n";
    $ipxe .= "param uuid " . $uuid . "\n";
    $ipxe .= "param session_ipxe " . $session_ipxe . "\n";
    $ipxe .= "param platform " . $platform . "\n";
    $ipxe .= "echo ERREUR ! acces refuse\n";
    $ipxe .= "sleep 3\n";
    $ipxe .= "chain --replace --autofree admin.php##params\n";
}
ipxe_out($config, $ipxe);
?>