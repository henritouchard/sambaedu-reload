<?php

namespace App\Policies;

use App\Models\Delegation;
use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Story 7.2 (AC5) — Policy pour la gestion des Delegations.
 *
 * Règles :
 *  - `viewAny` : admin avec `user.assign.right` (voir toutes les délégations).
 *  - `view`    : admin avec `user.assign.right` OU propriétaire de la délégation.
 *  - `create`  : nécessite `user.assign.right` ET `user.delegate`
 *               (permission explicite de déléguer).
 *  - `delete`  : admin avec `user.assign.right` (révocation administrative).
 */
class DelegationPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    protected static array $gates = [
        'viewAny-delegation' => 'viewAny',
        'view-delegation' => 'view',
        'create-delegation' => 'create',
        'delete-delegation' => 'delete',
    ];

    public function viewAny(?Authenticatable $user): bool
    {
        return $this->canAssignRights($user);
    }

    public function view(?Authenticatable $user, ?Delegation $delegation = null): bool
    {
        if ($this->canAssignRights($user)) {
            return true;
        }
        // L'utilisateur peut voir ses propres délégations même sans droit global.
        if ($delegation !== null && $user !== null && isset($user->id) && $delegation->user_id === $user->id) {
            return true;
        }
        return false;
    }

    public function create(?Authenticatable $user): bool
    {
        return $this->canAssignRights($user)
            && $this->hasPermission($user, 'user.delegate');
    }

    public function delete(?Authenticatable $user, ?Delegation $delegation = null): bool
    {
        return $this->canAssignRights($user);
    }
}
