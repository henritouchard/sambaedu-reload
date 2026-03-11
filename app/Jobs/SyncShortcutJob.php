<?php

namespace App\Jobs;

use App\Models\Shortcut;
use App\Models\WorkstationGroup;
use App\Services\ShortcutsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Job pour synchroniser (upsert) un raccourci depuis le ControlHub.
 *
 * Logique :
 * - Si le raccourci existe (par controlhub_id) et est à jour → rien
 * - Si le raccourci existe et n'est pas à jour → mise à jour
 * - Si le raccourci n'existe pas → création
 *
 * Format payload :
 * - controlhub_version : timestamp de version
 * - icon : data URI base64 (ex: "data:image/png;base64,...") ou nom de fichier
 * - windows/linux : { link, args, path, workdir, startupwmclass(linux) }
 * - category, description, is_active, is_url, metadata
 */
class SyncShortcutJob extends BaseControlHubJob
{
    /**
     * @return array Le résultat de l'exécution
     * @throws \Exception En cas d'erreur
     */
    protected function execute(): array
    {
        $payload = $this->task->payload ?? [];

        Log::info('SyncShortcutJob: Processing shortcut sync', [
            'task_id' => $this->task->id,
            'controlhub_id' => $payload['controlhub_id'] ?? null,
        ]);

        if (empty($payload['name'])) {
            throw new \InvalidArgumentException('Le nom du raccourci est requis');
        }

        $controlhubId = $payload['controlhub_id'] ?? null;
        if (! $controlhubId) {
            throw new \InvalidArgumentException('Le controlhub_id est requis');
        }

        $controlhubVersion = $payload['controlhub_version'] ?? null;
        $existing = Shortcut::where('controlhub_id', (string) $controlhubId)->first();

        if ($existing && $this->isUpToDate($existing->controlhub_version, $controlhubVersion)) {
            $this->syncWorkstationGroups($existing, $payload);

            Log::info('SyncShortcutJob: Shortcut already up-to-date', [
                'controlhub_id' => $controlhubId,
                'shortcut_id' => $existing->id,
            ]);

            return [
                'action' => 'unchanged',
                'asset_id' => $existing->key,
                'controlhub_id' => $existing->controlhub_id,
                'shortcut_name' => $existing->name,
                'message' => 'Raccourci déjà à jour',
            ];
        }

        if ($existing) {
            return $this->updateExisting($existing, $payload);
        }

        return $this->createNew($payload);
    }

