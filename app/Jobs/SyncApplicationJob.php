<?php

namespace App\Jobs;

use App\Models\Application;
use Illuminate\Support\Facades\Log;

/**
 * Job pour synchroniser (upsert) une application depuis le ControlHub.
 *
 * Logique :
 * - Si l'application existe (par controlhub_id) et est à jour → rien
 * - Si l'application existe et n'est pas à jour → mise à jour
 * - Si l'application n'existe pas → création
 */
class SyncApplicationJob extends BaseControlHubJob
{
    /**
     * @return array Le résultat de l'exécution
     * @throws \Exception En cas d'erreur
     */
    protected function execute(): array
    {
        $payload = $this->task->payload ?? [];

        Log::info('SyncApplicationJob: Processing application sync', [
            'task_id' => $this->task->id,
            'controlhub_id' => $payload['controlhub_id'] ?? null,
            'app_id' => $payload['app_id'] ?? null,
        ]);

        $controlhubId = $payload['controlhub_id'] ?? null;
        if (!$controlhubId) {
            throw new \InvalidArgumentException('Le controlhub_id est requis');
        }

        if (empty($payload['app_id'])) {
            throw new \InvalidArgumentException("L'app_id est requis");
        }

        $controlhubVersion = $payload['controlhub_version'] ?? null;
        $existing = Application::where('controlhub_id', (string) $controlhubId)->first();

        if ($existing && $this->isUpToDate($existing->controlhub_version, $controlhubVersion)) {
            Log::info('SyncApplicationJob: Application already up-to-date', [
                'controlhub_id' => $controlhubId,
                'app_id' => $existing->app_id,
            ]);

            return [
                'action' => 'unchanged',
                'asset_id' => $existing->app_id,
                'controlhub_id' => $existing->controlhub_id,
                'app_name' => $existing->name,
                'message' => 'Application déjà à jour',
            ];
        }

        if ($existing) {
            return $this->updateExisting($existing, $payload);
        }

        return $this->createNew($payload);
    }

    /**
     * Met à jour une application existante.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function updateExisting(Application $application, array $payload): array
    {
        $this->applyPayloadToApplication($application, $payload);
        $application->save();

        Log::info('SyncApplicationJob: Application updated', [
            'controlhub_id' => $application->controlhub_id,
            'app_id' => $application->app_id,
        ]);

        return [
            'action' => 'updated',
            'asset_id' => $application->app_id,
            'controlhub_id' => $application->controlhub_id,
            'app_name' => $application->name,
            'message' => 'Application mise à jour avec succès',
        ];
    }

    /**
     * Crée une nouvelle application.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function createNew(array $payload): array
    {
        // Vérifier si une application avec le même app_id existe déjà (sans controlhub_id)
        $existing = Application::where('app_id', $payload['app_id'])->first();

        if ($existing) {
            // Rattacher l'application existante au ControlHub
            $existing->controlhub_id = (string) $payload['controlhub_id'];
            $existing->managed_by_control_hub = true;
            $this->applyPayloadToApplication($existing, $payload);
            $existing->save();

            Log::info('SyncApplicationJob: Existing application linked to ControlHub', [
                'controlhub_id' => $existing->controlhub_id,
                'app_id' => $existing->app_id,
            ]);

            return [
                'action' => 'linked',
                'asset_id' => $existing->app_id,
                'controlhub_id' => $existing->controlhub_id,
                'app_name' => $existing->name,
                'message' => 'Application existante rattachée au ControlHub',
            ];
        }

        $application = new Application();
        $application->controlhub_id = (string) $payload['controlhub_id'];
        $application->managed_by_control_hub = true;

        $this->applyPayloadToApplication($application, $payload);
        $application->save();

        Log::info('SyncApplicationJob: Application created', [
            'controlhub_id' => $application->controlhub_id,
            'app_id' => $application->app_id,
        ]);

        return [
            'action' => 'created',
            'asset_id' => $application->app_id,
            'controlhub_id' => $application->controlhub_id,
            'app_name' => $application->name,
            'message' => 'Application créée avec succès',
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Mapping payload → modèle
    // ═══════════════════════════════════════════════════════════════

    /**
     * Applique les champs du payload au modèle Application.
     *
     * @param array<string, mixed> $payload
     */
    private function applyPayloadToApplication(Application $application, array $payload): void
    {
        $directFields = [
            'app_id',
            'name',
            'version',
            'category',
            'compatibility',
            'branch',
            'xml',
            'xml_url',
            'xml_sha',
            'log_url',
        ];

        foreach ($directFields as $field) {
            if (array_key_exists($field, $payload)) {
                $application->{$field} = $payload[$field];
            }
        }

        // controlhub_version
        if (array_key_exists('controlhub_version', $payload)) {
            $application->controlhub_version = $payload['controlhub_version'];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Utilitaires
    // ═══════════════════════════════════════════════════════════════

    /**
     * Vérifie si l'entité locale est à jour par rapport à la version ControlHub.
     */
    private function isUpToDate(\DateTimeInterface|string|null $localVersion, ?string $remoteVersion): bool
    {
        if ($remoteVersion === null || $localVersion === null) {
            return false;
        }

        $local = $localVersion instanceof \DateTimeInterface
            ? $localVersion->format('Y-m-d\TH:i:s\Z')
            : $localVersion;

        return $local === $remoteVersion;
    }
}
