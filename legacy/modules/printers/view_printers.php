<?php

/**

 * Permet une gestion individuelle des imprimantes
 * @Projet SambaEdu
 * @Auteurs Equipe Sambaedu
 * @Licence Distribue sous la licence GPL

 * @note

 */

/**
 *
 * @Repertoire: printers/
 * file: view_printers.php
 *
 */

// Affiche les parametres de chaque imprimante
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
include "printers.inc.php";
include "ihm.inc.php"; // pour is_admin()
                       // include "ldap.inc.php";

if (! have_right($config, SE_COMPUTER_ADMIN)) {
    die("<div class=error_msg>Droits insuffisants pour accéder à cette fonction du serveur Se4 !</div>");
}

$one_printer = $_POST['one_printer'] ?? $_GET['one_printer'] ?? "";

$num = $_POST['num'] ?? "";
$status = $_POST['status'] ?? "";
$queue = $_POST['queue'] ?? "";
$period = $_POST['period'] ?? "";
$pages = $_POST['pages'] ?? "";
$quota = $_POST['quota'] ?? "";
$valids = $_POST['valids'] ?? "";
$validq = $_POST['validq'] ?? "";

// SECURITY (review 1bis-15 #4) : $status et $queue sont concaténés à "cups"
// puis exécutés via sudo /usr/sbin/ (RCE root via sudoers NOPASSWD). Whitelist
// stricte : seules les valeurs fonctionnelles attendues sont tolérées. Toute
// autre valeur est neutralisée (reset à "") — la condition !empty($valids|validq)
// plus bas empêchera alors l'exec.
if (! in_array($status, ['enable', 'disable'], true)) {
    $status = "";
}
if (! in_array($queue, ['accept', 'reject'], true)) {
    $queue = "";
}
// Le nom d'imprimante injecté dans exec() est également attacker-controlled
// via $num indexant $all_printers. $num doit être un entier.
if (! ctype_digit((string) $num)) {
    $num = "";
}

$all_printers = list_printers($config, $one_printer, false);
$nb_printers = count($all_printers);
$action = $_GET['action'] ?? "";
$lieu = $_GET['lieu'] ?? "";

// debug_var();
// debug valeurs du tableau contenant les infos sur les imprimantes
// var_dump($all_printers);
if (! empty($valids)) {
    $able = "cups" . $status;

    exec("sudo /usr/sbin/$able {$all_printers[$num]['name']}");
    // relecture du tableau pour récupérer le statut modifié
    $all_printers = list_printers($config, $one_printer, false);
} elseif (! empty($validq)) {
    $able = "cups" . $queue;
    exec("sudo /usr/sbin/$able {$all_printers[$num]['name']}");
    // relecture du tableau pour récupérer le statut modifié
    $all_printers = list_printers($config, $one_printer, false);
}

