<?php

namespace App\Jobs;

use App\Models\ControlHubTask;
use App\Services\ControlHub\ControlHubService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Classe abstraite de base pour tous les Jobs ControlHub.
 * 
 * Cette classe gère automatiquement :
 * - La mise à jour des statuts en BDD (received → in_progress → success/failed)
 * - L'envoi du callback au ControlHub avec le résultat
 * - La gestion des erreurs et des retries
 * - Le logging standardisé
 * 
 * Pour créer un nouveau Job, il suffit d'hériter de cette classe
 * et d'implémenter la méthode execute() qui contient la logique métier.
 * 
 * @example
 * class ExecuteMyTaskJob extends BaseControlHubJob
 * {
 *     protected function execute(): array
 *     {
 *         // Logique métier ici
 *         return ['result' => 'data'];
 *     }
 * }
 */
abstract class BaseControlHubJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Nombre de tentatives maximum
     */
    public int $tries = 3;

    /**
     * Timeout en secondes (4 minutes par défaut)
     */
    public int $timeout = 240;

    /**
     * La tâche associée à ce job
     */
    public ControlHubTask $task;

    /**
     * Create a new job instance.
     */
    public function __construct(ControlHubTask $task)
    {
        $this->task = $task;
    }

    /**
     * Retourne le nom du job pour les logs.
     * Par défaut, utilise le nom de la classe sans le namespace.
     */
    protected function getJobName(): string
    {
        return class_basename(static::class);
    }

    /**
     * Méthode abstraite à implémenter par les jobs enfants.
     * Contient la logique métier spécifique au job.
     * 
     * @return array Le résultat de l'exécution (sera envoyé au ControlHub)
     * @throws \Exception En cas d'erreur (sera capturée et gérée automatiquement)
     */
    abstract protected function execute(): array;

    /**
     * Execute the job.
     * Cette méthode orchestre l'exécution : mise à jour statut, exécution, callback.
     */
    public function handle(ControlHubService $controlHub): void
    {
        $jobName = $this->getJobName();

        Log::info("{$jobName}: Starting task execution", [
            'task_id' => $this->task->id,
            'controlhub_task_id' => $this->task->controlhub_task_id,
            'task_name' => $this->task->name,
            'task_type' => $this->task->type,
        ]);

        // Vérifier et verrouiller la tâche pour éviter les race conditions avec l'annulation
        $canExecute = DB::transaction(function () {
            $task = ControlHubTask::where('id', $this->task->id)
                ->lockForUpdate()
                ->first();

            if (!$task || $task->isCanceled() || $task->status === ControlHubTask::STATUS_IN_PROGRESS || $task->isCompleted()) {
                // Tâche déjà annulée, en cours, ou terminée
                return false;
            }

            // Marquer comme en cours (dans la transaction)
            $task->markAsInProgress();
            $this->task->refresh();
            return true;
        });

        if (!$canExecute) {
            Log::info("{$jobName}: Task skipped - already canceled or in progress", [
                'task_id' => $this->task->id,
                'status' => $this->task->fresh()->status ?? 'unknown',
            ]);
            return;
        }

        try {
            // Exécuter la logique métier (méthode abstraite)
            $result = $this->execute();

            // Enrichir le résultat avec les métadonnées standard
            $result = $this->enrichResult($result);

            // Marquer comme succès
            $this->task->markAsSuccess($result);

            Log::info("{$jobName}: Task completed successfully", [
                'task_id' => $this->task->id,
                'result_keys' => array_keys($result),
            ]);

            // Envoyer le callback de succès au ControlHub
            $this->sendCallback($controlHub, 'success', $result);

        } catch (\Exception $e) {
            Log::error("{$jobName}: Task failed", [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Marquer comme échoué
            $this->task->markAsFailed($e->getMessage());

            // Envoyer le callback d'échec au ControlHub
            $this->sendCallback($controlHub, 'failed', null, $e->getMessage());
        }
    }

    /**
     * Enrichit le résultat avec les métadonnées standard.
     */
    protected function enrichResult(array $result): array
    {
        return array_merge($result, [
            'executed_at' => now()->toISOString(),
            'instance_id' => config('controlHub.se4fs.instance_id'),
            'job_name' => $this->getJobName(),
        ]);
    }

    /**
     * Envoyer le résultat au ControlHub via callback.
     */
    protected function sendCallback(
        ControlHubService $controlHub,
        string $status,
        ?array $result,
        ?string $error = null
    ): void {
        $instanceId = config('controlHub.se4fs.instance_id');
        $endpoint = "/api/sambaedu/task-result/{$instanceId}";

        $payload = [
            'task_id' => $this->task->controlhub_task_id,
            'status' => $status,
            'result' => $result,
            'error' => $error,
            'completed_at' => now()->toISOString(),
        ];

        try {
            $response = $controlHub->callControlHubApi($endpoint, $payload, 'POST');

            if ($response['success']) {
                $this->task->markCallbackSent($response);
                Log::info("{$this->getJobName()}: Callback sent successfully", [
                    'controlhub_task_id' => $this->task->controlhub_task_id,
                    'status' => $status,
                ]);
            } else {
                $errorMsg = $response['message'] ?? 'Unknown error';
                $this->task->markCallbackFailed($errorMsg, $response);
                Log::warning("{$this->getJobName()}: Callback failed", [
                    'controlhub_task_id' => $this->task->controlhub_task_id,
                    'error' => $errorMsg,
                ]);
            }
        } catch (\Exception $e) {
            $this->task->markCallbackFailed($e->getMessage());
            Log::error("{$this->getJobName()}: Callback exception", [
                'controlhub_task_id' => $this->task->controlhub_task_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure (après toutes les tentatives).
     */
    public function failed(\Throwable $exception): void
    {
        $jobName = $this->getJobName();

        Log::error("{$jobName}: Job failed permanently after {$this->tries} attempts", [
            'task_id' => $this->task->id,
            'controlhub_task_id' => $this->task->controlhub_task_id,
            'error' => $exception->getMessage(),
        ]);

        $this->task->markAsFailed("Job failed after max retries: " . $exception->getMessage());
    }
}
