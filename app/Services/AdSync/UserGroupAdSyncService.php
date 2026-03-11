<?php

declare(strict_types=1);

namespace App\Services\AdSync;

use App\Models\UserGroup;
use App\Repositories\GroupRepository;
use Illuminate\Support\Facades\Log;

class UserGroupAdSyncService
{
    public function __construct(
        private GroupRepository $groupRepository,
    ) {
    }

    /**
     * @return array{success:bool,error:?string}
     */
    public function create(UserGroup $group): array
    {
        $created = $this->groupRepository->createGroup(
            name: $group->name,
            description: $group->display_name ?? $group->name,
            type: $this->mapTypeToLdap($group->type),
        );

        if (!$created) {
            return ['success' => false, 'error' => "Création AD impossible pour {$group->name}"];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * @return array{success:bool,error:?string}
     */
    public function update(UserGroup $group, string $oldName): array
    {
        // Si le nom change, on applique une stratégie create+delete de secours
        if ($oldName !== $group->name) {
            $createResult = $this->create($group);
            if (!$createResult['success']) {
                return $createResult;
            }

            $deleted = $this->groupRepository->deleteGroup($oldName);
            if (!$deleted) {
                Log::warning('[UserGroupAdSyncService] Impossible de supprimer l\'ancien groupe AD', [
                    'old_name' => $oldName,
                    'new_name' => $group->name,
                ]);
            }

            return ['success' => true, 'error' => null];
        }

        $updated = $this->groupRepository->updateGroupDescription(
            cn: $group->name,
            description: $group->display_name ?? $group->name,
        );

        if (!$updated) {
            // Fallback important: si le groupe n'existe pas encore dans l'AD
            // (ex: worker indisponible au moment de la création SQL), on le crée.
            Log::warning('[UserGroupAdSyncService] Mise à jour AD impossible, tentative de création', [
                'group_name' => $group->name,
            ]);

            return $this->create($group);
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * @return array{success:bool,error:?string}
     */
    public function delete(string $groupName): array
    {
        $deleted = $this->groupRepository->deleteGroup($groupName);

        if (!$deleted) {
            return ['success' => false, 'error' => "Suppression AD impossible pour {$groupName}"];
        }

        return ['success' => true, 'error' => null];
    }

    private function mapTypeToLdap(string $type): string
    {
        return match (mb_strtolower(trim($type))) {
            'class', 'classe' => 'classe',
            'cours' => 'cours',
            'matiere', 'matière' => 'matiere',
            'projet' => 'projet',
            'equipe', 'équipe' => 'equipe',
            default => 'other_group',
        };
    }
}
