<?php

/**
 * Stub ihm.inc.php — shadow du legacy sambaedu/includes/ihm.inc.php.
 *
 * Ce stub résout le problème de redéclaration des fonctions de ihm.inc.php
 * dans le contexte PHPUnit où plusieurs tests s'exécutent dans le même process PHP.
 * Plusieurs modules printers font `include "ihm.inc.php"` (sans _once), ce qui
 * cause des "Cannot redeclare" lors de l'exécution de plusieurs tests de suite.
 *
 * Ce stub est prioritaire via l'include_path (stubs/ est prepend). Le guard
 * LEGACY_IHM_INC_LOADED assure que le fichier original n'est chargé qu'une seule
 * fois, même si plusieurs modules font `include "ihm.inc.php"`.
 *
 * Story : 1bis-15-module-printers — résolution collision ihm.inc.php (AC5)
 */

// Guard : ne charger qu'une seule fois (idempotent)
if (defined('LEGACY_IHM_INC_LOADED')) {
    return;
}
define('LEGACY_IHM_INC_LOADED', true);

// Charger le fichier original via chemin absolu (une seule fois, grâce au guard ci-dessus)
$_ihm_legacy_path = config('sambaedu.legacy_path', '/var/www/sambaedu') . '/includes/ihm.inc.php';
if (file_exists($_ihm_legacy_path)) {
    require_once $_ihm_legacy_path;
}
unset($_ihm_legacy_path);
