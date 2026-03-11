<?php

namespace App\Jobs;

use App\Enums\LockReason;
use App\Models\WorkstationGroup;
use App\Repositories\WorkstationGroupRepository;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job pour exécuter la tâche "update_workstation_group" ordonnée par le ControlHub.
 * Seuls les groupes verrouillés CONTROL_HUB peuvent être modifiés.
 * Contourne le verrou du service car le ControlHub est propriétaire du groupe.
 */
class UpdateWorkstationGroupJob extends BaseControlHubJob
{
    /**
     * Exécute la logique métier spécifique à la mise à jour de groupe.
     * 
     * @return array Le résultat de l'exécution
     * @throws \Exception En cas d'erreur
     */
    protected function execute(): array
    {
        $payload = $this->task->payload ?? [];

        Log::info('UpdateWorkstationGroupJob: Processing workstation group update', [
            'task_id' => $this->task->id,
            'controlhub_id' => $payload['controlhub_id'] ?? null,
        ]);

        if (empty($payload['controlhub_id'])) {
            throw new \InvalidArgumentException('Le controlhub_id du groupe est requis');
        }

        $controlhubId = $payload['controlhub_id'];
        $group = WorkstationGroup::where('controlhub_id', $controlhubId)->first();
        if (! $group) {
            throw new \RuntimeException("Groupe non trouvé avec controlhub_id: {$controlhubId}");
        }

        // Double-check : seuls les groupes CONTROL_HUB peuvent être modifiés via cette API
        if ($group->locked !== LockReason::CONTROL_HUB->value) {
            throw new \RuntimeException(
                "Le groupe '{$group->name}' n'est pas géré par le ControlHub (locked={$group->locked})"
            );
        }

        // Préparer les données de mise à jour
        $updateData = [];

        if (! empty($payload['name']) && $payload['name'] !== $group->name) {
            $updateData['name'] = $payload['name'];
        }
        if (array_key_exists('display_name', $payload)) {
            $updateData['display_name'] = $payload['display_name'];
        }
        if (array_key_exists('description', $payload)) {
            $updateData['description'] = $payload['description'];
        }
        if (array_key_exists('parent_id', $payload)) {
            $updateData['parent_id'] = $payload['parent_id'];
        }
        if (array_key_exists('is_active', $payload)) {
            $updateData['is_active'] = $payload['is_active'];
        }
        if (array_key_exists('controlhub_version', $payload)) {
            $updateData['controlhub_version'] = $payload['controlhub_version'];
        }

        if (empty($updateData)) {
            return [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'updated' => false,
                'message' => 'Aucune modification à appliquer',
            ];
        }

        // Mise à jour directe via le repository (contourne le verrou du service)
        // L'Observer gère la sync AD automatiquement
        $repository = app(WorkstationGroupRepository::class);

        DB::transaction(function () use ($group, $updateData, $repository) {
            $repository->updateGroup($group, $updateData);

            Log::info('UpdateWorkstationGroupJob: Group updated', [
                'group_id' => $group->id,
                'updated_fields' => array_keys($updateData),
            ]);
        });

        $group->refresh();

        // Sync shortcuts si fournis
        $shortcutsCount = null;
        if (array_key_exists('resolved_shortcut_ids', $payload)) {
            $group->shortcuts()->sync($payload['resolved_shortcut_ids']);
            $shortcutsCount = count($payload['resolved_shortcut_ids']);

            Log::info('UpdateWorkstationGroupJob: Shortcuts synced', [
                'task_id' => $this->task->id,
                'group_id' => $group->id,
                'shortcut_ids' => $payload['resolved_shortcut_ids'],
            ]);
        }

        // Sync app_profiles si fournis
        $appProfilesCount = null;
        if (array_key_exists('resolved_app_profile_ids', $payload)) {
            $group->appProfiles()->sync($payload['resolved_app_profile_ids']);
            $appProfilesCount = count($payload['resolved_app_profile_ids']);

            Log::info('UpdateWorkstationGroupJob: AppProfiles synced', [
                'task_id' => $this->task->id,
                'group_id' => $group->id,
                'app_profile_ids' => $payload['resolved_app_profile_ids'],
            ]);
        }

        $result = [
            'group_id' => $group->id,
            'group_name' => $group->name,
            'updated' => true,
            'updated_fields' => array_keys($updateData),
            'message' => 'Groupe de machines mis à jour avec succès',
        ];

        if ($shortcutsCount !== null) {
            $result['shortcuts_count'] = $shortcutsCount;
        }
        if ($appProfilesCount !== null) {
            $result['app_profiles_count'] = $appProfilesCount;
        }

        return $result;
    }
}
