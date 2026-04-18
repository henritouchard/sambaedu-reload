<?php

/**

 * Liste les imprimantes de sambaedu
 * @Projet SambaEdu
 *
 * @Auteurs Equipe Sambaedu
 *
 * @Licence Distribue sous la licence GPL

 * @note

 */

/**
 *
 * @Repertoire: printers/
 * file: list_printers.php
 *
 */

// Liste des imprimantes
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
$login = $config['login'];
require_once "ldap.inc.php";
include "printers.inc.php";
include "ihm.inc.php"; // pour is_admin()
require_once "partages.inc.php";


$view = $_POST['view'] ?? "v_parc";
// /////////// acls
$acls = array(
    "user::rwx",
    "user:www-admin:rwx",
    "group:domain\\040users:r-x",
    "group:domain\\040admins:rwx",
    "group:domain\\040computers:r-x",
    "mask::rwx",
    "other::---",
    "default:user::rwx",
    "default:user:www-admin:rwx",
    "default:group:domain\\040users:r-x",
    "default:group:domain\\040admins:rwx",
    "default:group:domain\\040computers:r-x",
    "default:mask::rwx",
    "default:other::---"
);
if (! check_acls("/var/lib/samba/printers", $acls) || ($config['acl_init'] != 1)) {
    set_acls("/var/lib/samba/printers", $acls);
}

