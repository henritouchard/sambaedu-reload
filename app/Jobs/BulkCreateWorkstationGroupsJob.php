<?php

namespace App\Jobs;

use App\Enums\LockReason;
use App\Models\WorkstationGroup;
use App\Repositories\WorkstationGroupRepository;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Support\Facades\Log;

/**
 * Job pour traiter un batch de WorkstationGroups depuis le ControlHub.
 *
 * Reçoit un arbre de groupes (payload.groups[]) et les crée ou met à jour
 * récursivement en profondeur (depth-first), garantissant que chaque parent
 * est traité avant ses enfants.
 *
 * Logique d'upsert :
 * - controlhub_id présent + groupe trouvé en base → mise à jour du groupe existant
 * - sinon (controlhub_id absent, null, ou non trouvé en base) → création du groupe (locked=control_hub, managed_by_control_hub=true)
 *
 * Les enfants héritent automatiquement du parent_id de leur parent traité.
 */
class BulkCreateWorkstationGroupsJob extends BaseControlHubJob
{
    /**
     * Timeout plus long pour les batchs (10 minutes)
     */
    public int $timeout = 600;

    /**
     * Exécute le batch : parcourt l'arbre de groupes en profondeur.
     */
    protected function execute(): array
    {
        $payload = $this->task->payload ?? [];
        $groups = $payload['groups'] ?? [];

        Log::info('BulkCreateWorkstationGroupsJob: Starting batch execution', [
            'task_id' => $this->task->id,
            'root_groups_count' => count($groups),
        ]);

        $results = [];
        $this->processGroupsRecursive($groups, null, $results);

        $successCount = count(array_filter($results, fn(array $r): bool => $r['status'] === 'success'));
        $failedCount = count(array_filter($results, fn(array $r): bool => $r['status'] === 'failed'));

        Log::info('BulkCreateWorkstationGroupsJob: Batch execution completed', [
            'task_id' => $this->task->id,
            'success' => $successCount,
            'failed' => $failedCount,
            'total' => count($results),
        ]);

        if ($failedCount > 0) {
            $this->task->update([
                'result' => [
                    'groups' => $results,
                    'summary' => [
                        'total' => count($results),
                        'success' => $successCount,
                        'failed' => $failedCount,
                    ],
                ],
            ]);

            throw new \RuntimeException(
                "Batch partiellement échoué : {$successCount} réussi(s), {$failedCount} échoué(s)"
            );
        }

        return [
            'groups' => $results,
            'summary' => [
                'total' => count($results),
                'success' => $successCount,
                'failed' => $failedCount,
            ],
            'message' => "Batch exécuté avec succès : {$successCount} groupe(s) traité(s)",
        ];
    }

