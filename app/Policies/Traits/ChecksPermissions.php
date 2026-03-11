<?php

namespace App\Policies\Traits;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Trait utilitaire pour les policies basées sur les permissions SQL (Spatie).
 */
trait ChecksPermissions
{
    /**
     * Vérifie si l'utilisateur a une permission Spatie donnée.
     */
    protected function hasPermission(?Authenticatable $user, string $spatiePermission): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can($spatiePermission);
    }

    /**
     * Raccourci : vérifie user.read
     */
    protected function canReadUsers(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'user.read');
    }

    /**
     * Raccourci : vérifie les droits user-admin
     */
    protected function canAdminUsers(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'user.modify');
    }

    /**
     * Raccourci : vérifie user.assign.right
     */
    protected function canAssignRights(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'user.assign.right');
    }

    /**
     * Raccourci : vérifie computer.view
     */
    protected function canViewComputers(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'computer.view');
    }

    /**
     * Raccourci : vérifie les droits computer-admin
     */
    protected function canAdminComputers(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'computer.modify');
    }
}
