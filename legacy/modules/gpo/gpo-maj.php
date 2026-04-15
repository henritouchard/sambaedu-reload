<?php

/**

 * Gestion des gpo pour clients Windows (mise a jour des gpo)


 * @Projet SambaEdu 

 * @auteurs denis.bonnenfant

 * @Licence Distribue selon les termes de la licence GPL

 * @note 

 */
/**
 *
 * @Repertoire: registre
 * file: gpo-maj.php
 *
 */
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
require_once "ihm.inc.php";
require_once "gpo.inc.php";

echo "<h1>Importation des GPOs dans l'AD</h1>";
$imports = $_POST['imports'] ?? array();
$imports_etab = $_POST['imports_etab'] ?? array();

$config = get_config($config, true, true);
// connexion();

if (! have_right($config, SE_COMPUTER_ADMIN)) {  // bug legacy corrigé : était && ! empty($config['etab_ou'])
    die("Vous n'avez pas les droits suffisants pour accéder à cette fonction</BODY></HTML>");
}
// Aide

// if ((count($imports) == 0) && (count($imports_etab) == 0)) {

$msg = [];
echo "<table cellpadding=\"5em\" align=\"center\" valign=\"top\" style=\"width:100%;\">\n";
echo "<tr valign=\"top\">\n";
echo "<td align=\"left\">\n";
echo "<h1>GPO initiales (pour réimport dans l'AD)</h1>";
/*
 * GPO issues du paquet se4
 * dont le fichier est
 * préfixé par se4_*.zip
 *
 */
$import_gpos = list_gpo_templates_git($config);
$nb = count($import_gpos);
if ($nb == 0) {
    // on met a jour les templates si besoin
    exec("sudo apt update && sudo apt install -y sambaedu-gpo-templates");
    $import_gpos = list_gpo_templates();
    $nb = count($import_gpos);
}
if ($nb > 15) {
    $nb = 15;
}
echo "<FORM method=\"post\" action=\"gpo-maj.php\">\n";
echo "<SELECT NAME=\"imports[]\" SIZE=\"" . $nb . "\" multiple>\n";
$local_gpos = read_gpo_json(); // valeur stockée sur le FS lors du dernier import

foreach ($import_gpos as $gpo) {
    $old_version = $local_gpos[$gpo["displayname"]]['version'] ?? false;
    $gpo_version = $gpo['version'];
    if ($gpo["displayname"]=="imprimantes") {
        $texte = $gpo["displayname"] . " (" . gpo_version($gpo["version"])[0] . "." . gpo_version($gpo["version"])[1] . ") Ne pas réimporter sous peine de perdre le paramétrage des imprimantes.";
    } else {
        if ($old_version && (gpo_version($gpo_version)[0] > gpo_version($old_version)[0] || gpo_version($gpo_version)[1] > gpo_version($old_version)[1])) {
            $texte = $gpo["displayname"] . " (" . gpo_version($gpo["version"])[0] . "." . gpo_version($gpo["version"])[1] . ") Version plus récente disponible, import conseillé !";
        } else {
            $texte = $gpo["displayname"] . " (" . gpo_version($gpo["version"])[0] . "." . gpo_version($gpo["version"])[1] . ")";
        }
    }
    echo "<option value=\"" . $gpo["displayname"] . "\"";
    echo ">" . $texte;
    echo "</option>\n";
}
echo "</SELECT>\n";
echo "<p><input type=\"submit\" value=\"Valider\">\n</p>";
echo "</FORM>\n";

echo "</td><td align=\"left\">\n";

/*
 * GPO présentent dans le rep. templates
 * dont le fichier est
 * préfixé par etab_*.zip
 *
 */
