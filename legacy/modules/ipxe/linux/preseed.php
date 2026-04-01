<?php
/*
 * génération des fichiers preseed pour debian
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require "ipxe_functions.inc.php";


$mode = $_POST['mode'] ?? $_GET['mode'] ?? "";
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$mask = $_POST['mask'] ?? $_GET['mask'] ?? $config['mask'];
$gateway = $_POST['gateway'] ?? $_GET['gateway'] ?? $config['gateway'];
$mac = $_POST['mac'] ?? $_GET['mac'];
$os = $_POST['os'] ?? $_GET['os'] ?? "debian";
$type = $_POST['type'] ?? $_GET['type'] ?? $config['environnement_bureau'] ?? "base";
$perso = $_POST['perso'] ?? $_GET['perso'] ?? false;
$params = [];
$preseed = "";

// définition de la locale par défaut
$config['linux_locale'] = $config['linux_locale'] ?? "fr_FR";
$config['linux_keyboard'] = $config['linux_keyboard'] ?? "fr(latin9)";

// Lire le fichier preseed générique et le compléter (sections 3, 5, 8 et 10)
if (auth_action($config, $mac, $uuid)) {
    $machine = get_action($config, $uuid);
    if (isset($machine['cn'])) {
        if ($type == "se4ad") {
            $file = file(__DIR__ . "/se4ad.cfg");
            if (preg_match("/^se4ad-(([0-9]{0,3})([0-9]{4}[a-zA-Z]))$/", $machine['cn'], $m)) {
                if (! empty($m[2])) {
                    $config['etab_ou'] = $m[1];
                } elseif (! empty($config['etab_ou'])) {
                    $config['etab_ou'] = substr($config['etab_ou'], 3) . $m[2];
                } else {
                    $config['etab_ou'] = $config['departement'] . $m[2];
                }
                $config['se4_priv_key'] = base64_encode(file_get_contents("/etc/sambaedu/id_rsa"));
            }
            $config['se4ad_etab_ip'] = slave_ip($config, $_SERVER['REMOTE_ADDR'], $type);
            $params = [
                'mask' => $mask,
                'gateway' => $gateway
            ];
            dns_add($config, $machine['cn'], $config['se4ad_etab_ip'], true);
            dns_add_ptr($config, $machine['cn'], $config['se4ad_etab_ip']);
        } elseif ($type == "se4fs") {
            if (preg_match("/^se4fs-(([0-9]{3})([0-9]{4}[a-z]))$/i", $machine['cn'], $m)) {
                if (! empty($m[2])) {
                    $config['etab_ou'] = $m[1];
                    $config['suffix'] = "-" . $m[3];
                } elseif (! empty($config['etab_ou'])) {
                    $config['etab_ou'] = substr($config['etab_ou'], 3) . $m[2];
                } else {
                    $config['etab_ou'] = $config['departement'] . $m[2];
                }
                $config['se4_priv_key'] = base64_encode(file_get_contents("/etc/sambaedu/id_rsa"));
            }
            // $config['se4fs_central_ip'] = $config['se4fs_ip'];
            $config['se4fs_name'] = $machine['cn'];
            $config['se4fs_ip'] = slave_ip($config, $_SERVER['REMOTE_ADDR'], $type);
            $params = [
                'mask' => $mask,
                'gateway' => $gateway,
                'action_ad' => "Installer se4fs etablissement"
            ];
            // uniquement pour les config multi-établissements
            // on demandera l'UAI de l'établissement lors de l'installation et son ip fixe
            // ajout dns, le faire au niveau du preseed pose parfois souci (blocage)
            dns_add($config, $machine['cn'], $config['se4fs_ip'], true);
            dns_add_ptr($config, $machine['cn'], $config['se4fs_ip']);
            $file = file(__DIR__ . "/se4fs.cfg");
        } elseif ($os == 'ubuntu') {
            $file = file(__DIR__ . "/ubuntu.cfg");
            if (@is_dual_boot($config, $machine)) {
                $file = array_merge($file, file(__DIR__ . "/double_boot.cfg"));
            } else {
                $file = array_merge($file, file(__DIR__ . "/simple_boot.cfg"));
                remove_dual_boot($config, $machine['cn']);
                set_os($config, $machine['cn'], "linux");
            }
        } else {
            $file = [];
            // gestion presence-absence du cache apt (section 3)
            if (! empty($config['apt_proxy'])) {
                $file = array_merge($file, file(__DIR__ . "/aptcache.cfg"));
            } else {
                if (! empty($config['server_proxy'])) {
                    $file = array_merge($file, file(__DIR__ . "/proxy.cfg"));
                }
                $file = array_merge($file, file(__DIR__ . "/nocache.cfg"));
            }
            if ($perso) {
                if ($type == "base") {
                    $file = array_merge($file, file(__DIR__ . "/debian_serv.cfg"));
                } elseif ($type == "nird") {
                    $file = array_merge($file, file(__DIR__ . "/debian_perso.cfg"));
                } elseif ($type == "primtux") {
                    $file = array_merge($file, file(__DIR__ . "/debian_perso.cfg"));
                } elseif ($type == "kiosk") {
                    $file = array_merge($file, file(__DIR__ . "/debian_kiosk.cfg"));
                } else {
                    $file = array_merge($file, file(__DIR__ . "/debian_perso.cfg"));
                }
            } else {
                $file = array_merge($file, file(__DIR__ . "/debian.cfg"));
                // choix de l'environnement de bureau gnome ou lxde (section 8)
                // gnome ou lxde
                switch ($type) {
                    case 'gnome':
                        $file = array_merge(file(__DIR__ . "/debian_gnome.cfg"), $file);
                        break;
                    case 'lxde':
                        $file = array_merge(file(__DIR__ . "/debian_lxde.cfg"), $file);
                        break;
                    case 'kde':
                        $file = array_merge(file(__DIR__ . "/debian_kde.cfg"), $file);
                        break;
                    case 'mate':
                        $file = array_merge(file(__DIR__ . "/debian_mate.cfg"), $file);
                        break;
                    case 'xfce':
                        $file = array_merge(file(__DIR__ . "/debian_xfce.cfg"), $file);
                        break;
                    case 'cinnamon':
                        $file = array_merge(file(__DIR__ . "/debian_cinnamon.cfg"), $file);
                        break;
                    case 'nextcloud':
                        $file = array_merge(file(__DIR__ . "/debian_nextcloud.cfg"), $file);
                        break;
                    default:
                        $file = array_merge(file(__DIR__ . "/debian_base.cfg"), $file);
                        break;
                }
                $file = array_merge($file, file(__DIR__ . "/sambaedu.cfg"));
                // lancement éventuellement d'une commande à la fin (section 10)
                // une commande → 1 ou pas de commande → 0
                if (! empty($config['commande_fin_preseed'])) {
                    $file = array_merge($file, file(__DIR__ . "/commande_fin.cfg"));
                }
            }
            // choix de simple ou du double boot (section 5)
            // simple_boot.cfg ou double_boot.cfg
            if (@is_dual_boot($config, $machine)) {
                $file = array_merge($file, file(__DIR__ . "/double_boot.cfg"));
            } else {
                $file = array_merge($file, file(__DIR__ . "/simple_boot.cfg"));
                remove_dual_boot($config, $machine['cn']);
                set_os($config, $machine['cn'], "linux");
                // on impose un dev si défini dans la conf
                if (! empty($config['linux_disk'])) {
                    $file[] = "d-i partman-auto/disk string " . $config['linux_disk'];
                }
            }
        }
        // initialisation des parametres présents dans sambaedu.conf
        if (empty($config['linux_interface'])) {
            $config['linux_interface'] = "auto";
        }
        if (empty($config['version_debian'])) {
            $config['version_debian'] = "trixie";
        }
        // $config['depot_type'] = "se4XP";
        write_param($config, $file);
        // parametres spécifiques au poste
        $params = array_merge(array(
            'hostname' => strtolower($machine['cn']),
            'uuid' => $uuid
        ), $params);
        write_param($params, $file);
        $preseed = "";
        foreach ($file as $ligne) {
            $preseed .= $ligne;
        }
        set_action($config, $uuid, [
            'type' => "install",
            'script' => "default",
            'role' => "linux",
            'etape' => "preseed"
        ]);
        $computer = get_action($config, $uuid);
        set_progress($computer['id'], "5%");
        set_statut($computer['id'], "installation Linux preseed");

        file_put_contents("/tmp/" . $machine['cn'] . ".preseed", $preseed);
    }
}
header("Content-type: text/plain");
echo $preseed;
