<?php

/**
 * Bootstrap PHPUnit — exécuté avant toute classe de test.
 *
 * Vérifie que la base de données active n'est pas la prod, en lisant
 * directement le config cache si présent (qui prend la priorité sur les
 * env vars de phpunit.xml quand il existe).
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Empêche legacy/bootstrap.php de charger les includes legacy originaux
// (samba-tool.inc.php, gpo.inc.php, ...) qui font de vrais exec(samba-tool)
// et bloquent les tests sur un timeout Kerberos. Les shims if-guardés
// prennent le relais sans toucher au réseau / à la VM samba.
if (! defined('LEGACY_SKIP_LEGACY_INCLUDES')) {
    define('LEGACY_SKIP_LEGACY_INCLUDES', true);
}

$cacheFile = __DIR__ . '/../bootstrap/cache/config.php';

if (file_exists($cacheFile)) {
    // Le cache existe → c'est lui que Laravel va utiliser, pas phpunit.xml.
    // On le charge pour lire la vraie config DB.
    $cached     = require $cacheFile;
    $connection = $cached['database']['default'] ?? 'pgsql';
    $database   = $cached['database']['connections'][$connection]['database'] ?? '';
    $source     = 'config cache (bootstrap/cache/config.php)';
} else {
    // Pas de cache → Laravel lira .env.testing puis les env vars phpunit.xml.
    // Les env vars phpunit.xml sont injectées par le runner avant ce fichier.
    $connection = $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?: 'pgsql';
    $database   = $_ENV['DB_DATABASE']   ?? getenv('DB_DATABASE')   ?: '';
    $source     = 'env vars (phpunit.xml / .env.testing)';
}

$isSafe = $connection === 'sqlite'
    || $database === ':memory:'
    || str_ends_with($database, '_test');

if (! $isSafe) {
    $hint = file_exists($cacheFile)
        ? "  → Lancez : php artisan config:clear"
        : '';

    fwrite(STDERR, implode("\n", [
        '',
        '╔══════════════════════════════════════════════════════════════╗',
        '║  SÉCURITÉ : BASE DE DONNÉES DE PRODUCTION DÉTECTÉE          ║',
        '╚══════════════════════════════════════════════════════════════╝',
        '',
        "  Source     : {$source}",
        "  Connection : {$connection}",
        "  Database   : {$database}",
        '',
        '  Les tests refusent de tourner sur une base non suffixée _test',
        '  ou non égale à :memory: (SQLite).',
        $hint,
        '',
    ]));

    exit(1);
}