echo "<h1>Autres GPO disponibles (issues d'exports ...)</h1>\n";
$gpos = list_gpo_templates_etab();
$nb = count($gpos);
if ($nb != 0) {
    if ($nb > 15) {
        $nb = 15;
    }
    echo "<FORM method=\"post\" action=\"gpo-maj.php\">\n";
    echo "<SELECT NAME=\"imports_etab[]\" SIZE=\"" . $nb . "\" multiple>";

    foreach ($gpos as $gpo) {

        echo "<option value=\"" . $gpo["displayname"] . "\"";
        echo ">" . $gpo["displayname"] . "\n";
        echo " (" . gpo_version($gpo["version"])[0] . "." . gpo_version($gpo["version"])[1] . " ) ";
        echo "</option>\n";
    }
    echo "</SELECT>\n";
    echo "<p><input type=\"submit\" value=\"Valider\">\n</p>";
    echo "</FORM>\n";
}
echo "</td></tr></table>\n";
// }
if (count($imports) != 0) {
    foreach ($imports as $gpo) {
        $res = import_gpo($config, $gpo, "se4_" . $gpo, true, true, true);
        // $msg[$gpo] = "GPO " . $gpo . " : \n";
        $msg[$gpo] = "";
        if ($res) {
            $msg[$gpo] .= "<div style=\"color:Green;\"><b>Importation via Git OK</b></div>\n";
        } else {
            $msg[$gpo] .= "<div style=\"color:Red;\"><b>ERREUR lors de l'importation via Git</b></div>\n";
        }
    }
} elseif (count($imports_etab) != 0) {
    foreach ($imports_etab as $gpo) {
        $res = import_gpo($config, $gpo, "etab_" . $gpo . ".zip", true, false, true);
        // $msg[$gpo] = "GPO établissement " . $gpo . " :<br>\n";
        $msg[$gpo] = "";
        if ($res) {
            $msg[$gpo] .= "<div style=\"color:Green;\"><b>Importation personnalisée OK</b></div>\n";
        } else {
            $msg[$gpo] .= "<div style=\"color:Red;\"><b>ERREUR lors de l'importation personnalisée</b></div>\n";
        }
    }
}

// Liste des GPO déjà présentes sur l'AD
$gpos_in_ad = gpogetlink($config, $config['ldap_base_dn']);
usort($gpos_in_ad, 'compare_list_gpo_by_name');
echo "<h1>GPO actuellement présentes sur le serveur Active Directory</h1>";
echo "<ul>";
foreach ($gpos_in_ad as $gpo) {
    $old_version = $local_gpos[$gpo["displayname"]]['version'] ?? false;
    $key = array_search($gpo["displayname"], array_column($import_gpos, "displayname"));
    if ($key !== false) {
        $import_version = $import_gpos[$key]['version'] ?? false;
    } else {
        $import_version = false;
    }
    $gpo_infos = search_ad($config, $gpo["displayname"], "gpo");
    $sysvolini = read_gpo_sysvol($config, $gpo_infos[0], GPT_INI);
    $gpo_version_sysvol = $sysvolini['Version'] ?? 0;
    $gpo_version = $gpo_infos[0]['versionnumber'];
    $msg[$gpo["displayname"]] = $msg[$gpo["displayname"]] ?? "";
    if (gpo_version($gpo_version)[0] != gpo_version($gpo_version_sysvol)[0] || gpo_version($gpo_version)[1] != gpo_version($gpo_version_sysvol)[1]) {
        $colour = "<div style=\"color:Red;display:inline;\">RÉIMPORT OBLIGATOIRE ! AD : " . gpo_version($gpo_version)[0] . "." . gpo_version($gpo_version)[1] . ", 
SYSVOL : " . gpo_version($gpo_version_sysvol)[0] . "." . gpo_version($gpo_version_sysvol)[1] . "</div>";
    } elseif (gpo_version($gpo_version)[0] != gpo_version($gpo_version_sysvol)[0] || gpo_version($gpo_version)[1] != gpo_version($gpo_version_sysvol)[1]) {
        $colour = "<div style=\"color:Red;display:inline;\">RÉIMPORT OBLIGATOIRE ! AD : " . gpo_version($gpo_version)[0] . "." . gpo_version($gpo_version)[1] . ", 
SYSVOL : " . gpo_version($gpo_version_sysvol)[0] . "." . gpo_version($gpo_version_sysvol)[1] . "</div>";
    } elseif ($import_version && $old_version && (gpo_version($import_version)[0] > gpo_version($old_version)[0] || gpo_version($import_version)[1] > gpo_version($old_version)[1])) {
        $colour = "<div style=\"color:Green;display:inline;\">RÉIMPORT CONSEILLE !  AD : " . gpo_version($gpo_version)[0] . "." . gpo_version($gpo_version)[1] . ", 
IMPORT : " . gpo_version($import_version)[0] . "." . gpo_version($import_version)[1] . "</div>";
    } else {
        $colour = "<div style=\"display:inline;\">AD : " . gpo_version($gpo_version)[0] . "." . gpo_version($gpo_version)[1] . "</div>";
    }
    if ($gpo["displayname"]=="imprimantes") {
        $colour = "Ne pas réimporter depuis les GPO initiales sous peine de perdre le paramétrage des imprimantes. " . $colour;
    }
    echo "<li>" . $gpo["displayname"] . " (" . $colour . ")";
    echo $msg[$gpo["displayname"]] . "</li>\n";
}
echo "</ul>\n";

$html = "";
admin_footer_html($html);
echo $html;
