<?php

namespace App\Services;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Jobs\SyncGpoJob;
use App\Models\Delegation;
use App\Models\User;
use App\Models\WorkstationGroup;
use LogicException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Service central de gestion des permissions SambaEdu 4.6
 *
 * Orchestre les permissions Spatie (globales) et les délégations (scopées par WorkstationGroup).
 * Fournit la conversion bitmask ↔ permissions pour les besoins de compatibilité legacy.
 * 
 * Règle de conception : toute logique métier (Policies, Blade, middleware) doit utiliser
 * uniquement les permissions Spatie ($user->can(...)) et jamais les colonnes ad_* ni le bitmask.
 */
class PermissionService
{
    public function __construct()
    {
    }

    // ========================================================================
    // SYNCHRONISATION AD → SQL (DÉSACTIVÉE)
    // ========================================================================

    /**
     * @deprecated Les droits web ne sont plus synchronisés depuis l'AD.
     * 
     * @param string $login samAccountName de l'utilisateur
     * @param array $adData Données AD de l'utilisateur (fullname, dn, groups, rightProfiles, role)
     * @return User L'utilisateur synchronisé
     */
    public function syncFromAd(string $login, array $adData): User
    {
        throw new LogicException('syncFromAd() est désactivé: les droits web sont désormais gérés uniquement en SQL.');
    }

    /**
     * Synchronise les permissions SQL → AD (anticipe la transition source de vérité)
     */
    public function syncToAd(User $user): void
    {
        // TODO: Implémenter quand SQL devient source de vérité
        // Via add_right_profile() / remove_right_profile() legacy
        Log::debug('[PermissionService] syncToAd() pas encore implémenté', [
            'login' => $user->login,
        ]);
    }

    // ========================================================================
    // CONVERSION BITMASK ↔ PERMISSIONS
    // ========================================================================

    /**
     * Convertit un bitmask legacy en liste de noms de permissions Spatie
     */
    public function bitmaskToPermissions(int $bitmask): array
    {
        return SambaPermission::fromBitmask($bitmask);
    }

    /**
     * Convertit les permissions Spatie d'un utilisateur en bitmask legacy
     */
    public function permissionsToBitmask(User $user): int
    {
        return SambaPermission::toBitmask(
            $user->getAllPermissions()->pluck('name')->toArray()
        );
    }

    // ========================================================================
    // DÉLÉGATIONS
    // ========================================================================

    /**
     * Accorde une délégation à un utilisateur sur un WorkstationGroup
     */
    public function grantDelegation(
        User $user,
        string $permissionName,
        WorkstationGroup $group,
        ?User $grantedBy = null,
        ?\DateTimeInterface $expiresAt = null
    ): Delegation {
        $permission = Permission::findByName($permissionName, 'web');

        $delegation = Delegation::updateOrCreate(
            [
                'user_id' => $user->id,
                'workstation_group_id' => $group->id,
                'permission_id' => $permission->id,
                'is_negative' => false,
            ],
            [
                'granted_by' => $grantedBy?->id,
                'expires_at' => $expiresAt,
            ]
        );

        Log::info('[PermissionService] Délégation accordée', [
            'user' => $user->login,
            'permission' => $permissionName,
            'workstation_group' => $group->name,
            'granted_by' => $grantedBy?->login,
        ]);

        // Dispatch GPO sync si nécessaire (computer.elevate)
        $perm = SambaPermission::tryFrom($permissionName);
        if ($perm?->requiresGpoSync()) {
            SyncGpoJob::dispatch($user->id, $group->id, 'grant');
        }

        return $delegation;
    }

    /**
     * Révoque une délégation
     */
    public function revokeDelegation(
        User $user,
        string $permissionName,
        WorkstationGroup $group
    ): bool {
        $permission = Permission::findByName($permissionName, 'web');

        $deleted = Delegation::where('user_id', $user->id)
            ->where('workstation_group_id', $group->id)
            ->where('permission_id', $permission->id)
            ->where('is_negative', false)
            ->delete();

        Log::info('[PermissionService] Délégation révoquée', [
            'user' => $user->login,
            'permission' => $permissionName,
            'workstation_group' => $group->name,
            'deleted' => $deleted,
        ]);

        // Dispatch GPO sync si nécessaire (computer.elevate)
        $perm = SambaPermission::tryFrom($permissionName);
        if ($deleted > 0 && $perm?->requiresGpoSync()) {
            SyncGpoJob::dispatch($user->id, $group->id, 'revoke');
        }

        return $deleted > 0;
    }

