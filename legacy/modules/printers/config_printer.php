<?php

/**

 * Ajout d'une nouvelle imprimante

 * @Projet SambaEdu
 * @Auteurs Equipe Sambaedu
 * @Licence Distribue sous la licence GPL

 * @note 

 */

/**
 *
 * @Repertoire: printers/
 * file: config_printer.php
 *
 */

// Configuration d'un nouvelle imprimante
// Ecriture dans CUPS
include "config.inc.php";
require "ldap.inc.php";
require_once("functions.inc.php");
require_once("traitement_data.inc.php");
$config = get_config();
include 'admin_ui.inc.php';
$html = "";
admin_header_html($config, $html);
admin_topbar_html($config, $html);
admin_menu_html($config, $html);
$html .= header_authorize($config);
echo $html;
require_once "ldap.inc.php"; //
include "ihm.inc.php"; // pour enleveaccents();
include "printers.inc.php";
require_once "partages.inc.php";

$nom = $_POST['nom'] ?? $_GET['nom'] ?? "";
if (! empty($nom)) {
    $nom = add_hostname_suffix($config, $nom);
}
$lieu = $_POST['lieu'] ?? "";
$info = $_POST['info'] ?? "";
$ppd = $_POST['ppd'] ?? "";
$uri = $_POST['uri'] ?? "socket://";
$new = $_POST['new'] ?? false;
$driver = $_POST['driver'] ?? "";
$newprinter = $_GET['newprinter'] ?? "";
if ($newprinter != "") {
    $newname = $_GET['name'] ?? "";
    $uri = "socket://$newprinter:9100";
    $new = true;
}
if (! have_right($config, SE_ADMIN)) {
    die("<div class=error_msg>Cette application, nécessite les droits d'administrateur du serveur Se4 !</div>");
}

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
if (! check_acls("/var/lib/samba/printers", $acls) || ($config['acl_init'] != 1))
    set_acls("/var/lib/samba/printers", $acls);

