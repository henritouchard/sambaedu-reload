<?php

/**

 * Gestion des gpo pour clients Windows (export des gpo)


 * @Projet SambaEdu 

 * @auteurs denis.bonnenfant

 * @Licence Distribue selon les termes de la licence GPL

 * @note 

 */
/**
 *
 * @Repertoire: registre
 * file: gpo-maj.php
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
require_once "ihm.inc.php";
require_once "gpo.inc.php";

echo "<h1>Export des GPO</h1>";
$exports = $_POST['exports'] ?? array();

$config = get_config($config, true, true);
// connexion();

if (! have_right($config, SE_COMPUTER_ADMIN))
    die("Vous n'avez pas les droits suffisants pour accéder à cette fonction</BODY></HTML>");

// Aide

if (count($exports) == 0) {

    echo "<h1>Liste des GPOs du domaine</h1>";

    $gpos = gpogetlink($config, $config['ldap_base_dn']);
    $nb = count($gpos);
    usort($gpos, 'compare_list_gpo_by_name');
    if ($nb > 15)
        $nb = 15;
    echo "<h3>Sélectionnez des GPO:</h3>";
    echo "<FORM method=\"post\" action=\"gpo-export.php\">\n";
    echo "<SELECT NAME=\"exports[]\" SIZE=\"" . $nb . "\" multiple>";

    foreach ($gpos as $gpo) {
        echo "<option value=\"" . $gpo["displayname"] . "\"";
        echo ">" . $gpo["displayname"] . "\n";
        echo "</option>";
    }
    echo "</SELECT>\n";
    echo "<input type=\"submit\" value=\"Valider\">\n";

    echo "</FORM>\n";
} else {
    foreach ($exports as $gpo) {

        $res = export_gpo($config, $gpo, true);
        echo "GPO " . $gpo . " :<br>\n";
        if ($res) {
            exec("cp -f \"/usr/share/sambaedu/gpo/etab_" . $gpo . ".zip\" \"/var/www/sambaedu/tmp/etab_" . $gpo . ".zip\"");
            echo "export OK : <a href='../tmp/etab_" . $gpo . ".zip'> Télécharger...</a><br>\n";
        } else {
            echo "ERREUR<br>\n";
        }
    }
}

$html = "";
admin_footer_html($html);
echo $html;
?>
