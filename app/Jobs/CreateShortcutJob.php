<?php

namespace App\Jobs;

use App\Models\Shortcut;
use App\Services\ShortcutsService;
use Illuminate\Support\Facades\Log;

/**
 * Job pour exécuter la tâche "create_shortcut" ordonnée par le ControlHub.
 * Crée un nouveau raccourci en base de données via le modèle Eloquent Shortcut.
 */
class CreateShortcutJob extends BaseControlHubJob
{
    /**
     * Exécute la logique métier spécifique à la création de raccourci.
     * 
     * @return array Le résultat de l'exécution
     * @throws \Exception En cas d'erreur
     */
    protected function execute(): array
    {
        $payload = $this->task->payload ?? [];

        Log::info('CreateShortcutJob: Processing shortcut creation', [
            'task_id' => $this->task->id,
            'payload' => $payload,
        ]);

        // Validation du payload
        if (empty($payload['name'])) {
            throw new \InvalidArgumentException('Le nom du raccourci est requis');
        }

        $controlhubId = $payload['controlhub_id'] ?? null;

        // Si controlhub_id fourni, vérifier qu'il n'existe pas déjà
        if ($controlhubId) {
            $existing = Shortcut::where('controlhub_id', $controlhubId)->first();
            if ($existing) {
                Log::info('CreateShortcutJob: Shortcut with controlhub_id already exists, returning existing', [
                    'task_id' => $this->task->id,
                    'controlhub_id' => $controlhubId,
                    'shortcut_id' => $existing->id,
                ]);
                return [
                    'asset_id' => $existing->key,
                    'controlhub_id' => $existing->controlhub_id,
                    'shortcut_name' => $existing->name,
                    'shortcut_owner' => $existing->owner,
                    'shortcut_place' => $existing->place,
                    'message' => 'Raccourci déjà existant (idempotence)',
                ];
            }
        }

        // Préparer les données du raccourci
        $shortcutData = [
            'controlhub_id' => $controlhubId,
            'controlhub_version' => $payload['controlhub_version'] ?? null,
            'key' => uniqid(),
            'name' => $payload['name'],
            'owner' => $payload['owner'] ?? '',
            'place' => $payload['place'] ?? 'desktop',
            'is_global' => true, // Raccourcis créés via ControlHub sont protégés
            'windows_link' => $payload['windows_link'] ?? '',
            'windows_args' => $payload['windows_args'] ?? '',
            'windows_path' => $payload['windows_path'] ?? '',
            'windows_icon' => '',
            'linux_link' => $payload['linux_link'] ?? '',
            'linux_args' => $payload['linux_args'] ?? '',
            'linux_path' => $payload['linux_path'] ?? '',
            'linux_icon' => '',
            'linux_startupwmclass' => $payload['linux_startupwmclass'] ?? '',
        ];

        Log::info('CreateShortcutJob: shortcutData prepared', [
            'task_id' => $this->task->id,
            'shortcutData' => $shortcutData,
            'has_icon_url' => !empty($payload['icon_url']),
            'has_icon_data' => !empty($payload['icon_data']),
            'has_windows_icon' => !empty($payload['windows_icon']),
            'has_linux_icon' => !empty($payload['linux_icon']),
        ]);

        // Gérer l'icône Windows si fournie (format: {data: "base64...", mime: "image/png"})
        $shortcutsService = app(ShortcutsService::class);

        if (!empty($payload['windows_icon']) && is_array($payload['windows_icon']) && !empty($payload['windows_icon']['data'])) {
            $iconData = $payload['windows_icon'];
            Log::info('CreateShortcutJob: Processing windows_icon from base64', [
                'task_id' => $this->task->id,
                'mime' => $iconData['mime'] ?? 'unknown',
                'data_length' => strlen($iconData['data']),
            ]);
            $iconPath = $this->saveIconFromBase64WithMime(
                $iconData['data'],
                $iconData['mime'] ?? 'image/png',
                $payload['name'],
                $shortcutsService
            );
            if ($iconPath) {
                $shortcutData['windows_icon'] = $iconPath;
                $shortcutData['icon_path'] = $iconPath;
            }
        } elseif (!empty($payload['icon_url'])) {
            $iconPath = $this->downloadIconFromUrl($payload['icon_url'], $payload['name'], $shortcutsService);
            if ($iconPath) {
                $shortcutData['windows_icon'] = $iconPath;
                $shortcutData['icon_path'] = $iconPath;
            }
        } elseif (!empty($payload['icon_data'])) {
            $iconPath = $this->saveIconFromBase64($payload['icon_data'], $payload['name'], $shortcutsService);
            if ($iconPath) {
                $shortcutData['windows_icon'] = $iconPath;
                $shortcutData['icon_path'] = $iconPath;
            }
        }

        // Gérer l'icône Linux si fournie (format: {data: "base64...", mime: "image/png"})
        if (!empty($payload['linux_icon']) && is_array($payload['linux_icon']) && !empty($payload['linux_icon']['data'])) {
            $linuxIconData = $payload['linux_icon'];
            Log::info('CreateShortcutJob: Processing linux_icon from base64', [
                'task_id' => $this->task->id,
                'mime' => $linuxIconData['mime'] ?? 'unknown',
                'data_length' => strlen($linuxIconData['data']),
            ]);
            $linuxIconPath = $this->saveIconFromBase64WithMime(
                $linuxIconData['data'],
                $linuxIconData['mime'] ?? 'image/png',
                $payload['name'] . '_linux',
                $shortcutsService
            );
            if ($linuxIconPath) {
                $shortcutData['linux_icon'] = $linuxIconPath;
                // Si pas d'icône Windows, utiliser l'icône Linux comme icon_path par défaut
                if (empty($shortcutData['icon_path'])) {
                    $shortcutData['icon_path'] = $linuxIconPath;
                }
            }
        }

        // Créer le raccourci en base
        $shortcut = Shortcut::create($shortcutData);

        // Associer les workstation groups si fournis (résolution faite dans le contrôleur)
        if (!empty($payload['resolved_workstation_group_ids'])) {
            $shortcut->workstationGroups()->sync($payload['resolved_workstation_group_ids']);

            Log::info('CreateShortcutJob: Workstation groups synced', [
                'task_id' => $this->task->id,
                'shortcut_id' => $shortcut->id,
                'group_ids' => $payload['resolved_workstation_group_ids'],
            ]);
        }

        Log::info('CreateShortcutJob: Shortcut created successfully in DB', [
            'task_id' => $this->task->id,
            'shortcut_id' => $shortcut->id,
            'controlhub_id' => $shortcut->controlhub_id,
            'shortcut_name' => $shortcut->name,
            'workstation_groups_count' => count($payload['resolved_workstation_group_ids'] ?? []),
        ]);

        return [
            'asset_id' => $shortcut->key,
            'controlhub_id' => $shortcut->controlhub_id,
            'shortcut_name' => $shortcut->name,
            'shortcut_owner' => $shortcut->owner,
            'shortcut_place' => $shortcut->place,
            'workstation_groups_count' => count($payload['resolved_workstation_group_ids'] ?? []),
            'message' => 'Raccourci créé avec succès',
        ];
    }

