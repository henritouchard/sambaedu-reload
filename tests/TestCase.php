<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardAgainstProductionDatabase();
    }

    /**
     * Interdit l'exécution des tests si la connexion DB active pointe sur
     * la base de production. Protège contre le cas où le config cache
     * (bootstrap/cache/config.php) a été buildé depuis le .env de prod :
     * dans ce cas, phpunit.xml ne peut pas overrider config() et les tests
     * tapent la prod au lieu de SQLite/:memory:.
     *
     * Règle : on abort si DB_CONNECTION != sqlite ET DB_DATABASE contient
     * un nom de base non suffixé par _test ou non égal à :memory:.
     */
    private function guardAgainstProductionDatabase(): void
    {
        $connection = config('database.default');
        $database   = config("database.connections.{$connection}.database", '');

        // SQLite :memory: ou fichier de test → OK
        if ($connection === 'sqlite') {
            return;
        }

        // PostgreSQL/MySQL avec base suffixée _test → OK
        if (str_ends_with($database, '_test')) {
            return;
        }

        $cacheFile = base_path('bootstrap/cache/config.php');
        $hint = file_exists($cacheFile)
            ? ' (config caché détecté — lancez "php artisan config:clear" avant les tests)'
            : '';

        $this->fail(
            "SÉCURITÉ : les tests sont connectés à la base \"{$database}\" ({$connection}),"
            . " qui ressemble à une base de production.{$hint}"
            . " Vérifiez phpunit.xml et .env.testing avant de relancer."
        );
    }
}
