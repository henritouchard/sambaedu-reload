<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MachinePowerActionTask;
use App\Services\Parc\MachinePowerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Story 4-2 — correction review #1 (NFR2 async).
 *
 * Exécute l'action power (wake / shutdown / shutdown-force / restart) sur une
 * machine en arrière-plan, met à jour `machine_power_action_tasks` à chaque
 * transition d'état (queued → dispatched → running → completed|failed).
 *
 * Le composant Livewire MachineShow crée la ligne `machine_power_action_tasks`
 * en status=queued, dispatche ce job, et retourne immédiatement un toast
 * "Action lancée" (< 500 ms) — d'où le respect de NFR2. Le polling
 * `wire:poll.{N}s="pollMachineReadiness"` consomme ensuite l'état de la task
 * pour afficher la progression et détecter la completion.
 *
 * Comportement dégradé connu : si QUEUE_CONNECTION=sync et aucun worker
 * queue n'est lancé, le job s'exécute inline (le retour UI sera donc plus lent,
 * mais le flux fonctionnel reste identique — pas de régression fonctionnelle).
 * Sur une install de prod, lancer `php artisan queue:work` pour bénéficier
 * de l'async réel.
 */
class DispatchMachinePowerActionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Nombre de tentatives. On garde 1 : un échec shell est déjà capturé
     * dans `result` par le service (pas une exception), donc un retry
     * automatique n'apporte rien et peut spam-shutdown une machine.
     */
    public int $tries = 1;

    /**
     * Timeout par job (secondes). Couvre le pire cas `net rpc shutdown -t 30`
     * + ping préalable. Au-delà, on considère que le worker est bloqué et
     * on laisse Laravel remonter l'erreur.
     */
    public int $timeout = 90;

    public function __construct(
        public readonly int $taskId,
    ) {
        // La connexion est résolue au dispatch via la config parc.queue_connection.
        // En prod avec worker, cela permet de router facilement vers une queue
        // dédiée (ex: `power-actions`) sans toucher le code.
        $connection = (string) config('parc.queue_connection', 'default');
        if ($connection !== 'default') {
            $this->onConnection($connection);
        }
    }

    public function handle(MachinePowerService $powerService): void
    {
        /** @var MachinePowerActionTask|null $task */
        $task = MachinePowerActionTask::find($this->taskId);

        if (!$task) {
            Log::warning('DispatchMachinePowerActionJob: task introuvable', [
                'task_id' => $this->taskId,
            ]);
            return;
        }

        // Pick-up par le worker → status=running + dispatched_at=now().
        $task->update([
            'status' => MachinePowerActionTask::STATUS_RUNNING,
            'dispatched_at' => now(),
        ]);

        $workstation = $task->workstation;
        if (!$workstation) {
            $this->markFailed($task, 'Workstation associée introuvable (supprimée entre-temps ?).');
            return;
        }

        $name = (string) $workstation->name;
        $ip = (string) ($workstation->ip ?? $name);
        $mac = (string) ($workstation->mac ?? '');

        try {
            $result = match ($task->action) {
                'wake' => $powerService->wakeOnLan($mac, $ip, $name),
                'shutdown' => $powerService->shutdown($name, $ip, false),
                'shutdown-force' => $powerService->shutdown($name, $ip, true),
                'restart', 'reboot' => $powerService->reboot($name, $ip, $mac),
                default => throw new \InvalidArgumentException("Action power non supportée: {$task->action}"),
            };

            $success = (bool) ($result['success'] ?? false);

            // Important : même pour une action réussie côté service (Process::run
            // OK + code 201/202), on NE MET PAS status=completed immédiatement
            // pour `wake` / `restart` — le polling UI est responsable de la
            // détection de readiness (machine effectivement up). On marque
            // simplement le résultat du service ; l'UI suit la completion via
            // ping + la phase `restart_phase` pour les reboots.
            //
            // Pour `shutdown` / `shutdown-force`, même logique : l'UI confirme
            // via ping que la machine a bien cessé de répondre.
            //
            // → On laisse donc le status à "running" et on renseigne le `result`.
            //   Le composant Livewire marquera la task `completed` depuis
            //   `pollMachineReadiness()` une fois l'état confirmé côté réseau.
            //
            // Exception : si le service a échoué d'emblée (MAC invalide,
            // shutdown sur machine off, etc.), on marque failed tout de suite
            // pour court-circuiter le polling.
            if (!$success) {
                $this->markFailed(
                    $task,
                    (string) ($result['message'] ?? 'Échec action power'),
                    $result,
                );
                return;
            }

            $task->update(['result' => $result]);

            Log::info('DispatchMachinePowerActionJob: action dispatchée avec succès', [
                'task_id' => $task->id,
                'action' => $task->action,
                'machine' => $name,
                'code' => $result['code'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('DispatchMachinePowerActionJob: exception', [
                'task_id' => $task->id,
                'action' => $task->action,
                'machine' => $name,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($task, $e->getMessage());
        }
    }

    /**
     * Handler de défaillance globale (appelé par Laravel si `handle()` throw
     * et qu'aucune tentative restante) — garantie qu'une task ne peut pas
     * rester bloquée en `running` indéfiniment.
     */
    public function failed(?\Throwable $exception): void
    {
        $task = MachinePowerActionTask::find($this->taskId);
        if (!$task || $task->isTerminal()) {
            return;
        }

        $task->update([
            'status' => MachinePowerActionTask::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => $exception?->getMessage() ?? 'Job en échec sans exception.',
        ]);
    }

    private function markFailed(MachinePowerActionTask $task, string $message, ?array $result = null): void
    {
        $task->update([
            'status' => MachinePowerActionTask::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => $message,
            'result' => $result ?? $task->result,
        ]);
    }
}
