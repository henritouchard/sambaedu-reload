<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Parc\WorkstationGroupScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 4-4 — Purge quotidienne des runs d'historique > 30 jours.
 *
 * Pattern identique à `error-logs:prune`. Scheduler ->daily() dans Kernel.
 */
class PruneWorkstationGroupScheduleRunsCommand extends Command
{
    protected $signature = 'parc:prune-group-schedule-runs {--days=30 : Nombre de jours à conserver}';

    protected $description = 'Purge les runs d\'historique des programmations plus anciens que N jours (défaut 30).';

    public function handle(WorkstationGroupScheduleService $service): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('L\'option --days doit être >= 1.');
            return Command::FAILURE;
        }

        try {
            $deleted = $service->pruneRuns($days);
            $this->info("Runs purgés (> {$days}j) : {$deleted}");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('[parc:prune-group-schedule-runs] échec', [
                'exception' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
