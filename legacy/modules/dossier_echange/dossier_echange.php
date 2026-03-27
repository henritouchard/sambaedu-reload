 <?php

/**

 * Gestion des dossiers /var/sambaedu/Classes/Classe_XXX/_echange
 * @Version  10 - 2020

 * @Projet SambaEdu

 * @auteurs Equipe SambaÉdu

 * @note

 * @Licence Distribue selon les termes de la licence GPL
 */

/**
 *
 * @Repertoire  dossier_echange/
 * file dossier_echange.php
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
require_once "ldap.inc.php";
require_once "ihm.inc.php";
require_once "samba.inc.php";
require_once "partages.inc.php";

foreach ($_POST as $cle => $val) {
    $$cle = $val;
}
// debug_var();

// Pour tenir compte des essais...
$nom_de_la_page = "dossier_echange.php";

echo "<h1>Gestion des dossiers d'échange</h1>";

// if (have_right($config, SE_ADMIN)) {

$admin_delegation = have_right($config, SE_SHARE_ADMIN);
$admin_se = have_right($config, SE_ADMIN);

// Recherche des ressources classes existantes
if ($admin_se) {

    // ouverture du repertoire Classes
    $loop = 0;
    $repClasses = dir("/var/sambaedu/Classes/");
    // recuperation de chaque entree
    while ($ressource = $repClasses->read()) {
        if (preg_match("/^Classe_(.*)$/", $ressource, $m)) {
            $list_ressources[$loop] = $m[1];
            $loop ++;
        }
    }
    $repClasses->close();
} elseif ($admin_delegation) {
    include ("fonc_outils.inc.php");
    $list = list_classes($config, $login);
    foreach ($list as $value) {
        $list_ressources[] = $value;
    }
} else {
    die("Vous n'avez pas les droits suffisants pour accéder à cette fonction</BODY></HTML>");
}
// Fin Recherche des ressources classes existantes

sort($list_ressources, SORT_NATURAL | SORT_FLAG_CASE);
// var_dump($list_ressources);

// Presentation de la liste des ressources disponibles

// Le choix des classes a traiter est-il fait?
if (! isset($choice_done)) {
    // echo "<H3>Liste des ressources Classes disponibles sur le serveur $cn_srv</H3>\n";
    echo "<H3>Création/Activation/Désactivation des dossiers _echange sur le serveur</H3>\n";
    if (count($list_ressources) == 0) {
        echo "<P>Il n'y a pas de ressources Classes sur ce serveur !</P>\n";
    } else {
        if (count($list_ressources) > 10)
            $size = 10;
        else
            $size = count($list_ressources);
        // echo "<h4>Création/Activation/Désactivation des dossiers _echange</h4>";
        // echo "<form>\n";
        echo "<form action=\"$nom_de_la_page\" method=\"post\">\n";
        // Affichage liste des ressources disponibles
        /*
         * echo "<select size=\"".$size."\" name=\"list_classes[]\" multiple=\"multiple\">\n";
         * for ($loop=0; $loop<count($list_ressources);$loop++) {
         * echo "<option value=".$list_ressources[$loop].">".$list_ressources[$loop]."\n";
         * }
         * echo "</select><br>\n";
         */

        /*
         * //AJOUT MODIF
         */
        echo "<p>Les boutons sont placés dans l'état actuel.<br>\n";
        echo "Seules les classes pour lesquelles vous modifierez le choix seront affectées.<br>\n";
        echo "<table class='table-bordered'>";
        echo "<tr class=\"menuheader\" height=\"30\" style=\"font-weight:bold;\" align=\"center\">";
        echo "<td>Classe</td>";
        echo "<td>Etat actuel</td>";
        echo "<td>Actif</td>";
        echo "<td>Verrouillé</td>";
        // echo "<td>Réactiver<br>automatiquement<br>l'accès<br> après...</td>";
        echo "</tr>\n";
        for ($loop = 0; $loop < count($list_ressources); $loop ++) {
            $echange = "/var/sambaedu/Classes/Classe_" . $list_ressources[$loop] . "/_echange";
            $resultat = "none";
            if (is_dir($echange)) {
                $acl = get_facl($echange);
                // var_dump($acl);
                $mode = "";
                if (isset($acl[strtolower("classe_" . $list_ressources[$loop])])) {
                    $mode = $acl[strtolower("classe_" . $list_ressources[$loop])]['mode'];
                }
                if ($mode == "rwx") {
                    $resultat = "actif";
                } else {
                    $resultat = "verrouille";
                }
            }
            // Si actif
            $color_line = "";
            $pre_selectionne = "";
            // echo "Valeur de resultat : $resultat";
            // var_dump($resultat);
            if ("$resultat" == "actif") {
                $color_line = " bgcolor=\"#00FF00\"";
                $pre_selectionne = " checked=\"true\"";
            }
            if ("$resultat" == "verrouille") {
                $color_line = " bgcolor=\"#FF6666\"";
                $pre_selectionne = " checked=\"true\"";
            }
            // var_dump($resultat);
            // var_dump($color_line);
            // var_dump($pre_selectionne);

            echo "<tr align=\"center\" $color_line>\n";
            echo "<td>$list_ressources[$loop]<input type=\"hidden\" name=\"list_classes[$loop]\" value=\"" . urlencode($list_ressources[$loop]) . "\"></td>\n";
            echo "<td>$resultat<input type=\"hidden\" name=\"etat_actuel[$loop]\" value=\"$resultat\"></td>\n";

            echo "<td><input type=\"radio\" name=\"activate[$loop]\" value=\"actif\" $pre_selectionne></td>\n";

            $pre_selectionne = "";
            if ("$resultat" == "verrouille") {
                $pre_selectionne = " checked=\"true\"";
            }
            echo "<td><input type=\"radio\" name=\"activate[$loop]\" value=\"verrouille\" $pre_selectionne></td>\n";

            // Delai:
            // echo "<td>\n";
            // echo "<input type=\"checkbox\" name=\"delai[$loop]\" value=\"oui\" >\n";
            // echo "<select name=\"heures[$loop]\">\n";
            // for($i=0;$i<=12;$i++){
            // echo "<option value=\"$i\">$i</option>\n";
            // }
            // echo "</select> H \n";
            // echo "<select name=\"minutes[$loop]\">\n";
            // for($i=0;$i<=55;$i=$i+5){
            // echo "<option value=\"$i\">$i</option>\n";
            // }
            // echo "</select> MIN \n";
            // echo "</td>\n";
            //

            // echo "<input type=\"text\" name=\"minutes\" value=\"5\">minutes</td>";
            echo "</tr>\n";
            unset($resultat);
        }
        echo "</table>\n";
        /*
         * //FIN MODIF
         */

        echo "<input type=\"hidden\" name=\"choice_done\" value=\"true\">\n";
        // echo "Activer: <input type=\"radio\" name=\"activate\" value=\"yes\" checked> / \n";
        // echo "<input type=\"radio\" name=\"activate\" value=\"no\">: Désactiver<BR>\n";
        echo "<input type=\"submit\" value=\"Envoyer\">";
        echo "</form>\n";
    }
} else {
    // PARTIE ACTION:
    // Le choix des classes a traiter a ete effectue dans le formulaire ci-dessus.
    // echo "<p>activate=$activate</p>\n";
    echo "<h3>Traitement des dossiers _echange</h3>\n";
    for ($loop = 0; $loop < count($list_classes); $loop ++) {
        // echo "<p>".count($list_classes)."</p>\n";
        // if("$list_classes[$loop]"!=""){

        if (isset($activate[$loop])) {
            if ("$etat_actuel[$loop]" != "$activate[$loop]") {
                if ("$activate[$loop]" != "") {
                    $grp_class = strtolower(urldecode($list_classes[$loop]));
                    $echange = "/var/sambaedu/Classes/Classe_" . urldecode($list_classes[$loop]) . "/_echange";
                    if ("$activate[$loop]" == "actif") {
                        echo update_classes($config, urldecode($list_classes[$loop]), true, true);
                    } else {
                        echo update_classes($config, urldecode($list_classes[$loop]), true, false);
                    }
                    // echo "Lancement de l'action.<br>\n";
                    batch_write("fast");
                    echo "</p>\n";
                    system("/usr/bin/sudo /tmp/partages.sh");
                } else {
                    // Pas de modification pour $list_classes[$loop]
                    // parce que le dossier n'est pas encore initialies
                    // et qu'aucune case n'etait selectionnee.
                    echo "\n";
                }
            } // fin test pb
            else {
                // Pas de modification pour cette classe
                // (le bouton radio d'activation/verrouillage n'a pas ete deplace).
                echo "\n";
            } // fin test pb
        }
    }
    echo "<p><a href=\"dossier_echange.php\">Retour au menu 'Dossier _echange'</a></p>\n";
}

// } // Fin if is_admin
$html = "";
admin_footer_html($html);
echo $html;
?>