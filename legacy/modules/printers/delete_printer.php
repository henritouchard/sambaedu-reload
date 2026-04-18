<?php

/**

 * Suppression des imprimantes du parc selectionne ou de tout l'AD selon le choix
 * @Projet SambaEdu
 * @auteurs Equipe SambaÉdu
 * @Licence Distribue selon les termes de la licence GPL
 * @note

 */

/**
 *
 * @Repertoire: printers/
 * file: delete_printer.php
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
require_once "ldap.inc.php"; // pour fonction search_machines ()
include "ihm.inc.php"; // pour fonction is_admin()
include "printers.inc.php";
include "gpo.inc.php";


if (have_right($config, SE_ADMIN)) {

    $choix = $_POST['choix'] ?? $_GET['choix'] ?? "option1";
    $parc = $_POST['parc'] ?? $_GET['parc'] ?? "";
    $filtre_imp = $_POST['filtre_imp'] ?? "";
    $remove_printers = $_POST['remove_printers'] ?? array();
    $delete_printers = $_POST['delete_printers'] ?? array();
    $mp = $_POST['mp'] ?? array();
    $delete_printer = $_POST['delete_printer'] ?? false;

    if (($choix == "option1") && empty($parc) && (count($delete_printers) == 0)) {
        // Affichage de la page de selection du parc dans le cas du retrait d'imprimante(s) pour un parc.

        echo "<H1>Sélection du parc</H1>";
        $list_parcs = search_parcs($config, "*", "salle"); // Liste des parcs existants
        if (count($list_parcs) > 0) {
            sort($list_parcs);
            echo "<FORM METHOD=\"post\">\n";
            echo "<SELECT NAME=\"parc\" SIZE=\"10\">";
            for ($loop = 0; $loop < count($list_parcs); $loop ++) {
                echo "<OPTION VALUE=\"" . $list_parcs[$loop]["name"] . "\">" . $list_parcs[$loop]["name"] . "\n";
            }
            echo "</SELECT>&nbsp;&nbsp;\n";
            echo "<INPUT TYPE=\"hidden\" NAME=\"choix\" VALUE=\"option1\">";
            echo "<INPUT TYPE=\"submit\" VALUE=\"Valider\">\n";
            echo "</FORM>\n";
        }
    }
    if (! $delete_printer && (! empty($parc) || ($choix == "option2"))) {
        // Affichage de la page de selection des imprimantes a supprimer.

        // Lecture des membres du parc
        $mp_all = array();
        if ($choix == "option1") { // Cas d'une suppression par parc
            foreach (list_group_printers($config, $parc)['printers'] as $p) { // ********************
                $mp_all[] = $p['name'];
            }
        } else { // Cas d'une suppression definitive
            foreach (list_printers($config, "", true) as $p) {
                $mp_all[] = $p['name'];
            }
        }
        if (count($mp_all) > 0) {
            echo "<H1>Sélection des imprimantes à supprimer</H1>";
            // Filtrage des noms
            // Affichage de la boite de saisie du nom d'imprimante a filtrer
            echo "<FORM ACTION=\"delete_printer.php\" METHOD=\"post\">\n";
            echo "<P>Lister les noms contenant:";
            echo "<INPUT TYPE=\"text\" NAME=\"filtre_imp\"\n VALUE=\"$filtre_imp\" SIZE=\"8\">";
            echo "<INPUT TYPE=\"hidden\" NAME=\"parc\" VALUE=\"$parc\">\n";
            if ($choix == "option1") {
                echo "<INPUT TYPE=\"hidden\" NAME=\"choix\" VALUE=\"option1\">";
            } else {
                echo "<INPUT TYPE=\"hidden\" NAME=\"choix\" VALUE=\"option2\">";
            }
            echo "<INPUT TYPE=\"submit\" VALUE=\"Valider\">\n";
            echo "</FORM>\n";
            // Filtrage selon critere indique par l'utilisateur
            if ("$filtre_imp" == "")
                $mp = $mp_all;
            else {
                for ($loop = 0; $loop < count($mp_all); $loop ++) {
                    $imp = $mp_all[$loop];
                    if (preg_match("/" . $filtre_imp . "/", $imp))
                        $mp[] = $imp;
                }
            }
            if (count($mp) > 15)
                $size = 15;
            else
                $size = count($mp); // Definition de la taille du formulaire liste
            if (count($mp) > 0) { // Dans le cas ou il y'a desimprimantes
                                  // Affichage du formulaire liste des imprimantes valides.
                echo "<FORM ACTION=\"delete_printer.php\" method=\"post\">\n";
                if ($choix == "option1") {
                    echo "<P>Sélectionnez les imprimantes à enlever du parc $parc:</P>\n";
                    echo "<P><SELECT SIZE=\"" . $size . "\" NAME=\"remove_printers[]\" MULTIPLE=\"multiple\">\n";
                    for ($loop = 0; $loop < count($mp); $loop ++) {
                        echo "<OPTION VALUE=\"" . $mp[$loop] . "\">" . $mp[$loop] . "";
                    }
                } else {
                    echo "<P>Sélectionnez les imprimantes que vous souhaitez supprimer:</P>\n";
                    echo "<P>ATTENTION !! La suppression effacera intégralement les informations de configuration pour les imprimantes sélectionnées !</P>";
                    echo "<P><SELECT SIZE=\"" . $size . "\" NAME=\"delete_printers[]\" MULTIPLE=\"multiple\">\n";
                    for ($loop = 0; $loop < count($mp); $loop ++) {
                        echo "<OPTION VALUE=\"" . $mp[$loop] . "\">" . $mp[$loop] . "";
                    }
                }
                echo "</SELECT></P>\n";
                echo "<INPUT TYPE=\"hidden\" NAME=\"delete_printer\" VALUE=\"true\">\n";
                echo "<INPUT TYPE=\"hidden\" NAME=\"parc\" VALUE=\"$parc\">\n";
                echo "<INPUT TYPE=\"hidden\" NAME=\"choix\" VALUE=\"$choix\">";
                echo "<INPUT TYPE=\"submit\" VALUE=\"Valider\" ONCLICK= \"return getconfirm();\">\n";
                echo "</FORM>\n";
            }
        } else { // A fortiori quand il n'y'a pas d'imprimantes a supprimer
            echo "<h1>Suppression d'imprimantes</h1>\n";
            $message = "Il n'y a pas d'imprimantes à supprimer !";
            echo $message;
            echo "<br><br><center>";
            echo "<a href=\"delete_printer_choice.php\">Retour</a>";
        }
    } else { // Affichage de la page de configmation des suppressions.
             // Suppression des imprimantes dans le parc
        if (count($remove_printers) > 0) {
            echo "<H1>Suppression d'imprimantes dans le parc <U>$parc</U></H1>";
            echo "<P>Vous avez sélectionné " . count($remove_printers) . " imprimante(s)<BR><BR>\n";
            for ($loop = 0; $loop < count($remove_printers); $loop ++) {
                $printer = $remove_printers[$loop];

                if (remove_printer_group($config, $printer, $parc)) {
                    echo "Suppression de l'imprimante <B><a href='../printers/view_printers.php?one_printer=imp1'>$printer</a></B> du parc <B><a href='../parcs/show_parc.php?parc=$parc'>$parc</a></B> effectuée<BR>";
                } else {
                    echo "<B>ÉCHEC</B> de la suppression de l'imprimante <B><a href='../printers/view_printers.php?one_printer=imp1'>$printer</a></B> du parc <B><a href='../parcs/show_parc.php?parc=$parc'>$parc</a></B><BR>";
                }
            }
        } elseif (count($delete_printers) > 0) {
            echo "<H1>Suppression définitive d'imprimantes</H1>";
            echo "<P>Vous avez sélectionné " . count($delete_printers) . " imprimante(s)<BR><BR>\n";
            for ($loop = 0; $loop < count($delete_printers); $loop ++) {
                $printer = $delete_printers[$loop];
                if (delete_printer($config, $printer)) {
                    echo "Suppression de l'imprimante <B>$printer</B> effectuée<BR>";
                } else {
                    echo "<B>ÉCHEC</B> de la suppression de l'imprimante <B>$printer</B><BR>";
                }
            }
        }
        echo "<br><br><center>";
        echo "<a href=\"delete_printer_choice.php\">Retour</a>";
    }
}

$html = "";
admin_footer_html($html);
echo $html;
?>