    /**
     * Télécharger une icône depuis une URL
     */
    private function downloadIconFromUrl(string $url, string $shortcutName, ShortcutsService $shortcutsService): ?string
    {
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get($url, [
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; SambaEdu/1.0)'
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::warning('CreateShortcutJob: Failed to download icon from URL', [
                    'url' => $url,
                    'status' => $response->getStatusCode()
                ]);
                return null;
            }

            $imageData = $response->getBody()->getContents();

            $extension = $this->getImageExtension($imageData);
            $tempFilePath = sys_get_temp_dir() . '/' . uniqid('shortcut_icon_') . '.' . $extension;
            file_put_contents($tempFilePath, $imageData);

            $uploadedFile = new \Illuminate\Http\UploadedFile(
                $tempFilePath,
                basename($tempFilePath),
                mime_content_type($tempFilePath),
                null,
                true
            );

            $iconPath = $shortcutsService->handleIconUpload($uploadedFile, $shortcutName);

            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }

            return $iconPath;

        } catch (\Exception $e) {
            Log::error('CreateShortcutJob: Error downloading icon from URL', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Sauvegarder une icône depuis des données base64 avec mime type explicite
     */
    private function saveIconFromBase64WithMime(string $base64Data, string $mimeType, string $shortcutName, ShortcutsService $shortcutsService): ?string
    {
        try {
            $extension = match ($mimeType) {
                'image/png' => 'png',
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg',
                'image/bmp' => 'bmp',
                'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
                default => 'png'
            };

            $imageData = base64_decode($base64Data);
            if ($imageData === false) {
                Log::warning('CreateShortcutJob: Invalid base64 data for icon');
                return null;
            }

            $tempFilePath = sys_get_temp_dir() . '/' . uniqid('shortcut_icon_') . '.' . $extension;
            file_put_contents($tempFilePath, $imageData);

            $uploadedFile = new \Illuminate\Http\UploadedFile(
                $tempFilePath,
                basename($tempFilePath),
                $mimeType,
                null,
                true
            );

            $iconPath = $shortcutsService->handleIconUpload($uploadedFile, $shortcutName);

            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }

            return $iconPath;

        } catch (\Exception $e) {
            Log::error('CreateShortcutJob: Error saving icon from base64 with mime', [
                'error' => $e->getMessage(),
                'mime' => $mimeType,
            ]);
            return null;
        }
    }

    /**
     * Sauvegarder une icône depuis des données base64 (ancien format)
     */
    private function saveIconFromBase64(string $base64Data, string $shortcutName, ShortcutsService $shortcutsService): ?string
    {
        try {
            if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
                $extension = $matches[1];
                $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
            } else {
                $extension = 'png';
            }

            $imageData = base64_decode($base64Data);
            if ($imageData === false) {
                Log::warning('CreateShortcutJob: Invalid base64 data for icon');
                return null;
            }

            $tempFilePath = sys_get_temp_dir() . '/' . uniqid('shortcut_icon_') . '.' . $extension;
            file_put_contents($tempFilePath, $imageData);

            $uploadedFile = new \Illuminate\Http\UploadedFile(
                $tempFilePath,
                basename($tempFilePath),
                'image/' . $extension,
                null,
                true
            );

            $iconPath = $shortcutsService->handleIconUpload($uploadedFile, $shortcutName);

            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }

            return $iconPath;

        } catch (\Exception $e) {
            Log::error('CreateShortcutJob: Error saving icon from base64', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Déterminer l'extension d'une image depuis ses données binaires
     */
    private function getImageExtension(string $imageData): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageData);

        return match ($mimeType) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
            'image/ico' => 'ico',
            default => 'png'
        };
    }
}
