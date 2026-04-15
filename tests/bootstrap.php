<?php

/**
 * Bootstrap PHPUnit — exécuté avant toute classe de test.
 *
 * Vérifie que les variables d'environnement DB pointent sur une base
 * de test (sqlite ou *_test) et pas sur la prod, AVANT que Laravel
 * ne soit bootstrappé et AVANT toute connexion DB.
 *
 * Cas typique d'accident : `php artisan config:cache` buildé depuis le
 * .env de prod → config() retourne pgsql/sambaedu même si phpunit.xml
 * set DB_CONNECTION=sqlite. Le cache prend la priorité sur les env vars
 * de phpunit.xml pour les appels config() mais pas pour les appels env().
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Les env vars de phpunit.xml sont injectées AVANT ce fichier via le
// runner PHPUnit — on peut les lire directement.
$connection = $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?: 'pgsql';
$database   = $_ENV['DB_DATABASE']   ?? getenv('DB_DATABASE')   ?: '';

$isSafe = $connection === 'sqlite'
    || $database === ':memory:'
    || str_ends_with($database, '_test');

if (! $isSafe) {
    $cacheFile = __DIR__ . '/../bootstrap/cache/config.php';
    $hint = file_exists($cacheFile)
        ? "\n  → Config caché détecté ! Lancez : php artisan config:clear"
        : '';

    fwrite(STDERR, implode("\n", [
        '',
        '╔══════════════════════════════════════════════════════════════╗',
        '║  SÉCURITÉ : BASE DE DONNÉES DE PRODUCTION DÉTECTÉE          ║',
        '╚══════════════════════════════════════════════════════════════╝',
        '',
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
