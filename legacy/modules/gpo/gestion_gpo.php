<?php

/**
 * Gestion des GPO pour clients Windows (page d'import-export)
 * @Projet SambaEdu
 * @auteurs Deins Bonnenfant
 * @Licence Distribue selon les termes de la licence GPL
 * @note
 */

/**
 *
 * @Repertoire: registre
 * file: gestion_interface.php
 *
 */

/*
 *
 * $action=$_GET['action'];
 * $cat=$_GET['cat'];
 * $sscat=$_GET['sscat'];
 * if (!$cat) { $cat=$HTTP_COOKIE_VARS["Categorie"]; }
 * if ($cat) {
 * setcookie ("Categorie", "", time() - 3600);
 * setcookie("Categorie",$cat,time()+3600);
 * }
 *
 * if ($cat=="tout") {
 * setcookie ("Categorie", "", time() - 3600);
 * $cat="";
 * $sscat="";
 * }
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
require_once "functions.inc.php";
require_once "ldap.inc.php";
require_once "ihm.inc.php";
require_once "gpo.inc.php";

if (! have_right($config, SE_COMPUTER_ADMIN)) {
    die("Vous n'avez pas les droits suffisants pour accéder à cette fonction</BODY></HTML>");
}

check_gpo_templates($config);
echo "<h1>Gestion des GPO :</h1>";
if (empty($config['etab_ou'])) {
    echo "<a href=\"gpo-maj.php\">Effectuer la mise a jour de la base des GPO</a><br>";
    echo "<div><a href=\"gpo-export.php\">Exporter mes GPO ?</a></p></p>";
}
echo "<h1>Gestion des exclusions de profils itinérants Windows :</h1>";
echo "<a href=\"no_roam.php\">Statistiques sur la taille des dossiers des profils itinérants et exclusion de dossiers de ceux-ci</a><br>";


# pied de page
$html = "";
admin_footer_html($html);
echo $html;
?>