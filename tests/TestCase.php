<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardAgainstProductionDatabase();
        $this->ensureErrorLogsTableOnSqlite();
        // $_SESSION est un superglobal PHP qui persiste dans le process PHPUnit.
        // Le bridge legacy (LegacyCatchallController::bridgeLegacySession) y écrit
        // login/level/etab et fait fuiter l'état d'un test vers les suivants.
        $_SESSION = [];
    }

    /**
     * Crée la table `error_logs` en SQLite :memory: si absente.
     *
     * Plusieurs services (ErrorLoggerService, LegacyErrorHandler, middleware 404)
     * écrivent dans `error_logs` sans que le test l'ait explicitement créée.
     * En SQLite :memory: sans RefreshDatabase ni migrations, l'insert crashe
     * avec `no such table: error_logs` et masque la vraie erreur du test.
     * Cette garde minimale évite la cascade.
     */
    private function ensureErrorLogsTableOnSqlite(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }
        if (Schema::hasTable('error_logs')) {
            return;
        }
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 10);
            $table->text('message');
            $table->timestamp('created_at');
        });
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
