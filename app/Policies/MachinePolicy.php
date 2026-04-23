<?php

namespace App\Policies;

use App\Models\Workstation;
use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use App\Services\PermissionService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Story 7.2 (AC5) — Policy pour les machines (Workstation).
 *
 * Supporte le scoping par WorkstationGroup parent via `PermissionService::canOnWorkstationGroup`.
 * Un utilisateur délégué sur une salle physique peut agir sur les machines de
 * cette salle, sans avoir le droit global `computer.*`.
 *
 * Règles :
 *  - `viewAny`   : `computer.view` global (le listing se fait via WorkstationGroup scopé).
 *  - `view`      : délègue à `WorkstationGroupPolicy::view` via le parent.
 *  - `update`    : `computer.control` global OU délégation scopée sur le parent.
 *  - `control`   : idem `update` (action remote).
 *  - `elevate`   : `computer.elevate` global OU délégation scopée sur le parent.
 */
class MachinePolicy
{
    use RegistersGates;
    use ChecksPermissions;

    protected static array $gates = [
        'viewAny-workstation' => 'viewAny',
        'view-workstation' => 'view',
        'update-workstation' => 'update',
        'control-workstation' => 'control',
        'elevate-workstation' => 'elevate',
    ];

    public function viewAny(?Authenticatable $user): bool
    {
        return $this->canViewComputers($user);
    }

    public function view(?Authenticatable $user, ?Workstation $machine = null): bool
    {
        if ($machine === null) {
            return $this->canViewComputers($user);
        }
        return $this->scopedCheck($user, 'computer.view', $machine);
    }

    public function update(?Authenticatable $user, ?Workstation $machine = null): bool
    {
        if ($machine === null) {
            return $this->hasPermission($user, 'computer.control');
        }
        return $this->scopedCheck($user, 'computer.control', $machine);
    }

    public function control(?Authenticatable $user, ?Workstation $machine = null): bool
    {
        return $this->update($user, $machine);
    }

    public function elevate(?Authenticatable $user, ?Workstation $machine = null): bool
    {
        if ($machine === null) {
            return $this->hasPermission($user, 'computer.elevate');
        }
        return $this->scopedCheck($user, 'computer.elevate', $machine);
    }

    /**
     * Vérifie une permission en tenant compte des WorkstationGroup parents
     * physiques de la machine (via `PermissionService::canOnWorkstationGroup`).
     *
     * Une machine peut appartenir à plusieurs WorkstationGroup (relation N:N).
     * Si elle appartient à au moins un groupe physique sur lequel l'user a
     * la permission (globale ou scopée), on autorise.
     *
     * Si la machine n'a pas de groupe physique parent, on se rabat sur le
     * droit global.
     */
    private function scopedCheck(?Authenticatable $user, string $permissionName, Workstation $machine): bool
    {
        if (!$user instanceof \App\Models\User) {
            return $this->hasPermission($user, $permissionName);
        }

        // Shortcut : droit global → accès à tout.
        if ($user->can($permissionName)) {
            return true;
        }

        $physicalGroups = $machine->groups()->where('is_physical', true)->get();
        if ($physicalGroups->isEmpty()) {
            return false;
        }

        $svc = app(PermissionService::class);
        foreach ($physicalGroups as $grp) {
            if ($svc->canOnWorkstationGroup($user, $permissionName, $grp)) {
                return true;
            }
        }
        return false;
    }
}
