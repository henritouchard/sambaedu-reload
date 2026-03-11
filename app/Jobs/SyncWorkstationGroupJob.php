<?php

namespace App\Jobs;

use App\Services\ControlHub\WorkstationGroupSyncService;
use Illuminate\Support\Facades\Log;

/**
 * Job pour exécuter la synchronisation d'un WorkstationGroup depuis le ControlHub.
 *
 * Gère deux types de tâches :
 * - sync_workstation_group : groupe logique (flat)
 * - sync_workstation_group_tree : arborescence physique (tree)
 */
class SyncWorkstationGroupJob extends BaseControlHubJob
{
    /**
     * Exécute la logique métier de synchronisation.
     *
     * @return array Le résultat de l'exécution
     * @throws \Exception En cas d'erreur
     */
    protected function execute(): array
    {
        $payload = $this->task->payload ?? [];
        $taskType = $this->task->type;

        Log::info('SyncWorkstationGroupJob: Processing sync', [
            'task_id' => $this->task->id,
            'task_type' => $taskType,
        ]);

        $service = app(WorkstationGroupSyncService::class);

        if ($taskType === 'sync_workstation_group_tree') {
            $tree = $payload['tree'] ?? $payload;
            $result = $service->syncPhysicalTree($tree);
        } else {
            $result = $service->syncLogicalGroup($payload);
        }

        $resultArray = $result->toArray();

        Log::info('SyncWorkstationGroupJob: Sync completed', [
            'task_id' => $this->task->id,
            'task_type' => $taskType,
            'stats' => $resultArray,
        ]);

        return array_merge($resultArray, [
            'message' => 'Synchronisation terminée avec succès',
        ]);
    }
}
