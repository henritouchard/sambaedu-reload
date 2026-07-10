<?php
/**
 * Stub functions.inc.php — IN-REPO (Story 38.4, D6).
 *
 * Les modules Tier 3 (bbb, dhcp) font `require_once "functions.inc.php"`. Ce
 * fichier DOIT exister dans legacy/stubs/ pour que le require résolve in-repo
 * (plus AUCUN /var/www/sambaedu). Il fournit, guardées, les rares fonctions
 * utilitaires globales legacy que ces modules peuvent référencer. Inertes /
 * minimales (D6) — le gros de l'IHM/session est géré par Laravel + les shims.
 */

if (defined('LEGACY_FUNCTIONS_INC_LOADED')) {
    return;
}
define('LEGACY_FUNCTIONS_INC_LOADED', true);

if (!function_exists('remote_ip')) {
    /** IP cliente (parité legacy functions.inc.php:remote_ip). */
    function remote_ip()
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '127.0.0.1';
    }
}

if (!function_exists('getintlevel')) {
    /** Niveau d'interface courant (session). */
    function getintlevel()
    {
        return $_SESSION['level'] ?? 0;
    }
}

if (!function_exists('setintlevel')) {
    function setintlevel($new_level)
    {
        $_SESSION['level'] = $new_level;
    }
}

if (!function_exists('mktable')) {
    /** Génère un tableau HTML minimal (parité signature legacy). */
    function mktable($title, $content)
    {
        return '<table><caption>' . htmlspecialchars((string) $title)
            . '</caption>' . $content . '</table>';
    }
}

if (!function_exists('debug_var')) {
    function debug_var()
    {
        return '';
    }
}
