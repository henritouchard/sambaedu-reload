<?php
/*
 * L'administrateur indique
 * les variables d'environnement :
 * BBB_SECRET and BBB_SERVER_BASE_URL
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
include "bbb.inc.php";

if (have_right($config, SE_ADMIN)) {
    $action = $_POST['valider'] ?? "";
    $suppr = $_POST['suppr'] ?? "";
    /**
     * Si on a ajouté modifié ou supprimé un serveur
     */
    if (! empty($action) || ! empty($suppr)) {
        $bbb_secret = $_POST['bbb_secret'] ?? "";
        $bbb_url = $_POST['bbb_url'] ?? "";
        $bbb_scalelite = $_POST['bbb_scalelite'] ?? 0;

        /**
         * Suppression d'un serveur
         */
        if (is_array($suppr)) {
            foreach ($suppr as $key => $value) {
                unset($bbb_secret[$key]);
                unset($bbb_url[$key]);
                unset($bbb_scalelite[$key]);
            }
        }
        /**
         * Ajout ou modif
         */
        foreach ($bbb_url as $key => $value) {
            $bbb_secret[$key] = trim($bbb_secret[$key]);
            $bbb_url[$key] = trim($bbb_url[$key]);
            if ((strlen($value) < 10) or (strlen($bbb_secret[$key]) < 10)) {
                unset($bbb_secret[$key]);
                unset($bbb_url[$key]);
            }
        }
        $bbb_secret = implode(',', $bbb_secret);
        $bbb_url = implode(',', $bbb_url);
        $bbb_scalelite = implode(',', $bbb_scalelite);

        set_config($config, 'bbb_secret', $bbb_secret, 'bbb');
        set_config($config, 'bbb_server_base_url', $bbb_url, 'bbb');
        set_config($config, 'bbb_server_scalelite', $bbb_scalelite, 'bbb');
        $config = get_config($config, true);
    } else {
        $bbb_secret = $config['bbb_secret'] ?? '';
        $bbb_url = $config['bbb_server_base_url'] ?? '';
        $bbb_scalelite = $config['bbb_server_scalelite'] ?? '';
    }
    print form_config_bbb($bbb_url, $bbb_secret, $bbb_scalelite);
} else {
    print("Vous n'avez pas les droits nécessaires pour ouvrir cette page...");
}

// Footer
$html = "";
admin_footer_html($html);
echo $html;
?>