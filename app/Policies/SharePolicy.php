<?php

namespace App\Policies;

use App\Models\UserGroup;
use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Policy pour les partages SambaEdu.
 *
 * Gates initiales :
 *  - `viewAny-share` : `share.view`.
 *  - `view-share`    : `share.view`.
 *  - `refresh-share` : `share.refresh`.
 *
 * (pas de modèle Eloquent `Share` dédié, FS = source de vérité) — gate
 * additionnelle :
 *  - `manage-share`  : `share.manage`. Le second argument typé `?UserGroup`
 *    (le groupe classe cible) reste optionnel pour autoriser la création
 *    avant existence du dossier FS. Pattern Laravel standard.
 */
class SharePolicy
{
    use RegistersGates;
    use ChecksPermissions;

    protected static array $gates = [
        'viewAny-share' => 'viewAny',
        'view-share' => 'view',
        'refresh-share' => 'refresh',
        // Gate de gestion (création/sync/toggle/archivage).
        'manage-share' => 'manage',
    ];

    public function viewAny(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'share.view');
    }

    public function view(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'share.view');
    }

    public function refresh(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'share.refresh');
    }

    /**
     * Vérifie la permission de gérer un partage de classe.
     *
     * Le second argument est typé `?UserGroup` (le groupe classe cible) plutôt
     * qu'un modèle Eloquent `Share` (pas de table dédiée, le FS est la source de
     * vérité). Ne dépend pas de l'existence du dossier FS — la création est
     * autorisée si la permission est présente, indépendamment de l'état FS.
     */
    public function manage(?Authenticatable $user, ?UserGroup $classe = null): bool
    {
        return $this->hasPermission($user, 'share.manage');
    }
}
