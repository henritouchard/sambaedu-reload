<?php
/**
 * Stub traitement_data.inc.php — AUTONOME IN-REPO (Story 38.4, D6).
 *
 * L'original (sambaedu/includes/traitement_data.inc.php) purifiait $_GET/$_POST
 * via HTMLPurifier chargé depuis `sambaedu/vendor/autoload.php`. Story 38.4 :
 * plus AUCUNE délégation vers `/var/www/sambaedu` ni `../../sambaedu/`.
 *
 * Si HTMLPurifier est disponible dans le vendor SE5 (déjà autoloadé par le
 * bootstrap), on purifie ; sinon no-op silencieux (les entrées transitent par
 * le contrôleur Laravel + les shims, la purification legacy n'est plus le seul
 * rempart). Guard idempotent.
 */

if (defined('LEGACY_TRAITEMENT_DATA_LOADED')) {
    return;
}
define('LEGACY_TRAITEMENT_DATA_LOADED', true);

if (! class_exists(\HTMLPurifier::class)) {
    // HTMLPurifier indisponible : purification legacy désactivée (no-op).
    return;
}

$__purifConfig = \HTMLPurifier_Config::createDefault();
$__purifConfig->set('Core.Encoding', 'utf-8');
$__purifConfig->set('HTML.Doctype', 'XHTML 1.0 Strict');
$__purifier = new \HTMLPurifier($__purifConfig);

foreach ([&$_GET, &$_POST] as &$__superglobal) {
    foreach ($__superglobal as $__key => $__value) {
        $__testKey = $__purifier->purify($__key);
        if ($__key !== $__testKey) {
            unset($__superglobal[$__key]);
            continue;
        }
        if (! is_array($__value)) {
            $__superglobal[$__key] = $__purifier->purify($__value);
        } else {
            foreach ($__value as $__k2 => $__v2) {
                $__tk2 = $__purifier->purify($__k2);
                if ($__k2 !== $__tk2) {
                    unset($__superglobal[$__key][$__k2]);
                } elseif (! is_array($__v2)) {
                    $__superglobal[$__key][$__k2] = $__purifier->purify($__v2);
                }
            }
        }
    }
}
unset($__superglobal, $__purifConfig, $__purifier);
