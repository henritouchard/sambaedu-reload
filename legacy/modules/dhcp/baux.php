<?php

/**

 * Fonctions Gestion des baux du DHCP

 * @Projet LCS / SambaEdu

 * @Auteurs Equipe Sambaedu

 * @Note: 

 * @Licence Distribue sous la licence GPL

 */

/**
 *
 * @Repertoire: dhcp
 *
 * file: baux.php
 *
 */

// loading libs and init
include "config.inc.php";
require "ldap.inc.php";
require_once ("functions.inc.php");
require_once ("traitement_data.inc.php");
$config = get_config();
include 'admin_ui.inc.php';
$html = "";
admin_header_html($config, $html);
admin_topbar_html($config, $html);
admin_menu_html($config, $html);
$html .= header_authorize($config);
echo $html;
include_once "ldap.inc.php";
include "ihm.inc.php";
require_once "fonc_parc.inc.php";
require_once "fonc_outils.inc.php";
require_once "dhcpd.inc.php";

$action = $_POST['action'] ?? "";
if (have_right($config, SE_COMPUTER_ADMIN)) {


    // Supprime dhcpd.leases
    if ($action == "reinit") {
        exec("/usr/bin/sudo systemctl stop isc-dhcp-server.service", $out);
        exec("/usr/bin/sudo rm -f /var/lib/dhcp/dhcpd.leases", $out);
        exec("/usr/bin/sudo systemctl start isc-dhcp-server.service", $out);
        $action = "";
        $content = implode("<br>\n", $out);
    }

    @$content .= "<h1>Baux actifs</h1>";

    // Permet de vider le fichier dhcp.leases
    $content .= "<table><tr><td>";
    $content .= "<form name=\"lease_form\" method=post action=\"baux.php\">\n";
    $content .= "<input type='hidden' name='action' value='reinit'>\n";
    $content .= "<input type=\"submit\" name=\"button\" value=\"Réinitialiser\">\n";
    $content .= "</form>\n";
    $content .= "</td><td>";
    $content .= "<span title=\"Permet de purger les baux.A n'utiliser que lorsque des baux ne sont pas purgés.\" style=\"cursor:help;\"><IMG style=\"border: 0px solid ;\" src=\"../elements/images/help-info.gif \"></span>\n";
    $content .= "</td></tr></table>\n";

    // Prepare HTML code
    switch ($action) {
        case "":
        case 'index':
            $sup = 0;
            $parser = list_dhcp_leases($config);
            if ($parser != "") {
                $content .= form_dhcp_lease($config, $parser);
            } else {
                $content .= "Aucun bail actif pour le moment.";
            }
            break;

        case 'valid':
            $ip = $_POST['ip'] ?? array();
            $mac = $_POST['mac'] ?? array();
            $action_res = $_POST['action_res'] ?? array();
            $name = $_POST['name'] ?? array();
            $oldname = $_POST['name'] ?? array();
            $localadminname = $_POST['localadminname'] ?? "";
            $localadminpasswd = $_POST['localadminpasswd'] ?? "";
            $ou_rdn = $description = array();
            foreach ($ip as $keys => $value) {
                if ($action_res[$keys] == "reserver") {
                    $ou_rdn[$keys] = $config['computers_rdn'];
                    $description[$keys] = "reservation depuis l'interface Sambaedu";
                    if (create_machine($config, $name[$keys], $ou_rdn[$keys], $description[$keys])) {
                        $res = set_dhcp_reservation($config, $name[$keys], $ip[$keys], $mac[$keys]);
                        if ($res) {
                            // $content .= "reservation pour " . $name[$keys] . " " . $ip[$keys] . " " . $mac[$keys] . " OK<br>\n";
                            // relecture après ip fixee
                            $machine = search_machine($config, $name[$keys]);
                            $content .= "Réservation de l'adresse " . $machine['iphostnumber'] . " pour la machine <A href='../parcs/cherche_machine.php?mpenc=" . $name[$keys] . "'>" . $name[$keys] . "</A> OK<br>\n";
                            start_poste($config, "wol", $name[$keys]);
                        } else {
                            $content .= "Réservation pour " . $name[$keys] . " impossible<br>\n";
                        }

                        start_poste($config, "wol", $name[$keys]);
                    }
                } elseif ($action_res[$keys] == "imprimante") {
                    $ou_rdn[$keys] = $config['equipements_rdn'];
                    $description[$keys] = "Equipement réservé depuis l'interface Sambaedu";

                    if (create_machine($config, $name[$keys], $ou_rdn[$keys], $description[$keys])) {
                        if (set_dhcp_reservation($config, $name[$keys], $ip[$keys], $mac[$keys])) {
                            // relecture après ip fixee
                            $printer = search_machine($config, $name[$keys]);
                            $content .= "Réservation de l'adresse " . $printer['iphostnumber'] . " pour l'imprimante <A href='../printers/config_printer.php?newprinter=" . $printer['iphostnumber'] . "&name=" . $name[$keys] . "'>" . $name[$keys] . "</A> OK<br>\n";
                            start_poste($config, "wol", $name[$keys]);
                        } else {
                            $content .= "Réservation pour " . $name[$keys] . " impossible<br>\n";
                        }
                        start_poste($config, "wol", $name[$keys]);
                    }
                }
                /*
                 * elseif ($action_res[$keys]=="integrer") {
                 * // $content .= "<FONT color='red'>".add_reservation(/nfig, $ip[$keys],$mac[$keys],strtolower($name[$keys]),0)."</FONT>";
                 * if ($localadminpasswd[$keys] == "") { $localadminpasswd[$keys]="xxx"; }
                 * $content .= "<FONT color='red'>".integre_domaine($ip[$keys],$mac[$keys],strtolower($name[$keys]),$localadminname[$keys],$localadminpasswd[$keys])."</FONT>";
                 * }
                 * elseif ($action_res[$keys]=="renommer") {
                 * // $content .= add_reservation(/nfig, $ip[$keys],$mac[$keys],strtolower($name[$keys]),0);
                 * $content .= renomme_domaine($ip[$keys],strtolower($oldname[$keys]),strtolower($name[$keys]));
                 * }
                 */
            }
            $parser = list_dhcp_leases($config);
            if ($parser != "") {
                // $content .= dhcp_form_lease($parser);
                $content .= form_dhcp_lease($config, $parser);
            } else {
                $content .= "Aucun bail actif pour le moment.";
            }
            dhcpd_restart();
            break;

        default:
            // anti hacking
            $title = '';
            $content = '';
            return;
    }

    print "$content\n";
} else {
    print("Vous n'avez pas les droits nécessaires pour ouvrir cette page...");
}

// Footer
$html = "";
admin_footer_html($html);
echo $html;

?>