// Affichage du navigateur d'imprimantes si non $one_printer :
if (empty($one_printer)) {
    echo "<H1>Gestion des imprimantes</H1>";
    if (count($all_printers)) {
        if ($lieu == 1) {
            usort($all_printers, "cmp_location");
        } else {
            usort($all_printers, "cmp_printer");
        }
    }
    // Test serveur d'impression
    $status = exec("LC_ALL=C /usr/bin/lpstat -r");
    echo "\n<br>\n<CENTER>\n";
    echo "<table class='table-bordered' width=\"60%\">\n";

    echo "<tr class=menuheader style=\"height: 30\">\n";
    echo "<td colspan=\"8\" valign=\"middle\" align=\"center\">";
    echo "Serveur d'impression ";
    if ($status == "scheduler is running") {
        echo "<u onmouseover=\"return escape('<b>Etat : Serveur d\'impression en marche')\">";
        echo "<IMG style=\"border: 0px solid;\" SRC=\"../elements/images/enabled.png\" >";
        echo "</u>\n";
    } else {
        echo "<u onmouseover=\"return escape('<b>Etat : Serveur d\'impression stoppé')\">";
        echo "<IMG style=\"border: 0px solid;\" SRC=\"../elements/images/disabled.png\" >";
        echo "</u>\n";
    }
    echo "</td>";
    echo "</tr>";
    echo "<tr class=menuheader style=\"height: 30\">\n";

    echo "<td align=\"center\"></td>\n";
    echo "<td align=\"center\"><a href=view_printers.php>Imprimantes</a></td>\n";
    echo "<td align=\"center\">URI</td>\n";
    echo "<td align=\"center\">Information</td>\n";
    echo "<td align=\"center\"><a href=view_printers.php?lieu=1>Lieu</a></td>\n";
    echo "<td align=\"center\">Statut</td>\n";
    echo "<td align=\"center\">Etat</td>\n";

    echo "<td align=\"center\">Parc ou groupe</td>\n";
    echo "</tr>";

    $printers_groups = apcu_fetch('list_printer_group');
    if (empty($printers_groups)) {
        $printers_groups = list_printer_groups($config, "", true);
        apcu_store('list_printer_group', $printers_groups, 300);
    }
    // var_dump($printers_groups);
    // var_dump($all_printers);
    for ($loop = 0; $loop < count($printers_groups); $loop ++) {
        $printer = $printers_groups[$loop]['printer'];

        echo "<TR>";
        echo "<td align=\"center\"><img style=\"border: 0px solid ;\" src=\"../elements/images/printer.png\" title=\"Imprimante\" alt=\"Imprimante\">";
        echo "</TD><TD>";
        $printer_name = $printer['name'];
        if ($nb_printers < 6) {
            echo "<A HREF=\"#tag[$loop]\">$printer_name</A>";
        } else {
            echo "<A href='view_printers.php?one_printer=$printer_name'>$printer_name</A>";
        }
        echo "</TD><TD>";
        echo $printer['url'];
        echo "</TD><TD>";
        echo $printer['description'];
        echo "</TD><TD>";
        echo $printer['location'];
        echo "</TD><TD>";
        echo $printer['etat'];
        echo "</TD><TD>";
        echo $printer['statut'];
        echo "</TD><TD>";

        $groups = $printers_groups[$loop]['groups']; // *****************************************
                                                     // var_dump($groups);
        if (count($groups) > 0) {
            foreach ($groups as $group) {
                if ($group['type'] == "user") {
                    echo "<A href=../annu/group.php?filter=" . $group['name'] . ">";
                } else {
                    echo "<A href=../parcs/show_parc.php?parc=" . $group['name'] . ">";
                }
                echo $group['name'];
                echo "</A>";
                echo "<br>";
            }
        } else {
            echo "Sans parc";
        }
        echo "</TD></TR>";
    }
    echo "</table><br>\n";
}

// Si trop d'imprimante (>6) on ne les affiche plus ************************* est-ce que ça fonctionne vraiment ?
if (($nb_printers > 5) && ($action != "all")) {
    echo "<br><hr><center>";
    echo "<A href='view_printers.php?action=all'>Détail de toutes les imprimantes</A> ";
    echo " <u onmouseover=\"return escape('Permet de voir le détail de toutes les imprimantes. Cela peut être très long à afficher si vous en avez beaucoup.')\">
<img name=\"action_image2\"  src=\"../elements/images/system-help.png\"></u> ";
    echo "</center>";

    $html = "";
    admin_footer_html($html);
    echo $html;
    exit();
}
if ($action == "all") { // Est-ce encore utile ?
    echo "<HR>\n";
}

