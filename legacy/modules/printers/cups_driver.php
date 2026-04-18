<?php

/**

 * Affiche la page de selection des drivers
 * @Version $Id$

 * @Projet LCS / SambaEdu

 * @auteurs Patrice Andre <h.barca@free.fr>
 * @auteurs Carip-Academie de Lyon

 * @Licence Distribue selon les termes de la licence GPL

 * @note

 */

/**
 *
 * @Repertoire: printers/
 * file: cups_driver.php
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
include "ihm.inc.php"; // pour is_admin()


if (have_right($config, SE_ADMIN)) {
    echo "<h1>Pilote CUPS</H1>";
    echo "<H3>Sélectionnez un pilote dans la liste</H3>";

    // Retourne le nombre de pilotes
    $nb_drivers = exec("lpinfo -m | wc -l");
    // Retourne les pilotes
    $return = exec("lpinfo -m", $all_drivers);
    foreach ($all_drivers as $l) {
        $f = explode(" ", $l);
        list ($type, $ppd) = explode(":", $f[0]);
        $brand = $f[1];
        $model = $f[2] ?? "";
        $engine = $f[3] ?? "";
        $drivers[] = [
            'type' => $type,
            'ppd' => $ppd,
            'brand' => $brand,
            'model' => $model,
            'engine' => $engine
        ];
    }
    echo "<pre>" . print_r($drivers, true) . "</pre>";
    exit();
    // Affichage du filtre sur constructeur
    if (! isset($filtre)) {
        echo "<P>Nom d'utilisateur : </P>";
        echo "<FORM ACTION=\"cups_driver.php\" METHOD=\"post\">";
        echo "<INPUT TYPE=\"text\" NAME=\"filtre\" VALUE=\"$filtre\" SIZE=\"20\">";
        echo "<INPUT TYPE=\"hidden\" NAME=\"info_imprimante\" VALUE=\"$info_imprimante\">";
        echo "<INPUT TYPE=\"hidden\" NAME=\"uri_imprimante\" VALUE=\"$uri_imprimante\">";
        echo "<INPUT TYPE=\"hidden\" NAME=\"nom_imprimante\" VALUE=\"$nom_imprimante\">";
        echo "<INPUT TYPE=\"hidden\" NAME=\"info_imprimante\" VALUE=\"$lieu_imprimante\">";
        echo "<INPUT TYPE=\"hidden\" NAME=\"protocole\" VALUE=\"$protocole\">";
        echo "<INPUT TYPE=\"submit\" VALUE=\"Filtrer\">";
        echo "</FORM>";
    }

    echo "<FORM ACTION=\"config_printer.php\" METHOD=\"post\">";
    echo "<SELECT NAME=\"driver_name\" SIZE=\"15\" MULTIPLE>";
    for ($i = 0; $i < $nb_drivers; $i ++) {
        if (! isset($filtre) || (($fabricant[$i] == $filtre))) {
            echo "<OPTION VALUE=\"$all_drivers[$i]\">$all_drivers[$i]";
            echo "</OPTION>";
            echo "<INPUT TYPE=\"hidden\" NAME=\"info_imprimante\" VALUE=\"$info_imprimante\">";
            echo "<INPUT TYPE=\"hidden\" NAME=\"uri_imprimante\" VALUE=\"$uri_imprimante\">";
            echo "<INPUT TYPE=\"hidden\" NAME=\"nom_imprimante\" VALUE=\"$nom_imprimante\">";
            echo "<INPUT TYPE=\"hidden\" NAME=\"info_imprimante\" VALUE=\"$lieu_imprimante\">";
            echo "<INPUT TYPE=\"hidden\" NAME=\"protocole\" VALUE=\"$protocole\">";
            echo "<BR>";
        }
    }
    echo "</SELECT>";
}

$html = "";
admin_footer_html($html);
echo $html;
?>