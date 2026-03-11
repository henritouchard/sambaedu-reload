<?php

namespace App\Jobs;

use App\Enums\LockReason;
use App\Models\WorkstationGroup;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Support\Facades\Log;

/**
 * Job pour exécuter la tâche "create_workstation_group" ordonnée par le ControlHub.
 * Crée un WorkstationGroup verrouillé avec la raison CONTROL_HUB.
 */
class CreateWorkstationGroupJob extends BaseControlHubJob
{
    /**
     * Exécute la logique métier spécifique à la création de groupe.
     * 
     * @return array Le résultat de l'exécution
     * @throws \Exception En cas d'erreur
     */
    protected function execute(): array
    {
        $payload = $this->task->payload ?? [];

        Log::info('CreateWorkstationGroupJob: Processing workstation group creation', [
            'task_id' => $this->task->id,
            'group_name' => $payload['name'] ?? null,
        ]);

        if (empty($payload['name'])) {
            throw new \InvalidArgumentException('Le nom du groupe est requis');
        }

        // Vérifier que le groupe n'existe pas déjà (double-check dans le job)
        $existing = WorkstationGroup::where('name', $payload['name'])->first();
        if ($existing) {
            throw new \RuntimeException("Un groupe avec le nom '{$payload['name']}' existe déjà (id: {$existing->id})");
        }

        // Préparer les données avec lock CONTROL_HUB
        $groupData = [
            'name' => $payload['name'],
            'is_physical' => $payload['is_physical'] ?? true,
            'display_name' => $payload['display_name'] ?? null,
            'description' => $payload['description'] ?? null,
            'parent_id' => $payload['parent_id'] ?? null,
            'is_active' => true,
            'locked' => LockReason::CONTROL_HUB->value,
            'managed_by_control_hub' => true,
            'controlhub_id' => $payload['controlhub_id'],
            'controlhub_version' => $payload['controlhub_version'] ?? null,
        ];

        $service = app(WorkstationGroupService::class);
        $group = $service->createGroup($groupData);

        // Associer les raccourcis si fournis
        $shortcutsCount = 0;
        if (! empty($payload['resolved_shortcut_ids'])) {
            $group->shortcuts()->sync($payload['resolved_shortcut_ids']);
            $shortcutsCount = count($payload['resolved_shortcut_ids']);

            Log::info('CreateWorkstationGroupJob: Shortcuts synced', [
                'task_id' => $this->task->id,
                'group_id' => $group->id,
                'shortcut_ids' => $payload['resolved_shortcut_ids'],
            ]);
        }

        // Associer les app_profiles si fournis
        $appProfilesCount = 0;
        if (! empty($payload['resolved_app_profile_ids'])) {
            $group->appProfiles()->sync($payload['resolved_app_profile_ids']);
            $appProfilesCount = count($payload['resolved_app_profile_ids']);

            Log::info('CreateWorkstationGroupJob: AppProfiles synced', [
                'task_id' => $this->task->id,
                'group_id' => $group->id,
                'app_profile_ids' => $payload['resolved_app_profile_ids'],
            ]);
        }

        Log::info('CreateWorkstationGroupJob: Group created successfully', [
            'task_id' => $this->task->id,
            'group_id' => $group->id,
            'group_name' => $group->name,
            'locked' => $group->locked,
            'managed_by_control_hub' => $group->managed_by_control_hub,
        ]);

        return [
            'group_id' => $group->id,
            'group_name' => $group->name,
            'is_physical' => $group->is_physical,
            'locked' => $group->locked,
            'managed_by_control_hub' => $group->managed_by_control_hub,
            'ad_synced' => $group->isSyncedWithAd(),
            'shortcuts_count' => $shortcutsCount,
            'app_profiles_count' => $appProfilesCount,
            'message' => 'Groupe de machines créé avec succès',
        ];
    }
}
