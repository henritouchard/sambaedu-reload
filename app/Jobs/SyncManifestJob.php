<?php

namespace App\Jobs;

use App\Services\ControlHub\SyncManifestService;
use Illuminate\Support\Facades\Log;

/**
 * Job asynchrone pour exécuter le Sync Manifest.
 * Hérite de BaseControlHubJob pour bénéficier de la gestion automatique
 * des statuts, callbacks et retries.
 */
class SyncManifestJob extends BaseControlHubJob
{
    /**
     * Timeout plus long pour le sync manifest (10 minutes).
     */
    public int $timeout = 600;

    /**
     * Exécute la convergence déclarative via SyncManifestService.
     *
     * @return array Le résultat de l'exécution (sera envoyé au ControlHub)
     */
    protected function execute(): array
    {
        $payload = $this->task->payload;
        $manifestVersion = $payload['manifest_version'] ?? now()->toIso8601ZuluString();

        Log::info('SyncManifestJob: Starting manifest sync', [
            'task_id' => $this->task->id,
            'manifest_version' => $manifestVersion,
        ]);

        $service = app(SyncManifestService::class);
        $result = $service->apply($payload, $manifestVersion);

        return $result->toArray();
    }
}
