<?php
/**
 * Stub ent.inc.php — IN-REPO (Story 38.4, D6).
 *
 * `dhcp/dnsupdate.php` fait `require_once 'ent.inc.php'`. Aucune fonction ENT
 * n'est appelée par bbb/dhcp (inventaire constaté) : stub inerte guardé,
 * présent pour que le require résolve in-repo (plus AUCUN /var/www/sambaedu).
 * Le portage ENT (sync/import) est une décision produit hors scope (D5/Q2).
 */

if (defined('LEGACY_ENT_INC_LOADED')) {
    return;
}
define('LEGACY_ENT_INC_LOADED', true);
