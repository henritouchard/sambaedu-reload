<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FolderAccessRule;
use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Story 36.4 (D6) — Policy DÉDIÉE des règles d'accès aux dossiers
 * (`folder_access_rules`).
 *
 * Calquée 1:1 sur {@see NetworkSharePolicy} (traits `RegistersGates`/
 * `ChecksPermissions`) sur des permissions DÉDIÉES `folderrule.view` /
 * `folderrule.manage` (module SE5-natif, aucune GPO/bit legacy). Accordées au
 * `ReferentNumerique` et au `ComputerAdmin`.
 *
 * Gates exposés :
 *  - `viewAny-folderrule` / `view-folderrule` → `folderrule.view` ;
 *  - `manage-folderrule`                      → `folderrule.manage`.
 *
 * **Délégation scopée par parc (piège #9).** Le gate `manage-folderrule` est un
 * droit GLOBAL (créer/éditer une règle). Le contrôle PAR PARC des (dé)assignations
 * vit dans {@see \App\Services\Agent\FolderAccessRuleService} via
 * `PermissionService::canOnWorkstationGroup()` (anti-piège « Gate global non
 * scopé ») — un Gate global ne suffit PAS à borner un délégué à son périmètre.
 */
class FolderAccessRulePolicy
{
    use RegistersGates;
    use ChecksPermissions;

    protected static array $gates = [
        'viewAny-folderrule' => 'viewAny',
        'view-folderrule' => 'view',
        'manage-folderrule' => 'manage',
    ];

    public function viewAny(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'folderrule.view');
    }

    public function view(?Authenticatable $user, ?FolderAccessRule $rule = null): bool
    {
        return $this->hasPermission($user, 'folderrule.view');
    }

    public function manage(?Authenticatable $user, ?FolderAccessRule $rule = null): bool
    {
        return $this->hasPermission($user, 'folderrule.manage');
    }
}
