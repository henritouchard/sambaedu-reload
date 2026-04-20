<?php
/*
 * Liste et jonction d'un salon BBB
 *
 */
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
include "ihm.inc.php";
include "bbb.inc.php";
require "../vendor/autoload.php";

use BigBlueButton\BigBlueButton;
use BigBlueButton\Parameters\JoinMeetingParameters;

/*
 * List des meeting
 *
 */

$bbb_list = config_bbb($config);
$hash = md5($config['ldap_base_dn']);

if (count($bbb_list) == 0) {
    echo "Le module BigBlueButton n'est pas configuré. Contactez l'administrateur.";
} else {
    /**
     * Meetings lists to rejoin
     */
    $html = "";
    $login = $config['login'];
    $html .= liste_meetings2join_bbb($config, $login, $bbb_list, $hash);

    if (have_right($config, SE_ADMIN)) {
        /**
         * Affiche pour l'admin la liste des serveurs et le nombre de participants
         */
        $html .= "<h1>Informations sur les serveurs</h1>";
        $html .= info_servers_bbb($config, $bbb_list);
        /**
         * Affiche pour l'admin la liste des salon
         */
        $html .= "<h1>Informations sur les conférences</h1>";
        $html .= liste_meetings_servers_bbb($config, $bbb_list);
    }
}

echo $html;
// Footer
$html = "";
admin_footer_html($html);
echo $html;

?>