<?php
/**
 * Stub sites.inc.php — IN-REPO (Story 38.4, D6).
 *
 * `dhcp/config.php` fait `require_once "sites.inc.php"`. Aucune fonction de ce
 * fichier n'est appelée par bbb/dhcp (inventaire constaté) : stub inerte
 * guardé, présent pour que le require résolve in-repo (plus AUCUN
 * /var/www/sambaedu). La gestion multi-sites AWX/HAProxy legacy n'est pas
 * portée (hors scope 38.4).
 */

if (defined('LEGACY_SITES_INC_LOADED')) {
    return;
}
define('LEGACY_SITES_INC_LOADED', true);