    /**
     * Parcourt l'arbre de groupes en profondeur.
     *
     * Pour chaque groupe :
     * 1. Upsert (create ou update) le groupe
     * 2. Récupère l'id du groupe traité
     * 3. Traite récursivement les children avec ce parent_id
     *
     * @param array $groups Liste de groupes à traiter
     * @param int|null $parentId ID du parent (null pour les racines)
     * @param array &$results Tableau de résultats (passé par référence)
     */
    private function processGroupsRecursive(array $groups, ?int $parentId, array &$results): void
    {
        foreach ($groups as $groupData) {
            $name = $groupData['name'] ?? 'unknown';
            $controlhubId = $groupData['controlhub_id'] ?? null;
            $children = $groupData['children'] ?? [];

            Log::info('BulkCreateWorkstationGroupsJob: Processing group', [
                'task_id' => $this->task->id,
                'name' => $name,
                'controlhub_id' => $controlhubId,
                'parent_id' => $parentId ?? $groupData['parent_id'] ?? null,
                'action' => $controlhubId ? 'update' : 'create',
            ]);

            try {
                $result = $this->upsertGroup($groupData, $parentId);
                $processedId = $result['group_id'];

                $results[] = [
                    'name' => $name,
                    'action' => $result['action'],
                    'status' => 'success',
                    'controlhub_id' => $result['controlhub_id'],
                    'group_id' => $processedId,
                    'group_name' => $result['group_name'],
                ];

                // Traiter les enfants récursivement avec le parent_id du groupe traité
                if (!empty($children)) {
                    $this->processGroupsRecursive($children, $processedId, $results);
                }

            } catch (\Exception $e) {
                Log::error('BulkCreateWorkstationGroupsJob: Failed to process group', [
                    'task_id' => $this->task->id,
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'name' => $name,
                    'action' => $controlhubId ? 'update' : 'create',
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    /**
     * Crée ou met à jour un WorkstationGroup.
     *
     * - controlhub_id présent + groupe trouvé en base : mise à jour (seuls les groupes ControlHub sont modifiables)
     * - sinon (controlhub_id absent, null, ou non trouvé en base) : création (locked=control_hub, managed_by_control_hub=true)
     *
     * @param array $data Données du groupe
     * @param int|null $parentId ID du parent résolu par la récursion (prioritaire sur data.parent_id)
     * @return array Résultat avec group_id, group_name, action
     */
    private function upsertGroup(array $data, ?int $parentId): array
    {
        $controlhubId = $data['controlhub_id'] ?? null;

        // Le parent_id de la récursion est prioritaire, sinon on prend celui du payload
        $resolvedParentId = $parentId ?? ($data['parent_id'] ?? null);

        // Vrai upsert : si controlhub_id fourni, chercher en base
        // Si trouvé → update, sinon → create (avec le controlhub_id)
        if ($controlhubId) {
            $existing = WorkstationGroup::where('controlhub_id', $controlhubId)->first();
            if ($existing) {
                return $this->updateExistingGroup($controlhubId, $data, $resolvedParentId);
            }
        }

        return $this->createNewGroup($data, $resolvedParentId);
    }

    /**
     * Crée un nouveau WorkstationGroup verrouillé CONTROL_HUB.
     */
    private function createNewGroup(array $data, ?int $parentId): array
    {
        $name = $data['name'] ?? null;
        if (empty($name)) {
            throw new \InvalidArgumentException('Le nom du groupe est requis');
        }

        // Vérifier que le groupe n'existe pas déjà
        $existing = WorkstationGroup::where('name', $name)->first();
        if ($existing) {
            throw new \RuntimeException("Un groupe avec le nom '{$name}' existe déjà (id: {$existing->id})");
        }

        $groupData = [
            'controlhub_id' => $data['controlhub_id'] ?? null,
            'name' => $name,
            'is_physical' => $data['is_physical'] ?? true,
            'display_name' => $data['display_name'] ?? null,
            'description' => $data['description'] ?? null,
            'app_profile_name' => $data['app_profile_name'] ?? null,
            'parent_id' => $parentId,
            'is_active' => true,
            'locked' => LockReason::CONTROL_HUB->value,
            'managed_by_control_hub' => true,
        ];

        $service = app(WorkstationGroupService::class);
        $group = $service->createGroup($groupData);

        return [
            'controlhub_id' => $group->controlhub_id,
            'group_id' => $group->id,
            'group_name' => $group->name,
            'action' => 'created',
        ];
    }

    /**
     * Met à jour un WorkstationGroup existant géré par le ControlHub.
     */
    private function updateExistingGroup(string $controlhubId, array $data, ?int $parentId): array
    {
        $group = WorkstationGroup::where('controlhub_id', $controlhubId)->first();
        if (!$group) {
            throw new \RuntimeException("Aucun groupe avec controlhub_id={$controlhubId} n'existe");
        }

        if ($group->locked !== LockReason::CONTROL_HUB->value) {
            throw new \RuntimeException("Le groupe '{$group->name}' (controlhub_id={$controlhubId}) n'est pas géré par le ControlHub");
        }

        $updateData = [];

        if (!empty($data['name']) && $data['name'] !== $group->name) {
            // Vérifier unicité du nouveau nom
            $nameConflict = WorkstationGroup::where('name', $data['name'])
                ->where('id', '!=', $group->id)
                ->exists();
            if ($nameConflict) {
                throw new \RuntimeException("Un groupe avec le nom '{$data['name']}' existe déjà");
            }
            $updateData['name'] = $data['name'];
        }
        if (array_key_exists('display_name', $data)) {
            $updateData['display_name'] = $data['display_name'];
        }
        if (array_key_exists('description', $data)) {
            $updateData['description'] = $data['description'];
        }
        if (array_key_exists('app_profile_name', $data)) {
            $updateData['app_profile_name'] = $data['app_profile_name'];
        }
        if (array_key_exists('is_active', $data)) {
            $updateData['is_active'] = $data['is_active'];
        }
        if ($parentId !== null) {
            $updateData['parent_id'] = $parentId;
        } elseif (array_key_exists('parent_id', $data)) {
            $updateData['parent_id'] = $data['parent_id'];
        }

        if (!empty($updateData)) {
            $repository = app(WorkstationGroupRepository::class);
            $repository->updateGroup($group, $updateData);
            $group->refresh();
        }

        return [
            'controlhub_id' => $group->controlhub_id,
            'group_id' => $group->id,
            'group_name' => $group->name,
            'action' => 'updated',
            'updated_fields' => array_keys($updateData),
        ];
    }
}
