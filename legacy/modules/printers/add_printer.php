<?php

/**

 * Permet d'ajouter des imprimantes a des parcs
 * @Projet SambaEdu 
 * @auteurs Equipe SambaÉdu
 * @Licence Distribue selon les termes de la licence GPL
 * @note 
 */

/**
 *
 * @Repertoire: printers/
 * file: add_printer.php
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
include "gpo.inc.php";


if (have_right($config, SE_ADMIN)) {

    $parc = $_POST['parc'] ?? $_GET['parc'] ?? "";
    $filtre_imp = $_POST['filtre_imp'] ?? "";
    $filtre = $_POST['filtre'] ?? "";
    $new_printers = $_POST['new_printers'] ?? array();
    $add_print = $_POST['add_print'] ?? $_GET['add_print'] ?? false;

    // Affichage du formulaire de selection de parc
    if (empty($parc)) {
        echo "<H1>Sélection du parc à alimenter</H1>";
        $list_parcs = search_parcs($config, "*", "salle"); // Liste des parcs existants
        if (count($list_parcs) > 0) {
            sort($list_parcs);
            echo "<FORM METHOD=\"post\">\n";
            echo "<SELECT NAME=\"parc\" SIZE=\"10\">";
            for ($loop = 0; $loop < count($list_parcs); $loop ++) {
                echo "<OPTION VALUE=\"" . $list_parcs[$loop]["name"] . "\">" . $list_parcs[$loop]["name"] . "\n";
            }
            echo "</SELECT>&nbsp;&nbsp;\n";
            echo "<INPUT TYPE=\"submit\" VALUE=\"Valider\">\n";
            echo "</FORM>\n";
        }
    } elseif (! $add_print) {
        // Lecture des imprimantes du parc
        $mp = list_group_printers($config, $parc)['printers'] ?? array(); // *************************
                                                                           // Creation d'un tableau des nouvelles imprimantes a integrer
        $list_imprimantes = list_printers($config, "", true);
        // tri des imprimantes deja presentes dans le parc
        $mpcount = count($mp);
        $list_new_imprimantes = array();
        for ($loop = 0; $loop < count($list_imprimantes); $loop ++) {
            $imp = $list_imprimantes[$loop]['name'];
            foreach ($mp as $p) {
                if ($p['name'] == $imp) {
                    break;
                }
            }
            if (! isset($p['name']) || ($p['name'] != $imp)) // (comment $p peut exister ici ? bizarrerie ?)
                $list_new_imprimantes[] = $imp;
        }
        // Affichage de la page de selection des imprimantes a ajouter au parc
        echo "<H1>Sélection des imprimantes</H1>";
        if (count($list_new_imprimantes) > 0) {
            sort($list_new_imprimantes);
            // Filtrage des noms
            echo "<FORM ACTION=\"add_printer.php\" METHOD=\"post\">\n";
            echo "<P>Lister les noms contenant : </P>";
            echo "<INPUT TYPE=\"text\" NAME=\"filtre_imp\"\n VALUE=\"$filtre_imp\" SIZE=\"8\">";
            echo "<INPUT TYPE=\"hidden\" NAME=\"parc\" VALUE=\"$parc\">\n";
            echo "<INPUT TYPE=\"hidden\" NAME=\"filtre\" VALUE=\"$filtre\">\n";
            echo "<INPUT TYPE=\"submit\" VALUE=\"Valider\">\n";
            echo "</FORM>\n";
        }
        // Affichage du formulaire de liste des imprimantes
        if (count($list_new_imprimantes) > 15)
            $size = 15;
        else
            $size = count($list_new_imprimantes);
        if (count($list_new_imprimantes) > 0) {
            echo "<FORM ACTION=\"add_printer.php\" METHOD=\"post\">\n";
            echo "<P>Sélectionnez les nouvelles imprimantes à intégrer au parc:</P>\n";
            echo "<p><SELECT SIZE=\"" . $size . "\" NAME=\"new_printers[]\" MULTIPLE=\"multiple\">\n";
            for ($loop = 0; $loop < count($list_new_imprimantes); $loop ++) {
                echo "<OPTION VALUE=\"" . $list_new_imprimantes[$loop] . "\">" . $list_new_imprimantes[$loop];
            }
            echo "</SELECT></P>\n";
            echo "<INPUT TYPE=\"hidden\" NAME=\"add_print\" VALUE=\"true\">\n";
            echo "<INPUT TYPE=\"hidden\" NAME=\"parc\" VALUE=\"$parc\">\n";
            echo "<INPUT TYPE=\"submit\" VALUE=\"Valider\">\n";
            echo "</FORM>\n";
        } else {
            $message = "Il n'y a pas de nouvelle imprimantes à ajouter !";
            echo $message;
        }
    } else {
        // Ajout des imprimantes dans le parc selectionne
        echo "<H1>Alimentation du parc <U>$parc</U></H1>";
        echo "<P>Vous avez sélectionné " . count($new_printers) . " imprimante(s)<BR>\n";
        for ($loop = 0; $loop < count($new_printers); $loop ++) {
            $printer = $new_printers[$loop];
            $ret = add_printer_group($config, $printer, $parc);
            if ($ret) {
                echo "Ajout de l'imprimante <B><a href='../printers/view_printers.php?one_printer=imp1'>$printer</a></B> au parc <B><a href='../parcs/show_parc.php?parc=$parc' title='Voir les machines du parc.'>$parc</a></B> effectué<BR>";
            } else {
                echo "<B>ÉCHEC</B> de l'ajout de l'imprimante <B>$printer</B> au parc <B>$parc</B><BR>";
            }
        }
    }
}

$html = "";
admin_footer_html($html);
echo $html;
?>
