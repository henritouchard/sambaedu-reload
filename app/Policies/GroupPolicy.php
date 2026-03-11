<?php

namespace App\Policies;

use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Policy pour la gestion des groupes
 *
 * Contrôle l'accès via les permissions SQL (Spatie).
 */
class GroupPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    /**
     * Définition des gates pour cette policy
     */
    protected static array $gates = [
        'viewAny-group' => 'viewAny',
        'view-group' => 'view',
        'create-group' => 'create',
        'update-group' => 'update',
        'delete-group' => 'delete',
        'manage-groups' => 'viewAny',
        'addMember-group' => 'addMember',
        'removeMember-group' => 'removeMember',
    ];

    /**
     * Vérifie si l'utilisateur peut voir la liste des groupes
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->canReadUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut voir un groupe
     */
    public function view(?Authenticatable $user): bool
    {
        return $this->canReadUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut créer un groupe
     */
    public function create(?Authenticatable $user): bool
    {
        return $this->canAdminUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut modifier un groupe
     */
    public function update(?Authenticatable $user): bool
    {
        return $this->canAdminUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut supprimer un groupe
     */
    public function delete(?Authenticatable $user): bool
    {
        return $this->canAdminUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut ajouter un membre à un groupe
     */
    public function addMember(?Authenticatable $user): bool
    {
        return $this->canAdminUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut retirer un membre d'un groupe
     */
    public function removeMember(?Authenticatable $user): bool
    {
        return $this->canAdminUsers($user);
    }
}