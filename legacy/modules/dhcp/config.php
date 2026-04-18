<?php

/**

 * Configuration du serveur dhcp

 * @Projet SambaEdu

 * @auteurs  GrosQuicK   eric.mercier@crdp.ac-versailles.fr denis.bonnenfant

 * @note

 * @Licence  Distribue sous la licence GPL

 */

/**
 *
 * @Repertoire: dhcp
 *
 * file: config.php
 */

// init html code
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
require_once "dhcpd.inc.php";
require_once "sites.inc.php";

$action = $_POST['action'] ?? '';
$vlan_actif = $_POST['vlan'] ?? '0';

if (have_right($config, SE_ADMIN)) {
    $error = array();

    $content = "<h1>Paramètres du serveur DHCP</h1>";
    switch ($action) {
        case '':
        case 'index':
            $content .= dhcp_config_form($config, $vlan_actif, $error);
            $content .= dhcpd_status();
            break;

        case 'newconfig':
            $error = dhcp_update_config($config, $vlan_actif);
            if ($error == "") {
                exec("sudo /usr/share/sambaedu/sbin/make_dhcpd_conf.sh");
                echo "<meta http-equiv='refresh' content='0'>";
            }
            $content .= dhcp_config_form($config, $vlan_actif, $error);
            $content .= dhcpd_status();
            break;
        case 'restart':
            dhcpd_restart();
            $content .= dhcp_config_form($config, $vlan_actif, $error);
            $content .= dhcpd_status();
            break;
        case 'stop':
            dhcpd_stop();
            $content .= dhcp_config_form($config, $vlan_actif, $error);
            $content .= dhcpd_status();
            break;
        default:
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