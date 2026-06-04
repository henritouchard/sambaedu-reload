<?php

namespace App\Console\Commands;

use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Database\ConnectionResolverInterface as Resolver;

/**
 * Override de db:seed avec garde anti-production.
 *
 * Refuse de seeder si la base active ressemble à une base de prod
 * (ni sqlite, ni suffixée _test). Utiliser --force pour forcer.
 *
 * La détection vérifie d'abord le config cache s'il existe, car le cache
 * prend la priorité sur les env vars et peut pointer vers la prod même si
 * .env.testing dit autre chose. Le chemin du cache est résolu via
 * app()->getCachedConfigPath() (review 21-1 P-2) : il respecte
 * APP_CONFIG_CACHE au lieu de supposer bootstrap/cache/config.php.
 *
 * Note : --force est déjà défini par le parent (SeedCommand). On le réutilise
 * pour notre garde — ne pas redéfinir $signature ni getOptions().
 */
class DbSeedCommand extends SeedCommand
{
    public function handle(): int
    {
        if (! $this->option('force') && $this->isProductionDatabase()) {
            $connection = config('database.default');
            $database   = config("database.connections.{$connection}.database", '?');
            $cacheFile  = app()->getCachedConfigPath();
            $hint       = file_exists($cacheFile)
                ? '  → Config caché détecté ! Lancez : php artisan config:clear'
                : '';

            $this->components->error(implode("\n", array_filter([
                'BASE DE DONNÉES DE PRODUCTION DÉTECTÉE — seed annulé.',
                "  Connection : {$connection}",
                "  Database   : {$database}",
                '  Utilisez --force pour forcer.',
                $hint,
            ])));

            return self::FAILURE;
        }

        return parent::handle();
    }

    private function isProductionDatabase(): bool
    {
        // Lire le config cache en priorité s'il existe (chemin réel via
        // APP_CONFIG_CACHE — review 21-1 P-2)
        $cacheFile = app()->getCachedConfigPath();
        if (file_exists($cacheFile)) {
            $cached     = require $cacheFile;
            $connection = $cached['database']['default'] ?? config('database.default');
            $database   = $cached['database']['connections'][$connection]['database'] ?? '';
        } else {
            $connection = config('database.default');
            $database   = config("database.connections.{$connection}.database", '');
        }

        return $connection !== 'sqlite'
            && $database !== ':memory:'
            && ! str_ends_with($database, '_test');
    }
}