// Affichage de la page de saisie des parametres l'imprimante
if (empty($nom) && ! $new) {
    // Affichage de la page de selection des imprimantes a modifier.

    $mp_all = array();
    foreach (list_printers($config, "", true) as $p) {
        $mp_all[] = $p['name'];
    }
    if (count($mp_all) > 0) {
        echo "<H1>Sélection des imprimantes à modifier</H1>";
        // Filtrage des noms
        // Affichage de la boite de saisie du nom d'imprimante a filtrer
        echo "<FORM ACTION=\"config_printer.php\" METHOD=\"post\">\n";
        echo "<P>Sélectionnez l'imprimante à modifier</P>\n";
        echo "<P><SELECT SIZE=\"1\" onchange=submit() NAME=\"nom\" >\n";
        echo "<OPTION VALUE=\"\">Imprimante...";
        for ($loop = 0; $loop < count($mp_all); $loop++) {
            echo "<OPTION VALUE=\"" . $mp_all[$loop] . "\">" . $mp_all[$loop] . "";
        }
        echo "</SELECT></P>\n";
        echo "</FORM>\n";
    }
    echo "<H1>Ajout d'une imprimante</H1>\n";
    echo "<FORM ACTION=\"config_printer.php\" METHOD=\"post\">\n";
    echo "<INPUT TYPE=\"hidden\" NAME=\"new\" VALUE=\"true\">\n";
    echo "<INPUT TYPE=\"submit\" VALUE=\"Ajouter\" NAME=\"ajouter\">\n";
    echo "</FORM>\n";
} elseif (empty($nom) || empty($uri) || empty($info)) {

    echo "<H1>Configuration de l'imprimante</H1>\n";
    $smb_ready = false;
    if (! empty($nom)) {
        $printer_exist = list_printers($config, $nom, false);
        if (count($printer_exist) == 1) {
            $uri = $printer_exist[0]['url'] ?? "";
            $info = $printer_exist[0]['description'] ?? "";
            $lieu = $printer_exist[0]['location'] ?? "";
            $driver = $printer_exist[0]['smb_driver'] ?? "";
            $smb_ready = $printer_exist[0]['smb_ready'] ?? false;
            $model = $printer_exist[0]['model'] ?? "";
        }
    }
    // Affichage du formulaire
    echo "<FORM NAME = \"auth\" ACTION=\"config_printer.php\" METHOD=\"post\">\n";
    echo "<TABLE BORDER=\"0\">\n";
    echo "<TR>\n";
    echo "<TD>Nom :</TD>\n";

    // Si une modif on ne peut pas changer le nom
    if (! empty($nom)) {
        echo "<INPUT TYPE=\"hidden\" NAME=\"nom\" VALUE=\"$nom\">\n";
        echo "<TD COLSPAN=\"2\" VALIGN=\"top\">$nom</TD>\n";
        echo "<TD><u onmouseover=\"return escape('Le nom de l\'imprimante ne peut pas être changé..<br>Pour pouvoir le faire vous devez supprimer et recréer l\'imprimante')\"><img name=\"action_image2\"  src=\"../elements/images/system-help.png\"></u></TD>\n";
    } else {
        echo "<TD COLSPAN=\"2\" VALIGN=\"top\"><INPUT TYPE=\"text\" MAXLENGTH=\"15\" SIZE=\"15\" NAME=\"nom\" VALUE=\"$newname\"></TD>\n";
        echo "<TD><u onmouseover=\"return escape('Indiquer un nom pour l\'imprimante.<BR>Celui-ci doit être unique et limité à 15 caractères')\"><img name=\"action_image2\"  src=\"../elements/images/system-help.png\"></u></TD>\n";
    }
    echo "</TR>\n";

    echo "<TR>\n";
    echo "<TD>URI :</TD>\n";
    echo "<TD COLSPAN=\"2\" VALIGN=\"top\"><INPUT TYPE=\"text\" SIZE=\"30\" NAME=\"uri\" VALUE=\"$uri\"></TD>\n";
    echo "<TD><u onmouseover=\"return escape('Indiquer l\'URI de l\'imprimante sous la forme <b>socket://ip_imprimante:9100</b> <BR>Par exemple socket://172.16.100.113:9100')\"><img name=\"action_image2\"  src=\"../elements/images/system-help.png\"></u></TD>\n";

    echo "</TR>\n";
    echo "<TR>\n";
    echo "<TD>Emplacement :</TD>\n";
    echo "<TD COLSPAN=\"2\" VALIGN=\"top\"><INPUT TYPE=\"text\" SIZE=\"20\" NAME=\"lieu\" VALUE=\"$lieu\"></TD>\n";
    echo "<TD><u onmouseover=\"return escape('Indiquer le lieu d\'installation de l\imprimante.<br>Cette information n\'est qu\'indicative.')\"><img name=\"action_image2\"  src=\"../elements/images/system-help.png\"></u></TD>\n";
    echo "</TR>\n";
    echo "<TR>\n";
    echo "<TD>Description :</TD>\n";
    echo "<TD COLSPAN=\"2\" VALIGN=\"top\"><INPUT TYPE=\"text\"  SIZE=\"20\" NAME=\"info\" VALUE=\"$info\"></TD>\n";
    echo "<TD><u onmouseover=\"return escape('Description obligatoire')\"><img name=\"action_image2\"  src=\"../elements/images/system-help.png\"></u></TD>\n";
    echo "</TR>\n";
    echo "<TR>\n";
    echo "<TD>Pilote Linux :</TD>\n";
    $ppds = list_cups_drivers();
    if (empty($ppd) && ! empty($model)) {
        $ppd = $ppds[$model];
    }
    echo "<TD><SELECT NAME=\"ppd\" SIZE=\"1\">\n";
    foreach ($ppds as $m => $p) {
        echo "<OPTION VALUE=\"$p\"";
        if ($m == $model) {
            echo " selected";
        }
        echo ">$m</OPTION>\n";
    }
    echo "</SELECT></TD>\n";
    echo "</TR>\n";
    echo "<TR>\n";
    echo "<TD>Pilote Windows :</TD>\n";
    echo "<TD COLSPAN=\"2\" VALIGN=\"top\">";
    if (! $smb_ready) {
        $control = "disabled";
        $texte = "Attention l'imprimante n'est pas encore prête, veuillez patienter pour choisir le pilote<br>";
        $driver = "Attente...";
    } else {
        $control = "";
        $texte = "";
    }

    echo "<SELECT NAME=\"driver\" SIZE=\"1\" " . $control . ">\n";
    $drivers = array_merge(list_smb_drivers($config), array(
        "Aucun (impression Linux uniquement)",
        "Attente..."
    ));
    // $driver = $driver ?? "Aucun (impression Linux uniquement)";
    foreach ($drivers as $d) {
        echo "<OPTION VALUE=\"$d\"";
        if ($d == $driver)
            echo " selected";
        echo ">$d</OPTION>\n";
    }
    echo "</SELECT></TD>\n";
    echo "<TD>" . $texte . "<u onmouseover=\"return escape ('Pilotes déployés automatiquement par GPO pour les postes windows.<br><b>Attention</b> : vous devez les injecter au préalable depuis un poste Windows avec la console MMC afin de pouvoir en disposer ici (voir la documentation).')\"><img name=\"action_image2\"  src=\"../elements/images/system-help.png\"></u></TD>\n";

    echo "</TR>\n";
    echo "</TABLE>\n";
    echo "<BR><BR>\n";
    echo "<INPUT TYPE=\"submit\" VALUE=\"Valider\"><BR>\n";
    echo "</FORM>\n";
    $printer = array();
    if (! parse_printer_uri($uri, $printer)) {
        echo "<div class='error_msg'>Vous devez saisir une URI valide !</div><BR>\n";
    }
} else {
    // Affichage de la page de confirmation de l'installation de l'imprimante
    if ($driver == "Aucun (impression Linux uniquement)")
        $driver = "";
    $ReturnValue = add_printer($config, $nom, $uri, $info, $lieu, $driver, $ppd);

    // Compte rendu de creation
    if ($ReturnValue) {
        echo "L'imprimante <B>$nom</B> a été reconfigurée avec succès<BR>";
        echo "<br><center>";
        echo "<a href=view_printers.php?one_printer=$nom>Retour</a>";
        echo "</center>";
    } else {
        echo "<div class='error_msg'>Erreur lors de la modification de l'imprimante <B>$nom</B><font color='black'>(type d'erreur : $ReturnValue) </font>,veuillez contacter l'administrateur du système</div><BR>\n";
    }
}

$html = "";
admin_footer_html($html);
echo $html;
