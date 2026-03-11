<?php

namespace App\Jobs;

use App\Enums\LockReason;
use App\Models\WorkstationGroup;
use App\Repositories\WorkstationGroupRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job pour exécuter la tâche "delete_workstation_group" ordonnée par le ControlHub.
 * Seuls les groupes verrouillés CONTROL_HUB peuvent être supprimés.
 * Contourne le verrou du service car le ControlHub est propriétaire du groupe.
 */
class DeleteWorkstationGroupJob extends BaseControlHubJob
{
    /**
     * Exécute la logique métier spécifique à la suppression de groupe.
     * 
     * @return array Le résultat de l'exécution
     * @throws \Exception En cas d'erreur
     */
    protected function execute(): array
    {
        $payload = $this->task->payload ?? [];

        Log::info('DeleteWorkstationGroupJob: Processing workstation group deletion', [
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

        // Double-check : seuls les groupes CONTROL_HUB peuvent être supprimés via cette API
        if ($group->locked !== LockReason::CONTROL_HUB->value) {
            throw new \RuntimeException(
                "Le groupe '{$group->name}' n'est pas géré par le ControlHub (locked={$group->locked})"
            );
        }

        $groupId = $group->id;
        $groupName = $group->name;
        $adGuid = $group->ad_guid;
        $isPhysical = $group->is_physical;

        // Déverrouiller temporairement pour permettre la suppression via le service
        // L'Observer gère la suppression AD automatiquement
        $repository = app(WorkstationGroupRepository::class);

        DB::transaction(function () use ($group, $repository) {
            // Déverrouiller pour permettre la suppression
            $group->locked = null;
            $group->save();

            $repository->deleteGroup($group);
        });

        Log::info('DeleteWorkstationGroupJob: Group deleted successfully', [
            'task_id' => $this->task->id,
            'group_id' => $groupId,
            'group_name' => $groupName,
        ]);

        return [
            'deleted' => true,
            'group_id' => $groupId,
            'group_name' => $groupName,
            'ad_guid' => $adGuid,
            'is_physical' => $isPhysical,
            'message' => 'Groupe de machines supprimé avec succès',
        ];
    }
}
