<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FolderAccessRule;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use App\Services\PermissionService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Story 36.4 (D6) — Policy DÉDIÉE des règles d'accès aux dossiers
 * (`folder_access_rules`).
 *
 * Calquée sur {@see WorkstationGroupPolicy} (patron 7.1 : délégation scopée
 * ATTEIGNABLE) sur des permissions DÉDIÉES `folderrule.view` /
 * `folderrule.manage` (module SE5-natif, aucune GPO/bit legacy). Accordées au
 * `ReferentNumerique` et au `ComputerAdmin`.
 *
 * Gates exposés :
 *  - `viewAny-folderrule` → `viewAny` ;
 *  - `view-folderrule`    → `view` (avec la règle en ressource) ;
 *  - `manage-folderrule`  → `manage` (avec la règle en ressource).
 *
 * **Délégation scopée par parc ATTEIGNABLE (correction review #1, patron 7.1).**
 * Le gate `folderrule.view` (permission Spatie GLOBALE) fermait la porte AVANT
 * que `canOnWorkstationGroup` (dans le service) ne s'exécute : un délégué scopé
 * parc, SANS droit global, prenait 403. Comme `WorkstationGroupPolicy::viewAny`
 * (story 7.1), les gates policy-backed acceptent désormais AUSSI un délégué
 * scopé :
 *  - `viewAny` : droit global OU au moins un parc délégué `folderrule.manage` ;
 *  - `view($rule)` / `manage($rule)` : droit global OU `canOnWorkstationGroup`
 *    sur AU MOINS UN parc assigné à la règle.
 *
 * Les routes utilisent `can:viewAny-folderrule` (gate policy-backed) — PAS la
 * permission Spatie nue — pour que le middleware laisse entrer le délégué scopé,
 * comme `/app/parc` avec `can:viewAny-workstationGroup`.
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

    /**
     * Liste/index : droit global `folderrule.view` OU délégué scopé disposant
     * d'au moins un parc `folderrule.manage` (patron `WorkstationGroupPolicy`).
     */
    public function viewAny(?Authenticatable $user): bool
    {
        if ($this->hasPermission($user, 'folderrule.view')) {
            return true;
        }

        return $this->hasAnyScopedParc($user);
    }

    /**
     * Détail d'une règle : droit global OU délégation scopée sur au moins un des
     * parcs assignés à la règle. Sans règle en ressource → se rabat sur `viewAny`.
     */
    public function view(?Authenticatable $user, ?FolderAccessRule $rule = null): bool
    {
        if ($this->hasPermission($user, 'folderrule.view')) {
            return true;
        }

        if ($rule === null) {
            return $this->hasAnyScopedParc($user);
        }

        return $this->canOnAnyAssignedParc($user, $rule);
    }

    /**
     * Gestion d'une règle : droit global `folderrule.manage` OU délégation scopée
     * `folderrule.manage` sur au moins un des parcs assignés à la règle. Sans
     * règle en ressource (création globale) → droit global UNIQUEMENT.
     */
    public function manage(?Authenticatable $user, ?FolderAccessRule $rule = null): bool
    {
        if ($this->hasPermission($user, 'folderrule.manage')) {
            return true;
        }

        if ($rule === null) {
            return false;
        }

        return $this->canOnAnyAssignedParc($user, $rule);
    }

    /**
     * Le délégué scopé a-t-il au moins un parc où il peut gérer les règles ?
     * (patron `WorkstationGroupPolicy::viewAny` — sans ça, le middleware `can:`
     * ferme la porte avant le scoping).
     */
    private function hasAnyScopedParc(?Authenticatable $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return app(PermissionService::class)
            ->getAuthorizedWorkstationGroups($user, 'folderrule.manage')
            ->isNotEmpty();
    }

    /**
     * L'acteur peut-il gérer AU MOINS UN parc assigné à la règle (droit global
     * ou délégation positive scopée) ? — cœur de la délégation atteignable.
     */
    private function canOnAnyAssignedParc(?Authenticatable $user, FolderAccessRule $rule): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $permissions = app(PermissionService::class);

        foreach ($rule->workstationGroups()->get() as $group) {
            /** @var WorkstationGroup $group */
            if ($permissions->canOnWorkstationGroup($user, 'folderrule.manage', $group)) {
                return true;
            }
        }

        return false;
    }
}
