<?php

namespace App\Policies;

use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Policy pour la gestion des utilisateurs
 * 
 */
class UserPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    /**
     * Définition des gates pour cette policy
     */
    protected static array $gates = [
        'viewAny-user' => 'viewAny',
        'view-user' => 'view',
        'create-user' => 'create',
        'update-user' => 'update',
        'delete-user' => 'delete',
        'manage-users' => 'viewAny',
        'manage-groups' => 'manageGroups',
        'manage-rights' => 'manageRights',
    ];

    /**
     * Vérifie si l'utilisateur peut voir la liste des utilisateurs
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->canReadUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut voir un utilisateur spécifique
     */
    public function view(?Authenticatable $user): bool
    {
        return $this->canReadUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut créer un utilisateur
     */
    public function create(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'user.modify');
    }

    /**
     * Vérifie si l'utilisateur peut modifier un utilisateur
     */
    public function update(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'user.modify');
    }

    /**
     * Vérifie si l'utilisateur peut supprimer un utilisateur
     */
    public function delete(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'user.modify');
    }

    /**
     * Vérifie si l'utilisateur peut gérer les groupes des utilisateurs
     */
    public function manageGroups(?Authenticatable $user): bool
    {
        return $this->canAdminUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut modifier les droits d'un utilisateur
     * Requiert SE_USER_ASSIGN_RIGHT
     */
    public function manageRights(?Authenticatable $user): bool
    {
        return $this->canAssignRights($user);
    }
}