for ($loop = 0; $loop < $nb_printers; $loop ++) {
    $printer = $all_printers[$loop]['name'];

    if (($one_printer != "") && ($action != "all")) {
        echo "<H1>Liste des imprimantes</H1>";
    }
    // echo $printer;
    echo "<TABLE width=\"90%\"><TR><TD width=\"80%\">";
    echo "<FONT SIZE=5><A NAME=\"tag[$loop]\"><B>$printer</B></A></FONT>\n";
    echo "</TD>\n";
    // Ajout pour pouvoir modifier
    echo "<TD>";
    echo "<FORM ACTION=\"config_printer.php\" METHOD=\"post\">\n";
    echo "<INPUT TYPE=\"hidden\" VALUE=\"$printer\" NAME=\"nom\">\n";
    if (have_right($config, SE_ADMIN)) {
        echo "<INPUT TYPE=\"submit\" VALUE=\"Modifier\" NAME=\"modifs\">\n";
    }
    echo "</FORM>\n";
    echo "</TD>\n";
    // Ajout pour pouvoir supprimer
    echo "<TD>";
    echo "<FORM ACTION=\"delete_printer.php\" METHOD=\"post\">\n";
    echo "<input type=\"hidden\" name=\"delete_printer\" value=\"true\">\n";
    echo "<INPUT TYPE=\"hidden\" VALUE=\"$printer\" NAME=\"delete_printers[]\">\n";
    if (have_right($config, SE_ADMIN)) {
        echo "<INPUT TYPE=\"submit\" VALUE=\"Supprimer\" NAME=\"suppression\">\n";
    }
    echo "</FORM>\n";
    echo "</TD>\n";

    echo "</TR>\n</TABLE>\n";
    $URI = preg_replace("/:[^:]*@/", ":*******@", $all_printers[$loop]['url']);
    echo "<BR><BR>\n";
    echo "<TABLE BORDER=0>\n";
    echo "<TR><TD BGCOLOR=\"cornflowerblue\"><B>URI:</B></TD><TD WIDTH=300 BGCOLOR=\"cornflowerblue\">$URI</TD></TR>\n";
    echo "<TR><TD BGCOLOR=\"cornflowerblue\"><B>Emplacement :</B></TD><TD WIDTH=300 BGCOLOR=\"cornflowerblue\">" . $all_printers[$loop]['location'] . "</TD></TR>\n";
    echo "<TR><TD BGCOLOR=\"cornflowerblue\"><B>Description :</B></TD><TD WIDTH=300 BGCOLOR=\"cornflowerblue\">" . $all_printers[$loop]['description'] . "</TD></TR>\n";
    echo "<TR><TD BGCOLOR=\"cornflowerblue\"><B>Travaux en cours :</B></TD>\n";
    if ($all_printers[$loop]['etat'] == "printing") {
        echo "<TD BGCOLOR=\"cornflowerblue\"><BLINK>OUI</BLINK></TD></TR>\n";
    } else {
        echo "<TD BGCOLOR=\"cornflowerblue\">NON</TD></TR>\n";
    }
    echo "<TR><TD BGCOLOR=\"lightsteelblue\"><B>Etat:</B></TD>\n";
    if ($all_printers[$loop]['statut'] == "enabled") {
        echo "<TD BGCOLOR=\"lightsteelblue\"><FONT COLOR=\"green\">Active</FONT></TD>\n";
        $status = "disable";
    } else {
        echo "<TD BGCOLOR=\"lightsteelblue\"><FONT COLOR=\"red\">Inactive</FONT></TD>\n";
        $status = "enable";
    }
    echo "<TD BGCOLOR=\"lightsteelblue\">\n";
    echo "<FORM ACTION=\"view_printers.php\" METHOD=\"post\">\n";
    echo "<INPUT TYPE=\"hidden\" VALUE=\"$loop\" NAME=\"num\">\n";
    echo "<INPUT TYPE=\"hidden\" VALUE=\"$status\" NAME=\"status\">\n";
    echo "<INPUT TYPE=\"hidden\" VALUE=\"$one_printer\" NAME=\"one_printer\">\n";
    echo "<INPUT TYPE=\"submit\" VALUE=\"Basculer\" NAME=\"valids\">\n";
    echo "</FORM></TD>\n";
    echo "<TD VALIGN=\"top\" BGCOLOR=\"lightsteelblue\">Activer/Désactiver l'imprimante</TD></TR>\n";
    echo "<TR><TD BGCOLOR=\"lightsteelblue\"><B>Travaux d'impression:</B></TD>\n";
    $sys = exec("LC_ALL=C /usr/bin/lpstat -a $printer | grep not");
    if ($sys != "") {
        echo "<TD BGCOLOR=\"lightsteelblue\"><FONT COLOR=\"red\">Rejette</FONT></TD>\n";
        $queue = "accept";
    } else {
        echo "<TD BGCOLOR=\"lightsteelblue\"><FONT COLOR=\"green\">Accepte</FONT></TD>\n";
        $queue = "reject";
    }
    echo "<TD BGCOLOR=\"lightsteelblue\">\n";
    echo "<FORM ACTION=\"view_printers.php\" METHOD=\"post\">\n";
    echo "<INPUT TYPE=\"hidden\" VALUE=\"$loop\" NAME=\"num\">\n";
    echo "<INPUT TYPE=\"hidden\" VALUE=\"$queue\" NAME=\"queue\">\n";
    echo "<INPUT TYPE=\"hidden\" VALUE=\"$one_printer\" NAME=\"one_printer\">\n";
    echo "<INPUT TYPE=\"submit\" VALUE=\"Basculer\" NAME=\"validq\">\n";
    echo "</FORM></TD>\n";
    echo "<TD VALIGN=\"top\" BGCOLOR=\"lightsteelblue\">Accepter/Rejeter les travaux</TD></TR>\n";
    echo "</TABLE>\n";
    echo "<BR>";
    // Affiche le bouton pour basculer sur la page travaux d'impression
    echo "<FORM ACTION=\"printer_jobs.php\" METHOD=\"post\">\n";
    echo "<INPUT TYPE=\"hidden\" VALUE=\"$printer\" NAME=\"printer\">\n";
    // AJOUT: boireaus pour permettre un retour apres consultation des travaux
    echo "<INPUT TYPE=\"hidden\" VALUE=\"tag[$loop]\" NAME=\"tag\">\n";
    echo "<INPUT TYPE=\"submit\" VALUE=\"Travaux\" NAME=\"travaux\">\n";
    echo "&nbsp;Voir les travaux";
    echo "</FORM>\n";

    // Affichage du formulaire de quota
    /*
     * $nb_jours[$loop] = round(($nb_sec[$loop]) / 86400);
     * echo "<FORM ACTION=\"view_printers.php\" METHOD=\"post\">\n";
     * echo "<INPUT TYPE=\"hidden\" VALUE=\"$printer\" NAME=\"printer\">\n";
     * echo "<INPUT TYPE=\"hidden\" VALUE=\"$loop\" NAME=\"num\">\n";
     * echo "Définir un quota:";
     * echo "&nbsp;Nombre de pages: ";
     * echo "<INPUT TYPE=\"texte\" VALUE=\"$nb_pages[$loop]\" NAME=\"pages\" SIZE=\"6\">\n";
     * echo "&nbsp;tous les: ";
     * echo "<INPUT TYPE=\"texte\" VALUE=\"$nb_jours[$loop]\" NAME=\"period\" SIZE=\"5\">\n";
     * echo "&nbsp;jours &nbsp;&nbsp;&nbsp;";
     * echo "<INPUT TYPE=\"hidden\" VALUE=\"$one_printer\" NAME=\"one_printer\">\n";
     * echo "<INPUT TYPE=\"submit\" VALUE=\"Valider\" NAME=\"quota\">\n";
     * echo "&nbsp;&nbsp;";
     * echo "<INPUT TYPE=\"submit\" VALUE=\"Aucun\" NAME=\"quota\">\n";
     * echo "</FORM>\n";
     */
    echo "<HR>\n";
}

$html = "";
admin_footer_html($html);
echo $html;
?>