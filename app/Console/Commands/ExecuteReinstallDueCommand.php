<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Parc\WorkstationReinstallService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Story 3.11 — Tick scheduler (everyMinute) qui déclenche les réinstallations
 * dûes, bornées par le plafond de concurrence (D11).
 *
 * Architecture iso 4.4 :
 *  - Tick (léger : 1 SELECT + N enqueue) : cette commande.
 *  - Exécution effective (reboot forcé / WOL) : worker `laravel-queue-general`
 *    via les `DispatchMachinePowerActionJob` dispatchés par le service.
 *
 * La commande ne fait PAS d'I/O réseau, elle enqueue seulement.
 */
class ExecuteReinstallDueCommand extends Command
{
    protected $signature = 'parc:reinstall-due';

    protected $description = 'Déclenche les réinstallations OS dûes (throttlé par vagues — plafond de concurrence)';

    protected $help = <<<'HELP'
    Déclenche les réinstallations de postes arrivées à échéance, par vagues bornées
    par un plafond de postes traités simultanément.

    Planifiée chaque minute. Comme les programmations de groupes, elle est LÉGÈRE :
    elle lit ce qui est dû et met en file ; le redémarrage forcé ou le réveil réseau
    sont exécutés par le service de traitement en arrière-plan.

    Le plafond de concurrence est là pour éviter qu'un parc entier ne se réinstalle en
    même temps et ne sature le réseau ou le serveur d'images.
    HELP;

    public function handle(WorkstationReinstallService $service): int
    {
        try {
            $triggered = $service->triggerDue(Carbon::now());

            $this->info("Réinstallations déclenchées ce tick : {$triggered}");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('[parc:reinstall-due] échec', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
