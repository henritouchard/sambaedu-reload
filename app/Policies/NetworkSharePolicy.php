<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NetworkShare;
use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Story 34.2 (Q5) — Policy DÉDIÉE des « lecteurs réseau gérés » (`network_shares`).
 *
 * Calquée 1:1 sur {@see SharePolicy} (traits `RegistersGates`/`ChecksPermissions`)
 * mais sur des permissions DÉDIÉES `networkshare.view` / `networkshare.manage` —
 * volontairement DISTINCTES de `share.view`/`share.manage` :
 *  - le `ReferentNumerique` (pilote de la story 34.2) n'a AUCUNE permission
 *    `share.*` : réutiliser `share.view` l'aurait exclu ;
 *  - `share.manage` gouverne aussi les partages de CLASSE : la réutiliser
 *    aurait sur-octroyé le refnum aux partages de classe.
 *
 * Gates exposés :
 *  - `viewAny-networkshare` / `view-networkshare` → `networkshare.view` ;
 *  - `manage-networkshare`                       → `networkshare.manage`.
 *
 * Le second argument `?NetworkShare` reste optionnel (pattern Laravel standard) :
 * la création est autorisée AVANT existence du modèle (iso `SharePolicy::manage`).
 */
class NetworkSharePolicy
{
    use RegistersGates;
    use ChecksPermissions;

    protected static array $gates = [
        'viewAny-networkshare' => 'viewAny',
        'view-networkshare' => 'view',
        'manage-networkshare' => 'manage',
    ];

    public function viewAny(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'networkshare.view');
    }

    public function view(?Authenticatable $user, ?NetworkShare $share = null): bool
    {
        return $this->hasPermission($user, 'networkshare.view');
    }

    public function manage(?Authenticatable $user, ?NetworkShare $share = null): bool
    {
        return $this->hasPermission($user, 'networkshare.manage');
    }
}
