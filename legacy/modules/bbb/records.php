<?php
/*
 * Liste des enregistrements
 * les profs peuvent supprimer
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

$bbb_list = config_bbb($config);
$hash = md5($config['ldap_base_dn']);
$html = "";
if (empty($bbb_list)) {
    echo "Le module BigBlueButton n'est pas configuré. Contactez l'administrateur.";
} else {
    /**
     * Records list
     */

    $login = $config['login'];

    if (! is_eleve($config, $login)) {
        $suppr = $_POST['supprimer'] ?? "";
        if (! empty($suppr)) {
            $recordID = $_POST['recordID'] ?? "";
            $bbbServer = $_POST['bbbServer'] ?? "";
            $html .= remove_record_bbb($config, $bbb_list, $recordID, $bbbServer);
        }
        $html .= "";
    }
    $html .= html_liste_records_bbb($config, $login, $bbb_list, $hash);
    echo $html;
}

// Footer
$html = "";
admin_footer_html($html);
echo $html;

?>