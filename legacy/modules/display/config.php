<?php
/**
 * Configuration de l'ffichage dynamique
 * @Version $Id$ 
 * @Projet  SambaEdu
 * @Auteurs Equipe Sambaedu
 * @Licence Distribue sous la licence GPL
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
include "ihm.inc.php";
include "display.inc.php";
if (! have_right($config, SE_ADMIN))
    exit();
echo "<h1>Configuration de l'affichage dynamique</h1>\n";
echo "<h1>Ajout d'un flux d'informations :</h1>\n";
echo form_choose_info(); // nom du flux + durée d'affichage par news + nombre de news + image de fond + image d'intro. Lors de la saisi du premier flux, l'url du diaporama (précédemment paramétré éventuellement) est supprimé, lors de la saisi du 2e flux, il s'ajoute au premier.
echo " Exemple : 
        <ul>
        <li> Pour les flux du monde on ajoutera : https://www.lemonde.fr/rss/en_continu.xml,</li>
        <li> les flux d'un site SPIP d'établissement peuvent-être adaptés pour n'afficher que ceux qui correspondent à un mot clé,</li>
        <li> en plus des flux, les images déposées dans <b>Docs/images/</b> seront lues en plein écran dans l'ordre alphabétique. Vous pouvez donner les droits en écriture pour ce répertoire aux utilisateurs autorisés.</li> 
        </ul>";
echo "<h1>Ajout d'un diaporama public :</h1>\n";
echo form_diaporama_public(); // renseigner l'URL du diaporama public à afficher, si une url est saisie tous les flux d'infos sont supprimés.
echo " Les télés connectés, les mini-pc, peuvent ensuite être configurés en mode kiosque et ouvrir un navigateur en plein écran à l'adresse : <a href=\"http://" . $config['se4fs_name'] . "/display/\">http://" . $config['se4fs_name'] . "/display/</a>.<br /> Si le site utilisé pour diffuser votre diaporama affiche une bannière demandant l'autorisation des cookies, vous pouvez utiliser un blockeur de publicité (AdBlocker Ultimate sur Firefox par exemple) afin de supprimer ces bannières. </br> L'association SambaÉdu propose des images disque pour les RaspberryPi. N'hésitez pas à demander conseil à l'association SambaÉdu.";
$html = "";
admin_footer_html($html);
echo $html;
?>
