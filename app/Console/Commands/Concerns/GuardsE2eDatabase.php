<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use RuntimeException;

/**
 * Garde-fou structurel des commandes e2e destructives (Story 21.1, D-2).
 *
 * C'est le CŒUR SÉCURITÉ de l'epic : aucune commande utilisant ce trait ne
 * doit pouvoir DROP/CREATE une base qui ne soit pas une base e2e jetable.
 *
 * Calqué sur App\Console\Commands\DbSeedCommand::isProductionDatabase() — mais
 * sens INVERSÉ : ici on n'AUTORISE QUE le cas e2e, on refuse tout le reste
 * (allowlist, pas blocklist). Deux invariants cumulatifs :
 *   1. APP_ENV === 'e2e'
 *   2. la base cible de la connexion `pgsql` porte le suffixe attendu
 *      (`_e2e` pour le reset, `_e2e_template` pour le build de template).
 *
 * Détection du config cache : le fichier de cache de config prime sur les env
 * vars (comme DbSeedCommand). Si le cache existe, c'est lui qui dicte l'env et
 * le nom de base réellement utilisés par l'app — un `.env` peut mentir. Le
 * chemin est résolu via `app()->getCachedConfigPath()` (review 21-1 P-2) :
 * il respecte `APP_CONFIG_CACHE` (ex. `config-testing.php` de phpunit.xml) au
 * lieu de supposer `bootstrap/cache/config.php` — sinon le garde-fou pourrait
 * valider sur une config différente de celle que l'app utilise réellement.
 *
 * Le garde-fou est du CODE (exception levée), pas une option de config : il
 * doit être impossible de le contourner par mécompréhension d'un flag.
 */
trait GuardsE2eDatabase
{
    /**
     * Vérifie l'environnement e2e et le suffixe de base, ou lève une exception.
     *
     * @param  string  $requiredSuffix  Suffixe exigé sur le nom de base
     *                                   (ex. '_e2e' ou '_e2e_template').
     * @throws RuntimeException si l'environnement n'est pas e2e ou si la base
     *                          cible ne porte pas le suffixe exigé.
     */
    protected function guardE2eDatabase(string $requiredSuffix): void
    {
        [$appEnv, $connection, $database] = $this->resolveEffectiveEnvAndDatabase();

        if ($appEnv !== 'e2e') {
            throw new RuntimeException(
                "GARDE-FOU e2e : opération destructive REFUSÉE — APP_ENV=\"{$appEnv}\" "
                . "(attendu \"e2e\"). Aucune base n'a été touchée."
                . $this->configCacheHint(),
            );
        }

        if (! str_ends_with((string) $database, $requiredSuffix)) {
            throw new RuntimeException(
                "GARDE-FOU e2e : opération destructive REFUSÉE — base cible \"{$database}\" "
                . "(connexion \"{$connection}\") ne porte pas le suffixe \"{$requiredSuffix}\". "
                . "Aucune base n'a été touchée."
                . $this->configCacheHint(),
            );
        }
    }

    /**
     * Résout l'env applicatif et la base cible RÉELLEMENT effectifs.
     *
     * Lit le fichier de cache de config en priorité s'il existe (le cache
     * prime sur les env vars), sinon retombe sur la config runtime.
     *
     * @return array{0:string,1:string,2:string} [appEnv, connection, database]
     */
    private function resolveEffectiveEnvAndDatabase(): array
    {
        $cacheFile = $this->configCachePath();

        if (file_exists($cacheFile)) {
            $cached = require $cacheFile;
            $appEnv = $cached['app']['env'] ?? (string) config('app.env');
            $connection = $cached['database']['default'] ?? (string) config('database.default');
            $database = $cached['database']['connections'][$connection]['database'] ?? '';
        } else {
            $appEnv = (string) config('app.env');
            $connection = (string) config('database.default');
            $database = (string) config("database.connections.{$connection}.database", '');
        }

        return [(string) $appEnv, (string) $connection, (string) $database];
    }

    private function configCacheHint(): string
    {
        $cacheFile = $this->configCachePath();

        return file_exists($cacheFile)
            ? "\n  → Config caché détecté ({$cacheFile}) : il prime sur le .env. "
              . 'Lancez `php artisan config:clear` si l’env attendu ne correspond pas.'
            : '';
    }

    /**
     * Chemin RÉEL du cache de config : respecte `APP_CONFIG_CACHE`
     * (review 21-1 P-2), contrairement à un chemin codé en dur.
     */
    private function configCachePath(): string
    {
        return app()->getCachedConfigPath();
    }

    /**
     * Nom de la base cible e2e (connexion `pgsql`), telle qu'effectivement
     * configurée. Utilisé par les commandes pour construire le DROP/CREATE.
     */
    protected function e2eTargetDatabase(): string
    {
        [, , $database] = $this->resolveEffectiveEnvAndDatabase();

        return $database;
    }
}
