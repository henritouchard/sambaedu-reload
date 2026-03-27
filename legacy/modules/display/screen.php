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

if (! have_right($config, SE_ADMIN))
    exit();

echo "<h1>Configuration des écrans</h1>\n";
echo "<b>En cours de développement....</b>\n";
echo "<b>Ici, vous pourrez configurer les heures d'allumage et d'extinction des écrans compatibles.</b>\n";

$html = "";
admin_footer_html($html);
echo $html;

?>
