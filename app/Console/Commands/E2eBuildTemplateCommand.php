<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsE2eDatabase;
use App\Console\Commands\Concerns\UsesMaintenanceConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Story 21.1 — (Re)construction de la TEMPLATE de la base e2e.
 *
 * Construit `sambaedu_e2e_template` = base de référence figée à partir de
 * laquelle `e2e:reset` recrée `sambaedu_e2e` en ~centisecondes.
 *
 * Étapes :
 *   1. GARDE-FOU D-2 : refuse si APP_ENV !== e2e OU base cible non suffixée
 *      `_e2e` (la template est dérivée de ce nom + `_template`).
 *   2. DROP/CREATE de la template vide via la connexion de maintenance
 *      (`postgres`) — on ne peut pas dropper la base active (Q-4).
 *   3. migrate:fresh + db:seed (DatabaseSeeder existant) EXÉCUTÉS SUR la
 *      template, en repointant temporairement la connexion `pgsql` dessus.
 *
 * À RELANCER UNIQUEMENT quand des migrations ou des seeders changent
 * (D-1) — pas à chaque suite (c'est `e2e:reset` qui tourne par suite).
 *
 * Réutilise les seeders existants (Database\Seeders\DatabaseSeeder) ; le seed
 * e2e de référence (établissement, utilisateurs par rôle) est l'objet de la
 * Story 21.3, hors scope ici.
 */
class E2eBuildTemplateCommand extends Command
{
    use GuardsE2eDatabase;
    use UsesMaintenanceConnection;

    protected $signature = 'e2e:build-template';

    protected $description = 'Construit la template e2e (migrate:fresh + db:seed sur sambaedu_e2e_template). Refuse hors env e2e.';

    public function handle(): int
    {
        // 1) GARDE-FOU EN PREMIER. On valide le suffixe `_e2e` de la base
        //    courante (la template en dérive) ET on revalide le suffixe
        //    `_e2e_template` du nom construit, ceinture-et-bretelles.
        try {
            $this->guardE2eDatabase('_e2e');
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $base = $this->e2eTargetDatabase();
        $template = $base . '_template';

        try {
            // Revalidation du nom de template lui-même (invariant explicite).
            if (! str_ends_with($template, '_e2e_template')) {
                throw new RuntimeException(
                    "GARDE-FOU e2e : nom de template inattendu \"{$template}\" "
                    . '(suffixe `_e2e_template` exigé). Aucune base touchée.',
                );
            }

            $this->components->info("Construction de la template e2e : {$template}");

            $maintenance = $this->maintenanceConnection();

            // 2) DROP/CREATE template vide.
            $this->terminateAndDrop($maintenance, $template);
            $this->createDatabase($maintenance, $template);

            // 3) migrate:fresh + seed SUR la template : on repointe la connexion
            //    `pgsql` vers la template le temps de l'opération, puis on
            //    restaure pour ne pas polluer le reste du process.
            $this->runMigrationsAndSeedOn($template);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error(
                "Échec de la construction de la template e2e ({$template}) : {$e->getMessage()}",
            );

            return self::FAILURE;
        }

        $this->components->info(
            "Template e2e `{$template}` construite (migrate:fresh + db:seed). "
            . "Lancez `php artisan e2e:reset` pour (re)créer `{$base}`.",
        );

        return self::SUCCESS;
    }

    /**
     * Repointe temporairement la connexion `pgsql` vers $template, exécute
     * migrate:fresh + db:seed, puis restaure la config et purge la connexion.
     */
    private function runMigrationsAndSeedOn(string $template): void
    {
        $original = config('database.connections.pgsql.database');

        config(['database.connections.pgsql.database' => $template]);
        DB::purge('pgsql');

        try {
            // migrate:fresh sur la template (connexion par défaut = pgsql en e2e).
            $this->call('migrate:fresh', ['--force' => true]);

            // db:seed via le DatabaseSeeder existant. --force requis car notre
            // garde anti-prod (DbSeedCommand) inspecte le suffixe ; la base
            // pointe ici `_e2e_template` (≠ `_test`) → on force explicitement,
            // l'env est déjà verrouillé `e2e` par le garde-fou de cette commande.
            $this->call('db:seed', ['--force' => true]);
        } finally {
            // Restauration systématique, même en cas d'exception.
            config(['database.connections.pgsql.database' => $original]);
            DB::purge('pgsql');
        }
    }
}
