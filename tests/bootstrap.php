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

// Story 38.4 — la constante `LEGACY_SKIP_LEGACY_INCLUDES` est devenue SANS
// OBJET : `legacy/bootstrap.php` ne charge PLUS AUCUN include GPO legacy
// (`samba-tool.inc.php`, `gpo.inc.php`, …) — ils sont portés en natif ou
// dégradés (Story 38.4 T1/T2/T6). Plus rien à « skipper » : référence retirée.
