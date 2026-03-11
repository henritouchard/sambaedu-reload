<?php

namespace App\Jobs;

use App\Models\Shortcut;
use App\Services\ShortcutsService;
use Illuminate\Support\Facades\Log;

/**
 * Job pour exécuter la tâche "update_shortcut" ordonnée par le ControlHub.
 * Met à jour un raccourci existant en base de données via controlhub_id.
 */
class UpdateShortcutJob extends BaseControlHubJob
{
    /**
     * Exécute la logique métier spécifique à la mise à jour de raccourci.
     * 
     * @return array Le résultat de l'exécution
     * @throws \Exception En cas d'erreur
     */
    protected function execute(): array
    {
        $payload = $this->task->payload ?? [];

        Log::info('UpdateShortcutJob: Processing shortcut update', [
            'task_id' => $this->task->id,
            'payload' => $payload,
        ]);

        // Validation du payload
        if (empty($payload['name'])) {
            throw new \InvalidArgumentException('Le nom du raccourci est requis');
        }

        if (empty($payload['controlhub_id'])) {
            throw new \InvalidArgumentException('Le controlhub_id est requis pour la mise à jour');
        }

        $controlhubId = $payload['controlhub_id'];

        // Trouver le raccourci existant par controlhub_id
        $shortcut = Shortcut::where('controlhub_id', $controlhubId)->first();

        if (!$shortcut) {
            throw new \RuntimeException("Raccourci non trouvé avec controlhub_id: {$controlhubId}");
        }

        // Vérifier que c'est un raccourci ControlHub (is_global)
        if (!$shortcut->is_global) {
            throw new \RuntimeException("Le raccourci '{$shortcut->name}' n'est pas géré par le ControlHub");
        }

        // Mettre à jour les champs fournis dans le payload (merge partiel)
        $updatableFields = [
            'name', 'owner', 'place', 'controlhub_version',
            'windows_link', 'windows_args', 'windows_path',
            'linux_link', 'linux_args', 'linux_path', 'linux_startupwmclass',
        ];

        foreach ($updatableFields as $field) {
            if (array_key_exists($field, $payload)) {
                $shortcut->{$field} = $payload[$field];
            }
        }

        // Gérer l'icône Windows si fournie
        $shortcutsService = app(ShortcutsService::class);

        if (!empty($payload['windows_icon']) && is_array($payload['windows_icon']) && !empty($payload['windows_icon']['data'])) {
            $iconData = $payload['windows_icon'];
            Log::info('UpdateShortcutJob: Processing windows_icon from base64', [
                'task_id' => $this->task->id,
                'mime' => $iconData['mime'] ?? 'unknown',
                'data_length' => strlen($iconData['data']),
            ]);
            $iconPath = $this->saveIconFromBase64WithMime(
                $iconData['data'],
                $iconData['mime'] ?? 'image/png',
                $shortcut->name,
                $shortcutsService
            );
            if ($iconPath) {
                $shortcut->windows_icon = $iconPath;
                $shortcut->icon_path = $iconPath;
            }
        } elseif (!empty($payload['icon_url'])) {
            $iconPath = $this->downloadIconFromUrl($payload['icon_url'], $shortcut->name, $shortcutsService);
            if ($iconPath) {
                $shortcut->windows_icon = $iconPath;
                $shortcut->icon_path = $iconPath;
            }
        } elseif (!empty($payload['icon_data'])) {
            $iconPath = $this->saveIconFromBase64($payload['icon_data'], $shortcut->name, $shortcutsService);
            if ($iconPath) {
                $shortcut->windows_icon = $iconPath;
                $shortcut->icon_path = $iconPath;
            }
        }

        // Gérer l'icône Linux si fournie (format: {data: "base64...", mime: "image/png"})
        if (!empty($payload['linux_icon']) && is_array($payload['linux_icon']) && !empty($payload['linux_icon']['data'])) {
            $linuxIconData = $payload['linux_icon'];
            Log::info('UpdateShortcutJob: Processing linux_icon from base64', [
                'task_id' => $this->task->id,
                'mime' => $linuxIconData['mime'] ?? 'unknown',
                'data_length' => strlen($linuxIconData['data']),
            ]);
            $linuxIconPath = $this->saveIconFromBase64WithMime(
                $linuxIconData['data'],
                $linuxIconData['mime'] ?? 'image/png',
                $shortcut->name . '_linux',
                $shortcutsService
            );
            if ($linuxIconPath) {
                $shortcut->linux_icon = $linuxIconPath;
                if (empty($shortcut->icon_path)) {
                    $shortcut->icon_path = $linuxIconPath;
                }
            }
        }

        $shortcut->save();

        // Synchroniser les workstation groups si fournis (résolution faite dans le contrôleur)
        if (array_key_exists('resolved_workstation_group_ids', $payload)) {
            $shortcut->workstationGroups()->sync($payload['resolved_workstation_group_ids']);

            Log::info('UpdateShortcutJob: Workstation groups synced', [
                'task_id' => $this->task->id,
                'shortcut_id' => $shortcut->id,
                'group_ids' => $payload['resolved_workstation_group_ids'],
            ]);
        }

        Log::info('UpdateShortcutJob: Shortcut updated successfully in DB', [
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
            'message' => 'Raccourci mis à jour avec succès',
        ];
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
                Log::warning('UpdateShortcutJob: Invalid base64 data for icon');
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
            Log::error('UpdateShortcutJob: Error saving icon from base64 with mime', [
                'error' => $e->getMessage(),
                'mime' => $mimeType,
            ]);
            return null;
        }
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
                Log::warning('UpdateShortcutJob: Failed to download icon from URL', [
                    'url' => $url,
                    'status' => $response->getStatusCode()
                ]);
                return null;
            }

            $imageData = $response->getBody()->getContents();
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($imageData);
            $extension = match ($mimeType) {
                'image/png' => 'png',
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'png'
            };

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
            Log::error('UpdateShortcutJob: Error downloading icon from URL', [
                'url' => $url,
                'error' => $e->getMessage()
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
                Log::warning('UpdateShortcutJob: Invalid base64 data for icon');
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
            Log::error('UpdateShortcutJob: Error saving icon from base64', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
