<?php

/**

 * Choix entre supression d'un parc ou complete
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
 * file: delete_printer_choice.php
 *
 */

// Affichage du menu de suppression
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
include "ihm.inc.php"; // pour is_admin()
include "printers.inc.php";


if (have_right($config, SE_ADMIN)) {
    // Selection de la suppression: supprimer une ou plusieurs imprimante(s) d'un parc seulement, ou integralement
    echo "<H1>Sélection du mode de suppression</H1>";
    echo "<FORM ACTION=\"delete_printer.php\" method=\"post\">\n";
    echo "<P>Que souhaitez-vous ?</P>";
    echo "<INPUT TYPE=\"radio\" NAME=\"choix\" VALUE=\"option1\" CHECKED>Supprimer des imprimantes d'un parc seulement<BR><BR>";
    echo "<INPUT TYPE=\"radio\" NAME=\"choix\" VALUE=\"option2\">Supprimer définitivement des imprimantes (<B>CHOIX DANGEREUX</B>)<BR><BR>";
    echo "<P><INPUT TYPE=\"submit\" VALUE=\"Valider\"\n></P>";
    echo "</FORM>\n";
}

$html = "";
admin_footer_html($html);
echo $html;
?>