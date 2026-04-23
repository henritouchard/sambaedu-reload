<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Empêche legacy/bootstrap.php de charger les includes legacy originaux
// (samba-tool.inc.php, gpo.inc.php, ...) qui font de vrais exec(samba-tool)
// et bloquent les tests sur un timeout Kerberos. Les shims if-guardés
// prennent le relais sans toucher au réseau / à la VM samba.
if (! defined('LEGACY_SKIP_LEGACY_INCLUDES')) {
    define('LEGACY_SKIP_LEGACY_INCLUDES', true);
}
