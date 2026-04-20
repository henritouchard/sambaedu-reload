<?php
/**
 * Stub bbb.inc.php — guard idempotent.
 *
 * bbb.inc.php (sambaedu/includes/, 821 L) ne protège pas ses fonctions avec
 * if (!function_exists()) — il peut être inclus plusieurs fois dans une même
 * session PHP (PHPUnit ou bootstrap partagé entre tests) et produit alors :
 *   Fatal error: Cannot redeclare config_bbb()
 *
 * Ce stub est prioritaire dans l'include_path (legacy/stubs/ est prépendé
 * par le bootstrap). Il charge le fichier original via require_once (idempotent)
 * et garantit que les fonctions BBB ne sont déclarées qu'une seule fois.
 *
 * Story : 1bis-17 — module BBB
 */

if (defined('LEGACY_BBB_INC_LOADED')) {
    return;
}
define('LEGACY_BBB_INC_LOADED', true);

$_bbb_inc_path = config('sambaedu.legacy_path', '/var/www/sambaedu') . '/includes/bbb.inc.php';
if (file_exists($_bbb_inc_path)) {
    require_once $_bbb_inc_path;
}
unset($_bbb_inc_path);
