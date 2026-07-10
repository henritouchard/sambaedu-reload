<?php
/**
 * Stub fonc_parc.inc.php — IN-REPO (Story 38.4, D6).
 *
 * `dhcp/baux.php` fait `require_once "fonc_parc.inc.php"`. Aucune fonction de
 * ce fichier n'est appelée par les modules Tier 3 bbb/dhcp (inventaire
 * constaté) : stub inerte guardé, présent uniquement pour que le require
 * résolve in-repo (plus AUCUN /var/www/sambaedu).
 */

if (defined('LEGACY_FONC_PARC_INC_LOADED')) {
    return;
}
define('LEGACY_FONC_PARC_INC_LOADED', true);

if (!function_exists('ping')) {
    function ping($host, $timeout = 1)
    {
        return false;
    }
}