if ((have_right($config, SE_COMPUTER_ADMIN)) and ($login != "admin")) {
    echo "<H1>Liste des imprimantes</H1>";
    $parc_name = list_parcs($config, $_SERVER['REMOTE_ADDR']);
    // if ($parc_name!="") {
    if (count($parc_name) > 0) {
        // echo "<H5>Votre machine (IP = ".($_SERVER['REMOTE_ADDR']).") est dans le parc : $parc_name </H5> ";
        echo "<H5>Votre machine (IP = " . ($_SERVER['REMOTE_ADDR']) . ") ";
        if (count($parc_name) == 1) {
            echo "est dans le parc :" . $parc_name[0]['name'] . " </H5> ";
        } else {
            echo "est dans les parcs :" . $parc_name[0]['name'];
            for ($i = 1; $i < count($parc_name); $i ++) {
                echo ", " . $parc_name[$i]['name'];
            }
            echo " </H5> ";
        }

        echo "<TABLE BORDER=0>\n";
        echo "<HR>";
        $all_groups_printers = apcu_fetch('list_group_printer');
        if (empty($all_groups_printers)) {
            $all_groups_printers = list_group_printers($config, "");
            apcu_store('list_group_printer', $all_groups_printers, 300);
        }

        for ($i = 0; $i < count($parc_name); $i ++) {
            // echo " La machine est dans le parc ".$parc_name;
            // echo "<TR><TD WIDTH=200 BGCOLOR=\"cornflowerblue\"><B>$parc_name</B></TD></TR>";
            echo "<TR><TD WIDTH=200 BGCOLOR=\"cornflowerblue\"><B>$parc_name[$i]</B></TD></TR>\n";
            // $printers_parc=printers_members($parc_name,"parcs",1);

            // $printers_parc = list_parc_printers($config, $parc_name[$i]); //****************** Cette fonction n'existe pas, probablement list_group_printers ==> REPLACÉE ci-dessous
            $printers_parc = $all_groups_printers[array_search($parc_name[$i], array_column($all_groups_printers, "name"))]['printers'];

            $nb_printers_parc = count($printers_parc);
            for ($j = 0; $j < $nb_printers_parc; $j ++) {
                $p = $printers_parc[$j];
                $sys = $p['etat'];
                if ($sys != "idle")
                    $status = "OUI";
                else
                    $status = "NON";
                echo "<TR><TD WIDTH=200 BGCOLOR=\"lightsteelblue\"><LI><A href='view_printers.php?one_printer=$printers_parc[$j]'>$printers_parc[$j]</A></LI></TD>";
                echo "<TD><FONT COLOR=\"cornflowerblue\">Travaux en cours=$status\n</FONT></TD></TR>\n";
            }
            echo "<TR><TD HEIGHT=30></TD></TR>\n";
        }
        echo "</TABLE>\n";
    } else {
        echo "<H5>Votre machine (IP = " . ($_SERVER['REMOTE_ADDR']) . ") n'appartient à aucun parc !</H5>\n";
    }
} elseif (have_right($config, SE_COMPUTER_ADMIN) and ($login == "admin")) {
    echo "<H1>Liste des imprimantes</H1>";
    echo "<FORM ACTION=\"list_printers.php\" METHOD=\"post\">";
    if (! isset($view) || ($view == "v_parc")) {
        echo "<INPUT TYPE=\"radio\" NAME=\"view\" VALUE=\"v_parc\" CHECKED>par parc &nbsp&nbsp";
        echo "<INPUT TYPE=\"radio\" NAME=\"view\" VALUE=\"v_printers\">par imprimante &nbsp&nbsp";
    } else {
        echo "<INPUT TYPE=\"radio\" NAME=\"view\" VALUE=\"v_parc\">par parc &nbsp&nbsp";
        echo "<INPUT TYPE=\"radio\" NAME=\"view\" VALUE=\"v_printers\" CHECKED>par imprimante &nbsp&nbsp";
    }
    echo "<INPUT TYPE=\"submit\" VALUE=\"Valider\">";
    echo "<HR>";

    // Par parc
    if ($view != "v_printers") {
        echo "<H3>Classement par parcs de type salle</H3>";
        echo "<TABLE BORDER=0>";
        $printers_parcs = apcu_fetch('list_group_printer');
        if (empty($printers_parcs)) {
            $printers_parcs = list_group_printers($config, "");
            apcu_store('list_group_printer', $printers_parcs, 300);
        }

        $nb_parcs = count($printers_parcs);

        for ($i = 0; $i < $nb_parcs; $i ++) {
            $parc_name = $printers_parcs[$i]['name'];

            // Recherche de l'imprimmante par defaut
            // $imprim_defaut = get_default_printer($parc_name);

            echo "<TR><TH WIDTH=200 BGCOLOR=\"cornflowerblue\">&nbsp;$parc_name</TH><TH BGCOLOR=\"cornflowerblue\">&nbsp;Travaux en cours&nbsp;</TH><TH BGCOLOR=\"cornflowerblue\"> &nbsp;par défaut&nbsp;</TH></TR>";

            $nb_printers_parc = count($printers_parcs[$i]['printers']);

            if ($nb_printers_parc == 0) {
                echo "<TR><td colspan=3><i> Aucune imprimante n'est rattachée à ce parc</i></td></TR>";
            } else {
                foreach ($printers_parcs[$i]['printers'] as $p) {
                    $sys = $p['etat'] ?? "";
                    if ($sys != "idle")
                        $status = "OUI";
                    else
                        $status = "NON";
                    echo "<TR><TD WIDTH=200 BGCOLOR=\"lightsteelblue\"><LI><A href='view_printers.php?one_printer=" . $p['name'] . "'>" . $p['name'] . "</A></LI></TD>"; // ******************************
                    echo "<TD><FONT COLOR=\"cornflowerblue\">$status\n</FONT></TD>";

                    // if ($imprim_defaut == $printers_parc[$j]) {
                    // echo "<TD><img style=\"border: 0px solid ;\" src=\"../elements/images/enabled.png\" title=\"par defaut\" alt=\"par defaut\" ></TD>";
                    // } else {
                    echo "<TD></TD>";
                    // }

                    echo "</TR>";
                }
            }
            echo "<TR><TD HEIGHT=30></TD></TR>";
        }
        echo "</TABLE>";

        // par imprimante
    } elseif ($view == "v_printers") {
        echo "<H3>Classement par imprimante</H3>";
        echo "<TABLE BORDER=0>";
        $printers_parc = apcu_fetch('list_printer_group');
        if (! $printers_parc) {
            $printers_parc = list_printer_groups($config);
            apcu_store('list_printer_group', $printers_parc, 300);
        }

        $printers_parc = list_printer_groups($config);
        $nb_printers = count($printers_parc);

        for ($i = 0; $i < $nb_printers; $i ++) {
            $parc_trouve[$i] = false; // On considere au prealable qu'une imprimante n'appartient a aucun parc
            $printer_name = $printers_parc[$i]['printer']['name']; // *********************
            $printer_uri = $printers_parc[$i]['printer']['url'];
            $sys = $printers_parc[$i]['printer']['etat']; // *********************************
            if ($sys != "idle")
                $status = "OUI";
            else
                $status = "NON";

            echo "<TR><TD WIDTH=200 BGCOLOR=\"cornflowerblue\"><A href='view_printers.php?one_printer=" . $printer_name . "'><font color=\"black\"><B>" . $printer_name . "</B></font></A></TD>";
            echo "<TD>URI=" . $printer_uri . "\n</TD>";
            echo "<TD>Travaux en cours=" . $status . "\n</TD></TR>";

            if (count($printers_parc[$i]['groups']) > 0) {
                foreach ($printers_parc[$i]['groups'] as $group) {
                    echo "<TR><TD WIDTH=200 BGCOLOR=\"lightsteelblue\">" . $group['name'] . "\n</TD></TR>";
                    $parc_trouve[$i] = true; // l'imprimante appartient au moins a un parc
                }
            }

            echo "<TR><TD HEIGHT=30></TD></TR>";
        }
        echo "</TABLE>";

        // Affichage des imprimantes qui ne font pas partie d'un parc.

        $n = 0; // on fait l'affichage s'ils existent des imprimantes sans parc
        for ($i = 0; $i < $nb_printers; $i ++) {
            if ($parc_trouve[$i] == false) {
                $n = $n + 1;
            }
        }
        if ($n != 0) {
            echo "<BR><BR><HR>";
            echo "<H4><FONT COLOR=\"red\"><BLINK>Les imprimantes suivantes n'appartiennent à aucun parc :</BLINK></FONT></H4>";
            for ($i = 0; $i < $nb_printers; $i ++) {
                if ($parc_trouve[$i] == false) {
                    echo "<FONT COLOR=\"red\">";
                    // echo "{$all_printers[$i]['name']}";
                    echo "{$printers_parc[$i]['printer']['name']}"; // **********************
                    echo "</FONT>";
                    echo "<BR>";
                }
            }
        }
    }
}

$html = "";
admin_footer_html($html);
echo $html;
?>