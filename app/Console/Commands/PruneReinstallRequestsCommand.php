<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Parc\WorkstationReinstallService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 3.11 — Tâche 8.3 — Purge quotidienne des requêtes de réinstallation
 * terminales (done/failed/canceled) > N jours.
 *
 * Patron identique à `parc:prune-group-schedule-runs`. Scheduler ->daily().
 */
class PruneReinstallRequestsCommand extends Command
{
    protected $signature = 'parc:prune-reinstall-requests {--days=30 : Nombre de jours à conserver}';

    protected $description = 'Purge les réinstallations terminales (done/failed/canceled) plus anciennes que N jours (défaut 30).';

    protected $help = <<<'HELP'
    Supprime les demandes de réinstallation ARRIVÉES À TERME — abouties, échouées ou
    annulées — plus anciennes que la rétention demandée (30 jours par défaut).

      <info>php artisan parc:prune-reinstall-requests</info>
      <info>php artisan parc:prune-reinstall-requests --days=90</info>

    Les demandes encore en cours ne sont jamais touchées, quel que soit leur âge.

    Planifiée quotidiennement.
    HELP;

    public function handle(WorkstationReinstallService $service): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('L\'option --days doit être >= 1.');

            return Command::FAILURE;
        }

        try {
            $deleted = $service->prune($days);
            $this->info("Réinstallations purgées (> {$days}j) : {$deleted}");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('[parc:prune-reinstall-requests] échec', [
                'exception' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
