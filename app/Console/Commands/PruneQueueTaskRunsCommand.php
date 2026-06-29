<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Story 29.10 — Purge des runs de tracking d'exécution des jobs.
 *
 * Supprime les lignes `queue_task_runs` :
 *  - statut `done` dont le `finished_at` dépasse `workers.retention.done_days` (14 j) ;
 *  - statut `failed` dont le `failed_at` dépasse `workers.retention.failed_days` (30 j).
 *
 * Les runs `running` (sans `finished_at`/`failed_at`) sont TOUJOURS préservés,
 * quel que soit leur âge (worker bloqué = info utile pour le diagnostic).
 *
 * Seuils configurables dans `config/sambaedu.php`, clés `workers.retention.done_days`
 * et `workers.retention.failed_days`.
 *
 * Planifiée daily dans `routes/console.php`.
 */
class PruneQueueTaskRunsCommand extends Command
{
    protected $signature = 'queue-task-runs:prune';

    protected $description = 'Purge les queue_task_runs terminés au-delà des seuils de rétention (Story 29.10)';

    public function handle(): int
    {
        $doneCutoff = now()->subDays(max(1, (int) config('sambaedu.workers.retention.done_days', 14)));
        $failedCutoff = now()->subDays(max(1, (int) config('sambaedu.workers.retention.failed_days', 30)));

        $done = DB::table('queue_task_runs')
            ->where('status', 'done')
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $doneCutoff)
            ->delete();

        $failed = DB::table('queue_task_runs')
            ->where('status', 'failed')
            ->whereNotNull('failed_at')
            ->where('failed_at', '<', $failedCutoff)
            ->delete();

        $this->info(sprintf(
            '%d run(s) done purgé(s) (antérieurs au %s), %d run(s) failed purgé(s) (antérieurs au %s).',
            $done,
            $doneCutoff->format('d/m/Y'),
            $failed,
            $failedCutoff->format('d/m/Y'),
        ));

        return self::SUCCESS;
    }
}
