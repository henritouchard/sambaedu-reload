<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Connexion Postgres de maintenance construite À LA VOLÉE (Story 21.1, Q-4).
 *
 * On NE PEUT PAS dropper la base à laquelle on est connecté. Le DROP/CREATE de
 * la base e2e doit donc passer par une connexion ouverte sur une base neutre
 * (`postgres`). Décision Q-4 (henri 2026-06-04) : pas d'entrée permanente dans
 * `config/database.php` — on clone la config `pgsql` runtime en remplaçant
 * juste `database` par `postgres`, et on enregistre la connexion sous un nom
 * éphémère.
 *
 * `CREATE DATABASE … TEMPLATE …` échoue s'il reste des connexions ouvertes sur
 * la base cible OU sur la template. Avant chaque DROP, on termine les sessions
 * actives via `pg_terminate_backend`.
 */
trait UsesMaintenanceConnection
{
    /** Nom de la connexion de maintenance éphémère enregistrée à la volée. */
    private string $maintenanceConnectionName = 'pgsql_e2e_maintenance';

    /**
     * Garantit l'existence d'une connexion vers la base `postgres`, clonée
     * depuis la config `pgsql` runtime, et la retourne.
     */
    protected function maintenanceConnection(): string
    {
        $base = config('database.connections.pgsql');

        if (! is_array($base)) {
            // Sécurité : si la connexion pgsql n'est pas configurée, on ne tente
            // aucune opération destructive. Le garde-fou structurel a normalement
            // déjà refusé en amont (env non-e2e), mais on reste défensif.
            throw new \RuntimeException(
                'Connexion `pgsql` introuvable dans config/database.php — '
                . 'impossible de construire la connexion de maintenance.',
            );
        }

        $maintenance = array_merge($base, [
            'database' => 'postgres',
            // `search_path` hérité tel quel de la config source (review 21-1
            // N-2) : on ne fait que des CREATE/DROP DATABASE, qui n'en
            // dépendent pas — le forcer en string divergerait d'une config
            // source en array.
        ]);

        config(["database.connections.{$this->maintenanceConnectionName}" => $maintenance]);

        // Purge d'une éventuelle instance précédente pour repartir propre.
        DB::purge($this->maintenanceConnectionName);

        return $this->maintenanceConnectionName;
    }

    /**
     * Termine les connexions actives sur une base donnée (hors la nôtre, qui
     * est sur `postgres`). Sans ça, `DROP DATABASE` — et `CREATE DATABASE …
     * TEMPLATE` côté source — échouent avec "database is being accessed by
     * other users". (Review 21-1 P-3/N-1 : à appliquer aussi à la TEMPLATE
     * avant de l'utiliser comme source — ex. sessions résiduelles juste après
     * un `e2e:build-template`, ou une session psql de debug oubliée.)
     */
    protected function terminateSessions(string $connection, string $database): void
    {
        DB::connection($connection)->select(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity '
            . 'WHERE datname = ? AND pid <> pg_backend_pid()',
            [$database],
        );
    }

    /**
     * Termine les connexions actives sur une base donnée, puis la DROP si elle
     * existe. À exécuter via la connexion de maintenance (jamais connecté à la
     * base qu'on droppe).
     */
    protected function terminateAndDrop(string $connection, string $database): void
    {
        $this->terminateSessions($connection, $database);

        // Quoting de l'identifiant : on a déjà validé le suffixe via le
        // garde-fou ; on double-quote par robustesse.
        $quoted = $this->quoteIdentifier($database);
        DB::connection($connection)->statement("DROP DATABASE IF EXISTS {$quoted}");
    }

    /**
     * Crée $database comme copie binaire de $template (CREATE … TEMPLATE).
     * L'appelant doit avoir terminé les sessions sur la template juste avant
     * (`terminateSessions`) : Postgres exige zéro connexion sur la source.
     */
    protected function createFromTemplate(string $connection, string $database, string $template): void
    {
        $quotedDb = $this->quoteIdentifier($database);
        $quotedTpl = $this->quoteIdentifier($template);

        DB::connection($connection)->statement(
            "CREATE DATABASE {$quotedDb} TEMPLATE {$quotedTpl}",
        );
    }

    /** Crée une base vide (sans template). */
    protected function createDatabase(string $connection, string $database): void
    {
        $quoted = $this->quoteIdentifier($database);
        DB::connection($connection)->statement("CREATE DATABASE {$quoted}");
    }

    /**
     * Double-quote un identifiant Postgres en neutralisant les guillemets.
     * (Le nom de base a déjà passé le garde-fou structurel par suffixe ; ce
     * quoting est une ceinture-et-bretelles, pas la défense primaire.)
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
