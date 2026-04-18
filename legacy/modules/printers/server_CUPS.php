<?php

/**

 * Verifie le fonctionnement de CUPS
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
 * file: server_CUPS.php
 *
 */

// Etat du serveur d'impression CUPS
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
    echo "<H1>Serveur CUPS</H1>";
    echo "Serveur actif : ";
    $status = exec("LC_ALL=C /usr/bin/lpstat -r");
    if ($status == "scheduler is running")
        echo "<FONT COLOR=\"green\">OUI</FONT>";
    else
        echo "<FONT COLOR=\"red\">NON</FONT>";
}

$html = "";
admin_footer_html($html);
echo $html;
?>