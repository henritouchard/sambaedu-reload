<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Database;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Vérifie que la connexion base de données par défaut répond.
 *
 * Sur SE5 le driver attendu est PostgreSQL, mais le check reste agnostique
 * (en environnement de test la connexion par défaut est SQLite — il
 * rapporte alors le driver effectif sans échouer).
 */
final class PostgresConnectionCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'database';
    }

    public function name(): string
    {
        return 'Base de données';
    }

    public function run(): CheckResult
    {
        try {
            $connection = DB::connection();
            $pdo = $connection->getPdo();
            $driver = (string) $connection->getDriverName();
            // SELECT 1 : round-trip réel (getPdo() peut réutiliser un socket
            // mort sans le détecter).
            $connection->select('select 1');

            $version = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

            return CheckResult::ok(sprintf(
                'connexion OK (%s%s, base %s)',
                $driver,
                $version !== '' ? ' ' . $version : '',
                (string) $connection->getDatabaseName(),
            ));
        } catch (Throwable $e) {
            return CheckResult::error(
                sprintf('connexion impossible : %s', substr($e->getMessage(), 0, 160)),
                'Vérifier DB_* dans .env et que le service postgresql est démarré (systemctl status postgresql).',
            );
        }
    }
}
