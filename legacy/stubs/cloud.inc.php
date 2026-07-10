<?php
/**
 * Stub cloud.inc.php — IN-REPO (Story 38.4, D6).
 *
 * `dhcp/dnsupdate.php` fait `require "cloud.inc.php"`. Aucune fonction cloud
 * n'est appelée par bbb/dhcp (inventaire constaté) : stub inerte guardé,
 * présent pour que le require résolve in-repo (plus AUCUN /var/www/sambaedu).
 * Le portage cloud/NextCloud est traité par le plan fichiers (hors scope 38.4).
 */

if (defined('LEGACY_CLOUD_INC_LOADED')) {
    return;
}
define('LEGACY_CLOUD_INC_LOADED', true);