    /**
     * Crée une délégation négative (exclusion)
     */
    public function negateDelegation(
        User $user,
        string $permissionName,
        WorkstationGroup $group,
        ?User $grantedBy = null
    ): Delegation {
        $permission = Permission::findByName($permissionName, 'web');

        return Delegation::updateOrCreate(
            [
                'user_id' => $user->id,
                'workstation_group_id' => $group->id,
                'permission_id' => $permission->id,
                'is_negative' => true,
            ],
            [
                'granted_by' => $grantedBy?->id,
            ]
        );
    }

    /**
     * Vérifie si un utilisateur a une permission sur un WorkstationGroup
     * 
     * Logique :
     * 1. Droit global Spatie → accès à tout
     * 2. Délégation positive active sur ce WorkstationGroup
     * 3. Pas de délégation négative
     */
    public function canOnWorkstationGroup(User $user, string $permissionName, WorkstationGroup $group): bool
    {
        // 1. Droit global → accès à tout
        if ($user->can($permissionName)) {
            return true;
        }

        // 2. Délégation positive active sur ce WorkstationGroup
        $hasPositive = Delegation::where('user_id', $user->id)
            ->where('workstation_group_id', $group->id)
            ->forPermission($permissionName)
            ->positive()
            ->active()
            ->exists();

        if (!$hasPositive) {
            return false;
        }

        // 3. Vérifier qu'il n'y a pas de délégation négative
        $hasNegative = Delegation::where('user_id', $user->id)
            ->where('workstation_group_id', $group->id)
            ->forPermission($permissionName)
            ->negative()
            ->exists();

        return !$hasNegative;
    }

    /**
     * Retourne toutes les délégations actives d'un utilisateur
     */
    public function getUserDelegations(User $user): Collection
    {
        return Delegation::forUser($user)
            ->active()
            ->with(['workstationGroup', 'permission', 'granter'])
            ->get();
    }

    /**
     * Retourne toutes les délégations actives sur un WorkstationGroup
     */
    public function getWorkstationGroupDelegations(WorkstationGroup $group): Collection
    {
        return Delegation::forWorkstationGroup($group)
            ->active()
            ->with(['user', 'permission', 'granter'])
            ->get();
    }

    /**
     * Retourne les WorkstationGroups sur lesquels un utilisateur a une permission donnée
     */
    public function getAuthorizedWorkstationGroups(User $user, string $permissionName): Collection
    {
        // Si droit global, retourner tous les WorkstationGroups physiques
        if ($user->can($permissionName)) {
            return WorkstationGroup::physical()->active()->get();
        }

        // Sinon, retourner ceux avec une délégation positive active (sans négative)
        $positiveGroupIds = Delegation::forUser($user)
            ->forPermission($permissionName)
            ->positive()
            ->active()
            ->pluck('workstation_group_id');

        $negativeGroupIds = Delegation::forUser($user)
            ->forPermission($permissionName)
            ->negative()
            ->pluck('workstation_group_id');

        return WorkstationGroup::whereIn('id', $positiveGroupIds)
            ->whereNotIn('id', $negativeGroupIds)
            ->physical()
            ->active()
            ->get();
    }

    // ========================================================================
    // UTILITAIRES
    // ========================================================================

    /**
     * Retourne le mapping bitmask → permission
     */
    public static function getBitmaskMapping(): array
    {
        return SambaPermission::bitmaskMapping();
    }

    /**
     * Retourne le nom de permission Spatie pour un bitmask donné
     */
    public static function bitmaskToPermissionName(int $bitmask): ?string
    {
        return SambaPermission::fromSingleBitmask($bitmask)?->value;
    }
}
