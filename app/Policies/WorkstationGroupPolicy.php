<?php

namespace App\Policies;

use App\Models\WorkstationGroup;
use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use App\Services\PermissionService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Policy pour la gestion des WorkstationGroups (salles/parcs)
 * 
 * Supporte les délégations scopées : un utilisateur peut avoir des droits
 * limités à un WorkstationGroup physique spécifique via le PermissionService.
 */
class WorkstationGroupPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    /**
     * Définition des gates pour cette policy
     */
    protected static array $gates = [
        'viewAny-workstationGroup' => 'viewAny',
        'view-workstationGroup' => 'view',
        'create-workstationGroup' => 'create',
        'update-workstationGroup' => 'update',
        'delete-workstationGroup' => 'delete',
        'manage-workstationGroups' => 'viewAny',
    ];

    /**
     * Vérifie si l'utilisateur peut voir la liste des WorkstationGroups
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->canViewComputers($user);
    }

    /**
     * Vérifie si l'utilisateur peut voir un WorkstationGroup spécifique
     * Supporte les délégations scopées
     */
    public function view(?Authenticatable $user, ?WorkstationGroup $group = null): bool
    {
        if ($group !== null && $this->canCheckDelegation($user, $group)) {
            return app(PermissionService::class)
                ->canOnWorkstationGroup($user, 'computer.view', $group);
        }

        return $this->canViewComputers($user);
    }

    /**
     * Vérifie si l'utilisateur peut créer un WorkstationGroup
     */
    public function create(?Authenticatable $user): bool
    {
        return $this->canAdminComputers($user);
    }

    /**
     * Vérifie si l'utilisateur peut modifier un WorkstationGroup
     */
    public function update(?Authenticatable $user, ?WorkstationGroup $group = null): bool
    {
        return $this->canAdminComputers($user);
    }

    /**
     * Vérifie si l'utilisateur peut supprimer un WorkstationGroup
     */
    public function delete(?Authenticatable $user, ?WorkstationGroup $group = null): bool
    {
        return $this->canAdminComputers($user);
    }

    /**
     * Vérifie si on peut utiliser le système de délégation pour cet utilisateur/groupe
     */
    private function canCheckDelegation(?Authenticatable $user, WorkstationGroup $group): bool
    {
        return $user instanceof \App\Models\User && $group->is_physical;
    }
}
