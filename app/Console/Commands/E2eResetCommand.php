<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsE2eDatabase;
use App\Console\Commands\Concerns\UsesMaintenanceConnection;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Story 21.1 — Reset RAPIDE de la base e2e depuis la template.
 *
 * Recrée `sambaedu_e2e` en ~centisecondes par copie binaire de la template
 * `sambaedu_e2e_template` (D-1) :
 *   DROP DATABASE IF EXISTS sambaedu_e2e
 *   CREATE DATABASE sambaedu_e2e TEMPLATE sambaedu_e2e_template
 *
 * Aucune re-migration : la template est construite une seule fois par
 * `e2e:build-template`, et seulement reconstruite quand migrations/seeders
 * changent.
 *
 * Déclenchée par le `globalSetup` Playwright via SSH (DP-1) avant chaque suite.
 *
 * GARDE-FOU STRUCTUREL D-2 (cœur sécurité) : appliqué EN PREMIER. Refuse si
 * APP_ENV !== e2e OU si la base cible ne se termine pas par `_e2e`. Le DROP/
 * CREATE passe par une connexion de maintenance (`postgres`) car on ne peut
 * pas dropper la base active (Q-4).
 */
class E2eResetCommand extends Command
{
    use GuardsE2eDatabase;
    use UsesMaintenanceConnection;

    protected $signature = 'e2e:reset';

    protected $description = 'Recrée la base e2e depuis la template (DROP + CREATE … TEMPLATE). Refuse hors env e2e.';

    public function handle(): int
    {
        // 1) GARDE-FOU EN PREMIER — avant toute opération destructive.
        try {
            $this->guardE2eDatabase('_e2e');
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $target = $this->e2eTargetDatabase();
        $template = $target . '_template';

        $this->components->info("Reset e2e : {$target} ← TEMPLATE {$template}");

        try {
            $maintenance = $this->maintenanceConnection();

            // 2) Couper les sessions actives sur la cible puis la DROP.
            $this->terminateAndDrop($maintenance, $target);

            // 3) Couper aussi les sessions résiduelles sur la TEMPLATE (review
            //    21-1 P-3/N-1) : `CREATE … TEMPLATE` exige zéro connexion sur la
            //    source — ex. premier reset juste après `e2e:build-template`,
            //    ou session psql de debug oubliée sur la template.
            $this->terminateSessions($maintenance, $template);

            // 4) Recréer la cible par copie binaire de la template.
            $this->createFromTemplate($maintenance, $target, $template);
        } catch (Throwable $e) {
            $this->components->error(
                "Échec du reset e2e ({$target}) : {$e->getMessage()}\n"
                . "Vérifier que la template `{$template}` existe "
                . '(php artisan e2e:build-template) et qu’aucune session n’est ouverte dessus.',
            );

            return self::FAILURE;
        }

        $this->components->info("Base e2e `{$target}` recréée depuis `{$template}`.");

        return self::SUCCESS;
    }
}
