<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Parc\WorkstationGroupScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 4-4 — Tick scheduler (everyMinute) qui exécute les programmations dues.
 *
 * Architecture :
 *  - Tick (léger : 1 SELECT + N enqueue) : cette commande.
 *  - Exécution effective (WOL / shutdown) : worker `laravel-queue-general` via
 *    les `DispatchMachinePowerActionJob` dispatchés par le service.
 *
 * La commande ne fait PAS d'I/O réseau, elle enqueue seulement.
 */
class ExecuteWorkstationGroupSchedulesCommand extends Command
{
    protected $signature = 'parc:execute-group-schedules';

    protected $description = 'Exécute les programmations horaires des WorkstationGroups dues au tick courant';

    protected $help = <<<'HELP'
    Déclenche les programmations horaires des groupes de postes arrivées à échéance —
    allumages et extinctions planifiés.

    Planifiée chaque minute. Elle est délibérément LÉGÈRE : elle lit ce qui est dû et
    met les actions en file, sans jamais parler au réseau elle-même. L'allumage et
    l'extinction réels sont exécutés par le service de traitement en arrière-plan.

    <comment>Conséquence pratique :</comment> si des postes ne s'allument pas alors que la
    programmation est correcte, ce n'est pas ici qu'il faut chercher — vérifiez que
    les travailleurs de file d'attente tournent.
    HELP;

    public function handle(WorkstationGroupScheduleService $service): int
    {
        try {
            $result = $service->executeDue();

            $this->info(sprintf(
                'Schedules exécutés : %d (récurrents: %d, one-shots: %d), tasks dispatchées : %d',
                $result['executed_count'],
                $result['recurring_count'],
                $result['one_shot_count'],
                $result['total_tasks_dispatched'],
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('[parc:execute-group-schedules] échec', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
