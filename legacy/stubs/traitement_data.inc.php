<?php
/**
 * Stub anti-collision pour traitement_data.inc.php.
 *
 * L'original (sambaedu/includes/traitement_data.inc.php) charge HTMLPurifier
 * via sambaedu/vendor/autoload.php.
 *
 * Le bootstrap legacy préfixe stubs/ AVANT sambaedu/includes/ dans l'include_path.
 * Sans ce stub conditionnel, l'original ne serait jamais atteint, même sur VM.
 *
 * Ce stub charge l'original SI `sambaedu/vendor/autoload.php` est disponible
 * (cas VM de prod). Sinon, no-op silencieux (cas host/CI sans vendor).
 */

$autoload = __DIR__ . '/../../sambaedu/vendor/autoload.php';
$original = __DIR__ . '/../../sambaedu/includes/traitement_data.inc.php';

if (is_file($autoload) && is_file($original)) {
    require_once $autoload;
    require_once $original;
}
// else: no-op — HTMLPurifier indisponible en local host, purification désactivée.
