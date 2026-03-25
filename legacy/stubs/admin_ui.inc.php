<?php

/**
 * Stub admin_ui.inc.php — remplace le chrome legacy (header, topbar, menu, footer)
 * par des fonctions vides quand un module est exécuté en mode embed.
 *
 * Le layout SER (sidebar, navbar, etc.) est fourni par la vue Blade appelante.
 */

if (!function_exists('admin_header_html')) {
    function admin_header_html($config, &$html, $checks = false): void
    {
        $html = ''; // Pas de <head> legacy — le layout SER s'en charge
    }
}

if (!function_exists('admin_topbar_html')) {
    function admin_topbar_html($config, &$html): void
    {
        $html .= ''; // Pas de topbar legacy
    }
}

if (!function_exists('admin_menu_html')) {
    function admin_menu_html($config, &$html, $refresh = false): void
    {
        $html .= ''; // Pas de sidebar legacy
    }
}

if (!function_exists('admin_footer_html')) {
    function admin_footer_html(&$html, $retour = true): void
    {
        $html .= ''; // Pas de footer legacy
    }
}