    /**
     * Met à jour un raccourci existant.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function updateExisting(Shortcut $shortcut, array $payload): array
    {
        $this->applyPayloadToShortcut($shortcut, $payload);
        $this->processIcon($shortcut, $payload);
        $shortcut->save();
        $this->syncWorkstationGroups($shortcut, $payload);

        Log::info('SyncShortcutJob: Shortcut updated', [
            'controlhub_id' => $shortcut->controlhub_id,
            'shortcut_id' => $shortcut->id,
        ]);

        return [
            'action' => 'updated',
            'asset_id' => $shortcut->key,
            'controlhub_id' => $shortcut->controlhub_id,
            'shortcut_name' => $shortcut->name,
            'message' => 'Raccourci mis à jour avec succès',
        ];
    }

    /**
     * Crée un nouveau raccourci.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function createNew(array $payload): array
    {
        $shortcut = new Shortcut();
        $shortcut->controlhub_id = (string) $payload['controlhub_id'];
        $shortcut->key = $payload['key'] ?? uniqid();
        $shortcut->is_global = true;

        $this->applyPayloadToShortcut($shortcut, $payload);
        $this->processIcon($shortcut, $payload);
        $shortcut->save();
        $this->syncWorkstationGroups($shortcut, $payload);

        Log::info('SyncShortcutJob: Shortcut created', [
            'controlhub_id' => $shortcut->controlhub_id,
            'shortcut_id' => $shortcut->id,
        ]);

        return [
            'action' => 'created',
            'asset_id' => $shortcut->key,
            'controlhub_id' => $shortcut->controlhub_id,
            'shortcut_name' => $shortcut->name,
            'message' => 'Raccourci créé avec succès',
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Mapping payload → modèle
    // ═══════════════════════════════════════════════════════════════

    /**
     * Applique les champs du payload normalisé (flat) au modèle Shortcut.
     *
     * @param array<string, mixed> $payload
     */
    private function applyPayloadToShortcut(Shortcut $shortcut, array $payload): void
    {
        // Champs directs
        $directFields = [
            'name', 'owner', 'place',
            'category', 'description',
            'is_active', 'is_url', 'metadata',
        ];

        foreach ($directFields as $field) {
            if (array_key_exists($field, $payload)) {
                $shortcut->{$field} = $payload[$field];
            }
        }

        // controlhub_version
        if (array_key_exists('controlhub_version', $payload)) {
            $shortcut->controlhub_version = $payload['controlhub_version'];
        }

        // Champs Windows (normalisés en flat par le controller)
        $windowsFields = [
            'windows_link', 'windows_args', 'windows_path', 'windows_workdir',
        ];
        foreach ($windowsFields as $field) {
            if (array_key_exists($field, $payload)) {
                $shortcut->{$field} = $payload[$field];
            }
        }

        // Champs Linux (normalisés en flat par le controller)
        $linuxFields = [
            'linux_link', 'linux_args', 'linux_path', 'linux_startupwmclass', 'linux_workdir',
        ];
        foreach ($linuxFields as $field) {
            if (array_key_exists($field, $payload)) {
                $shortcut->{$field} = $payload[$field];
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Gestion de l'icône
    // ═══════════════════════════════════════════════════════════════

    /**
     * Traite le champ icon du payload.
     *
     * Accepte :
     * - data URI base64 : "data:image/png;base64,iVBOR..." → décode et sauvegarde sur disque
     * - nom de fichier simple : "firefox.ico" → stocké tel quel dans icon_path
     *
     * @param array<string, mixed> $payload
     */
    private function processIcon(Shortcut $shortcut, array $payload): void
    {
        if (! array_key_exists('icon', $payload) || empty($payload['icon'])) {
            return;
        }

        $iconValue = $payload['icon'];

        // Détection data URI base64
        if (preg_match('/^data:image\/([a-zA-Z0-9+.-]+);base64,(.+)$/s', $iconValue, $matches)) {
            $mimeSubtype = $matches[1];
            $base64Data = $matches[2];
            $iconPath = $this->saveIconFromBase64($base64Data, $mimeSubtype, $shortcut->name);
            if ($iconPath) {
                $shortcut->icon_path = $iconPath;
                $shortcut->windows_icon = $iconPath;
                $shortcut->linux_icon = $iconPath;
            }

            return;
        }

        // Sinon c'est un nom de fichier simple
        $shortcut->icon_path = $iconValue;
    }

    /**
     * Décode une icône base64 et la sauvegarde sur disque via ShortcutsService.
     */
    private function saveIconFromBase64(string $base64Data, string $mimeSubtype, string $shortcutName): ?string
    {
        try {
            $mimeType = 'image/' . $mimeSubtype;
            $extension = match ($mimeSubtype) {
                'png' => 'png',
                'jpeg', 'jpg' => 'jpg',
                'gif' => 'gif',
                'webp' => 'webp',
                'svg+xml' => 'svg',
                'bmp' => 'bmp',
                'x-icon', 'vnd.microsoft.icon' => 'ico',
                default => 'png',
            };

            $imageData = base64_decode($base64Data, true);
            if ($imageData === false) {
                Log::warning('SyncShortcutJob: Invalid base64 data for icon');

                return null;
            }

            $tempFilePath = sys_get_temp_dir() . '/' . uniqid('shortcut_icon_') . '.' . $extension;
            file_put_contents($tempFilePath, $imageData);

            $uploadedFile = new UploadedFile(
                $tempFilePath,
                basename($tempFilePath),
                $mimeType,
                null,
                true
            );

            $shortcutsService = app(ShortcutsService::class);
            $iconPath = $shortcutsService->handleIconUpload($uploadedFile, $shortcutName);

            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }

            return $iconPath;

        } catch (\Exception $e) {
            Log::error('SyncShortcutJob: Error saving icon from base64', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Relations
    // ═══════════════════════════════════════════════════════════════

    /**
     * Sync les workstation_groups par controlhub_id.
     *
     * @param array<string, mixed> $payload
     */
    private function syncWorkstationGroups(Shortcut $shortcut, array $payload): void
    {
        if (! array_key_exists('workstation_groups', $payload)) {
            return;
        }

        $groupIds = [];
        foreach ($payload['workstation_groups'] as $groupRef) {
            $chId = is_array($groupRef) ? ($groupRef['controlhub_id'] ?? null) : $groupRef;
            if (! $chId) {
                continue;
            }
            $group = WorkstationGroup::where('controlhub_id', (string) $chId)->first();
            if ($group) {
                $groupIds[] = $group->id;
            } else {
                Log::info('SyncShortcutJob: workstation_group not found', ['controlhub_id' => $chId]);
            }
        }

        $shortcut->workstationGroups()->sync($groupIds);
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
