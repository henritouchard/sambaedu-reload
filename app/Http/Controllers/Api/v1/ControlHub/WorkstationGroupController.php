<?php

namespace App\Http\Controllers\Api\v1\ControlHub;

use App\Enums\LockReason;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteWorkstationGroupJob;
use App\Jobs\SyncWorkstationGroupJob;
use App\Jobs\BulkCreateWorkstationGroupsJob;
use App\Models\ControlHubTask;
use App\Models\WorkstationGroup;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WorkstationGroupController extends Controller
{
    /**
     * Règles de validation communes pour un noeud de shortcut imbriqué.
     *
     * @return array<string, string>
     */
    private function shortcutRules(string $prefix): array
    {
        return [
            "{$prefix}.controlhub_id" => 'required|uuid',
            "{$prefix}.controlhub_version" => 'nullable|date',
            "{$prefix}.name" => 'required|string|max:255',
            "{$prefix}.owner" => 'nullable|string|max:255',
            "{$prefix}.place" => 'nullable|string|in:desktop,startup,taskbar',
            "{$prefix}.windows" => 'nullable|array',
            "{$prefix}.windows.link" => 'nullable|string',
            "{$prefix}.windows.args" => 'nullable|string',
            "{$prefix}.windows.path" => 'nullable|string',
            "{$prefix}.windows.icon" => 'nullable|string',
            "{$prefix}.linux" => 'nullable|array',
            "{$prefix}.linux.link" => 'nullable|string',
            "{$prefix}.linux.args" => 'nullable|string',
            "{$prefix}.linux.path" => 'nullable|string',
            "{$prefix}.linux.icon" => 'nullable|string',
            "{$prefix}.linux.startupwmclass" => 'nullable|string',
            "{$prefix}.workstation_groups" => 'nullable|array',
            "{$prefix}.workstation_groups.*.controlhub_id" => 'required|uuid',
        ];
    }

    /**
     * Règles de validation pour une application imbriquée dans un app_profile.
     *
     * @return array<string, string>
     */
    private function applicationRules(string $prefix): array
    {
        return [
            "{$prefix}.controlhub_id" => 'nullable|uuid',
            "{$prefix}.app_id" => 'required|string|max:255',
            "{$prefix}.name" => 'nullable|string|max:255',
            "{$prefix}.version" => 'nullable|string|max:100',
            "{$prefix}.category" => 'nullable|string|max:100',
            "{$prefix}.compatibility" => 'nullable|string|max:100',
            "{$prefix}.branch" => 'nullable|string|max:100',
            "{$prefix}.xml" => 'nullable|string',
            "{$prefix}.xml_url" => 'nullable|string',
            "{$prefix}.xml_sha" => 'nullable|string|max:255',
            "{$prefix}.log_url" => 'nullable|string',
        ];
    }

    /**
     * Règles de validation pour un app_profile imbriqué.
     *
     * @return array<string, string>
     */
    private function appProfileRules(string $prefix): array
    {
        return [
            "{$prefix}.controlhub_id" => 'required|uuid',
            "{$prefix}.controlhub_version" => 'nullable|date',
            "{$prefix}.name" => 'required|string|max:255',
            "{$prefix}.display_name" => 'nullable|string|max:255',
            "{$prefix}.description" => 'nullable|string|max:1000',
            "{$prefix}.applications" => 'nullable|array',
            "{$prefix}.applications.*" => 'array',
        ];
    }

    /**
     * Règles de validation communes pour un noeud de groupe.
     *
     * @return array<string, string>
     */
    private function groupNodeRules(string $prefix): array
    {
        return [
            "{$prefix}.controlhub_id" => 'required|uuid',
            "{$prefix}.controlhub_version" => 'nullable|date',
            "{$prefix}.name" => 'required|string|max:255',
            "{$prefix}.display_name" => 'nullable|string|max:255',
            "{$prefix}.description" => 'nullable|string|max:1000',
            "{$prefix}.is_physical" => 'required|boolean',
            "{$prefix}.parent_controlhub_id" => 'nullable|uuid',
            "{$prefix}.shortcuts" => 'nullable|array',
            "{$prefix}.shortcuts.*" => 'array',
            "{$prefix}.app_profiles" => 'nullable|array',
            "{$prefix}.app_profiles.*" => 'array',
        ];
    }

    /**
     * Synchronisation d'un groupe logique (flat, sans arborescence).
     *
     * Reçoit toutes les données du groupe + shortcuts + app_profiles imbriqués.
     * Upsert par controlhub_id + controlhub_version.
     */
    public function syncWorkstationGroup(Request $request): JsonResponse
    {
        Log::debug('syncWorkstationGroup', $request->all());

        try {
            $rules = array_merge(
                [
                    'task_id' => 'required|uuid',
                    'task_name' => 'required|string|max:255',
                    'task_type' => 'required|string|in:sync_workstation_group',
                    'payload' => 'required|array',
                    'scheduled_at' => 'nullable|date',
                ],
                $this->groupNodeRules('payload'),
            );

            $validated = $request->validate($rules);

            // Idempotence
            $existingTask = ControlHubTask::where('controlhub_task_id', $validated['task_id'])->first();
            if ($existingTask) {
                return response()->json([
                    'success' => true,
                    'message' => 'Task already received',
                    'task_id' => $existingTask->id,
                    'status' => $existingTask->status,
                ]);
            }

            $task = ControlHubTask::create([
                'controlhub_task_id' => $validated['task_id'],
                'name' => $validated['task_name'],
                'type' => 'sync_workstation_group',
                'payload' => $validated['payload'],
                'status' => ControlHubTask::STATUS_RECEIVED,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);

            Log::info('sync_workstation_group task created', [
                'controlhub_task_id' => $validated['task_id'],
                'local_id' => $task->id,
                'group_name' => $validated['payload']['name'],
            ]);

            $task->markAsQueued();
            $this->dispatchWithDelay($task);

            return response()->json([
                'success' => true,
                'message' => 'Task received and queued',
                'task_id' => $task->id,
                'status' => $task->status,
            ]);

        } catch (ValidationException $e) {
            Log::warning('sync_workstation_group validation failed', ['errors' => $e->errors()]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to process sync_workstation_group task', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Synchronisation d'une arborescence de groupes physiques (tree).
     *
     * Reçoit l'arbre complet avec tous les noeuds, shortcuts et app_profiles imbriqués.
     * Parcours parent → enfants, upsert par controlhub_id + controlhub_version.
     */
    public function syncWorkstationGroupTree(Request $request): JsonResponse
    {
        Log::debug('syncWorkstationGroupTree', $request->all());

        try {
            $rules = array_merge(
                [
                    'task_id' => 'required|uuid',
                    'task_name' => 'required|string|max:255',
                    'task_type' => 'required|string|in:sync_workstation_group_tree',
                    'payload' => 'required|array',
                    'payload.tree' => 'required|array',
                    'payload.tree.children' => 'nullable|array',
                    'scheduled_at' => 'nullable|date',
                ],
                $this->groupNodeRules('payload.tree'),
            );

            $validated = $request->validate($rules);

            // Idempotence
            $existingTask = ControlHubTask::where('controlhub_task_id', $validated['task_id'])->first();
            if ($existingTask) {
                return response()->json([
                    'success' => true,
                    'message' => 'Task already received',
                    'task_id' => $existingTask->id,
                    'status' => $existingTask->status,
                ]);
            }

            $task = ControlHubTask::create([
                'controlhub_task_id' => $validated['task_id'],
                'name' => $validated['task_name'],
                'type' => 'sync_workstation_group_tree',
                'payload' => $validated['payload'],
                'status' => ControlHubTask::STATUS_RECEIVED,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);

            Log::info('sync_workstation_group_tree task created', [
                'controlhub_task_id' => $validated['task_id'],
                'local_id' => $task->id,
                'root_name' => $validated['payload']['tree']['name'] ?? null,
            ]);

            $task->markAsQueued();
            $this->dispatchWithDelay($task);

            return response()->json([
                'success' => true,
                'message' => 'Task received and queued',
                'task_id' => $task->id,
                'status' => $task->status,
            ]);

        } catch (ValidationException $e) {
            Log::warning('sync_workstation_group_tree validation failed', ['errors' => $e->errors()]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to process sync_workstation_group_tree task', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Réception de la tâche delete_workstation_group depuis ControlHub.
     * Seuls les groupes verrouillés CONTROL_HUB peuvent être supprimés via cette API.
     */
    public function deleteWorkstationGroup(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'task_id' => 'required|uuid',
                'task_name' => 'required|string|max:255',
                'task_type' => 'required|string|in:delete_workstation_group',
                'payload' => 'required|array',
                'payload.controlhub_id' => 'required|uuid',
            ]);

            // Idempotence
            $existingTask = ControlHubTask::where('controlhub_task_id', $validated['task_id'])->first();
            if ($existingTask) {
                return response()->json([
                    'success' => true,
                    'message' => 'Task already received',
                    'task_id' => $existingTask->id,
                    'status' => $existingTask->status,
                ]);
            }

            // Vérifier que le groupe existe
            $controlhubId = $validated['payload']['controlhub_id'];
            $group = WorkstationGroup::where('controlhub_id', $controlhubId)->first();

            if (! $group) {
                return response()->json([
                    'success' => false,
                    'message' => 'Groupe non trouvé',
                    'controlhub_id' => $controlhubId,
                ], 404);
            }

            // Vérifier que le groupe est géré par ControlHub
            if ($group->locked !== LockReason::CONTROL_HUB->value) {
                Log::warning('delete_workstation_group: group is not managed by ControlHub', [
                    'controlhub_id' => $controlhubId,
                    'locked' => $group->locked,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Suppression refusée',
                    'error' => "Le groupe n'est pas géré par le ControlHub.",
                ], 403);
            }

            $task = ControlHubTask::create([
                'controlhub_task_id' => $validated['task_id'],
                'name' => $validated['task_name'],
                'type' => $validated['task_type'],
                'payload' => $validated['payload'],
                'status' => ControlHubTask::STATUS_RECEIVED,
            ]);

            $task->markAsQueued();
            DeleteWorkstationGroupJob::dispatch($task);

            return response()->json([
                'success' => true,
                'message' => 'Task received and queued',
                'task_id' => $task->id,
                'status' => $task->status,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to process delete_workstation_group task', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Réception d'un batch de WorkstationGroups depuis le ControlHub.
     *
     * Reçoit un arbre de groupes (avec children récursifs) et les crée ou met à jour
     * en une seule tâche. La logique d'upsert est :
     * - controlhub_id absent ou null -> création du groupe
     * - controlhub_id présent -> mise à jour du groupe existant (lookup par controlhub_id)
     *
     * L'arbre est parcouru en profondeur par le BulkCreateWorkstationGroupsJob,
     * garantissant que chaque parent est traité avant ses enfants.
     *
     * Endpoint : POST /api/v1/workstation-groups/bulk-create
     */
    public function bulkCreateWorkstationGroups(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'task_id' => 'required|uuid',
                'task_name' => 'required|string|max:255',
                'task_type' => 'required|string|in:batch_workstation_group',
                'payload' => 'required|array',
                'payload.groups' => 'required|array|min:1|max:100',
                'payload.groups.*.controlhub_id' => 'nullable|uuid',
                'payload.groups.*.name' => 'required|string|max:255',
                'payload.groups.*.is_physical' => 'required|boolean',
                'payload.groups.*.display_name' => 'nullable|string|max:255',
                'scheduled_at' => 'nullable|date',
            ]);

            // Idempotence : vérifier si la tâche existe déjà
            $existingTask = ControlHubTask::where('controlhub_task_id', $validated['task_id'])->first();
            if ($existingTask) {
                Log::info('batch_workstation_group task already exists', [
                    'task_id' => $validated['task_id'],
                    'local_id' => $existingTask->id,
                    'status' => $existingTask->status,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Task already received',
                    'task_id' => $existingTask->id,
                    'status' => $existingTask->status,
                ]);
            }

            $groups = $validated['payload']['groups'];

            // Validation récursive de l'arbre de groupes
            $validationErrors = $this->validateGroupsTree($groups);
            if (!empty($validationErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed for batch groups',
                    'errors' => $validationErrors,
                ], 422);
            }

            // Compter le nombre total de groupes (récursif)
            $totalGroups = $this->countGroupsRecursive($groups);

            // Enregistrer la tâche batch en base
            $task = ControlHubTask::create([
                'controlhub_task_id' => $validated['task_id'],
                'name' => $validated['task_name'],
                'type' => 'batch_workstation_group',
                'payload' => $validated['payload'],
                'status' => ControlHubTask::STATUS_RECEIVED,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);

            Log::info('batch_workstation_group task created', [
                'controlhub_task_id' => $validated['task_id'],
                'local_id' => $task->id,
                'root_groups_count' => count($groups),
                'total_groups_count' => $totalGroups,
            ]);

            // Dispatcher le job
            $task->markAsQueued();
            $delay = 0;
            if ($task->scheduled_at && $task->scheduled_at->isFuture()) {
                $delay = now()->diffInSeconds($task->scheduled_at);
            }

            if ($delay > 0) {
                BulkCreateWorkstationGroupsJob::dispatch($task)->delay($delay);
            } else {
                BulkCreateWorkstationGroupsJob::dispatch($task);
            }

            return response()->json([
                'success' => true,
                'message' => 'Batch task received and queued',
                'task_id' => $task->id,
                'status' => $task->status,
                'total_groups' => $totalGroups,
            ]);

        } catch (ValidationException $e) {
            Log::warning('batch_workstation_group validation failed', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to process batch_workstation_group task', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process batch task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Valide récursivement l'arbre de groupes.
     *
     * Chaque groupe doit avoir :
     * - name (string, requis)
     * - is_physical (boolean, requis)
     * - controlhub_id (UUID, optionnel) : si présent, le groupe sera mis à jour
     * - children (array, optionnel) : sous-groupes récursifs
     *
     * @return array Erreurs de validation indexées par chemin
     */
    private function validateGroupsTree(array $groups, string $path = 'groups'): array
    {
        $errors = [];

        foreach ($groups as $index => $group) {
            $currentPath = "{$path}[{$index}]";

            if (empty($group['name'])) {
                $errors[] = "{$currentPath}: le champ 'name' est requis";
            }
            if (!isset($group['is_physical'])) {
                $errors[] = "{$currentPath}: le champ 'is_physical' est requis";
            }

            // Valider le format du controlhub_id si fourni (UUID)
            if (!empty($group['controlhub_id']) && !\Illuminate\Support\Str::isUuid($group['controlhub_id'])) {
                $errors[] = "{$currentPath}: le champ 'controlhub_id' doit être un UUID valide";
            }

            // Valider parent_id si fourni
            if (!empty($group['parent_id'])) {
                $parent = WorkstationGroup::find($group['parent_id']);
                if (!$parent) {
                    $errors[] = "{$currentPath}: le parent_id={$group['parent_id']} n'existe pas";
                }
            }

            // Valider les enfants récursivement
            if (!empty($group['children']) && is_array($group['children'])) {
                $childErrors = $this->validateGroupsTree($group['children'], "{$currentPath}.children");
                $errors = array_merge($errors, $childErrors);
            }
        }

        return $errors;
    }

    /**
     * Compte le nombre total de groupes dans l'arbre (récursif).
     */
    private function countGroupsRecursive(array $groups): int
    {
        $count = count($groups);
        foreach ($groups as $group) {
            if (!empty($group['children']) && is_array($group['children'])) {
                $count += $this->countGroupsRecursive($group['children']);
            }
        }
        return $count;
    }

    /**
     * Dispatche un SyncWorkstationGroupJob avec délai optionnel.
     */
    private function dispatchWithDelay(ControlHubTask $task): void
    {
        $delay = 0;
        if ($task->scheduled_at && $task->scheduled_at->isFuture()) {
            $delay = now()->diffInSeconds($task->scheduled_at);
        }

        if ($delay > 0) {
            SyncWorkstationGroupJob::dispatch($task)->delay($delay);
        } else {
            SyncWorkstationGroupJob::dispatch($task);
        }
    }
}
