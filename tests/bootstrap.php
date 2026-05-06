<?php

// Bascule www-admin si lancé en root : root contourne les permissions POSIX
// (CAP_DAC_OVERRIDE), ce qui masque les vrais bugs de droits et ne reflète
// pas l'utilisateur PHP-FPM/queue runtime.
if (PHP_SAPI === 'cli'
    && function_exists('posix_geteuid')
    && posix_geteuid() === 0
    && posix_getpwnam('www-admin') !== false
) {
    $argv = $_SERVER['argv'] ?? [];
    $cmd  = PHP_BINARY . ' ' . implode(' ', array_map('escapeshellarg', $argv));
    $full = 'cd ' . escapeshellarg(getcwd()) . ' && exec ' . $cmd;
    passthru('runuser -u www-admin -- /bin/sh -c ' . escapeshellarg($full), $code);
    exit($code);
}

require_once __DIR__ . '/../vendor/autoload.php';

// Empêche legacy/bootstrap.php de charger les includes legacy originaux
// (samba-tool.inc.php, gpo.inc.php, ...) qui font de vrais exec(samba-tool)
// et bloquent les tests sur un timeout Kerberos. Les shims if-guardés
// prennent le relais sans toucher au réseau / à la VM samba.
if (! defined('LEGACY_SKIP_LEGACY_INCLUDES')) {
    define('LEGACY_SKIP_LEGACY_INCLUDES', true);
}
