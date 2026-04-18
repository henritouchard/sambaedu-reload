<?php

/**

 * Permet d'ajouter des drivers d'imprimantes Windows
 * @Projet SambaEdu 
 * @auteurs Equipe SambaÉdu
 * @Licence Distribue selon les termes de la licence GPL
 * @note 
 */

/**
 *
 * @Repertoire: printers/
 * file: add_driver.php
 *
 */

// Affichage de la page pour ajouter des imprimantes a des parcs
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
require_once "ldap.inc.php";
include "ihm.inc.php";
include "printers.inc.php";


if (have_right($config, SE_ADMIN)) {
    $server = $_POST['server'] ?? "";
    $printer = $_POST['printer'] ?? "";
    if (! empty($server)) {
        $drivers = enum_smb_printers($config, $server);
    } else {
        $drivers = [];
    }
    echo "<H1>Sélection des pilotes d'imprimantes Windows à ajouter sur le serveur</H1>\n";
    if (! empty($server) and count($drivers) == 0) {
        echo "<P><STRONG><I>Attention : l'ordinateur sélectionné ne contient pas d'imprimantes paramétrées correctement.</I></STRONG></P>\n";
    } elseif (! empty($printer)) {
        $out = upload_printer_driver($config, $printer, $server);
        echo $out;
    }
    echo "<FORM ACTION=\"add_driver.php\" METHOD=\"post\">\n";
    echo "<P>Procédure :</P>\n";
    echo "<OL>\n";
    echo "<LI>Installer l'imprimante en local sur un poste avec le pilote (64 bits obligatoirement) à ajouter sur le serveur.</LI>\n";
    echo "<LI>Renseigner le champ <I>Emplacement</I> dans l'onglet <I>Général</I> des propriétés de l'imprimante. (Cela permet de la rendre visible du côté du serveur.)</LI>\n";
    echo "<LI>Entrer le nom du poste contenant le pilote installé : \n";
    echo "<INPUT TYPE=\"text\" NAME=\"server\" VALUE=\"$server\" SIZE=\"12\">\n";
    echo "<INPUT TYPE=\"submit\" VALUE=\"Valider\">\n";
    echo "</LI>\n";
    echo "<LI>Choisir le pilote installé localement à récupérer sur le serveur d'impression SambaÉdu :\n";
    echo "<SELECT NAME=\"printer\">\n";
    foreach ($drivers as $driver) {
        echo "<OPTION VALUE=\"" . $driver['smb_name'] . "\"";
        if ($driver['smb_name'] == $printer) {
            echo " selected";
        }
        echo ">" . $driver['smb_driver'] . "</OPTION>\n";
    }
    echo "</SELECT>\n";
    echo "<INPUT TYPE=\"hidden\" NAME=\"add_print\" VALUE=\"true\">\n";
    echo "<INPUT TYPE=\"submit\" VALUE=\"Charger le pilote\">\n";
    echo "</LI>\n";
    echo "</OL>\n";
    echo "</FORM>\n";
    echo "<a href=config_printer.php> Configurer une imprimante...";
}

$html = "";
admin_footer_html($html);
echo $html;
?>