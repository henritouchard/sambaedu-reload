<?php
/*
 * Création d'un salon BBB
 * par un prof
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

$bbb_url = $config['bbb_server_base_url'] ?? '';
if (empty($bbb_url)) {
    echo "Le module BigBlueButton n'est pas configuré. Contactez l'administrateur.";
} else {
    /*
     * Les élèves ne peuvent pas créer des meetings
     */
    $login = $config['login'];

    if (is_eleve($config, $login)) {
        echo "Seuls les enseignants sont autorisés à créer des salons de visioconférence";
    } else {
        // $action = $_POST['valider'] ?? "";
        // if (empty($action)) {
        /*
         * Formulaire de création du meeting
         */
        $user = search_user($config, $login);
        $fullName = $user['fullname'];
        $sexe = $user['sexe'] ?? "";
        $html = form_create_bbb($config, $fullName, $sexe);
        echo $html;
        // }
    }
}
// Footer
$html = "";
admin_footer_html($html);
echo $html;
?>