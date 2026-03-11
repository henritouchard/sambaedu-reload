<?php

namespace App\Policies;

use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Policy pour la gestion des raccourcis
 *
 * Contrôle l'accès via les permissions SQL (Spatie).
 */
class ShortcutPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    /**
     * Définition des gates pour cette policy
     */
    protected static array $gates = [
        'viewAny-shortcut' => 'viewAny',
        'view-shortcut' => 'view',
        'create-shortcut' => 'create',
        'update-shortcut' => 'update',
        'delete-shortcut' => 'delete',
        'bulkDelete-shortcut' => 'bulkDelete',
        'manage-shortcuts' => 'viewAny',
    ];

    /**
     * Vérifie si l'utilisateur peut voir la liste des raccourcis
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->canAdminComputers($user);
    }

    /**
     * Vérifie si l'utilisateur peut voir un raccourci spécifique
     */
    public function view(?Authenticatable $user): bool
    {
        return $this->canAdminComputers($user);
    }

    /**
     * Vérifie si l'utilisateur peut créer un raccourci
     */
    public function create(?Authenticatable $user): bool
    {
        return $this->canAdminComputers($user);
    }

    /**
     * Vérifie si l'utilisateur peut modifier un raccourci
     */
    public function update(?Authenticatable $user): bool
    {
        return $this->canAdminComputers($user);
    }

    /**
     * Vérifie si l'utilisateur peut supprimer un raccourci
     */
    public function delete(?Authenticatable $user): bool
    {
        return $this->canAdminComputers($user);
    }

    /**
     * Vérifie si l'utilisateur peut effectuer des suppressions groupées
     */
    public function bulkDelete(?Authenticatable $user): bool
    {
        return $this->canAdminComputers($user);
    }
}
