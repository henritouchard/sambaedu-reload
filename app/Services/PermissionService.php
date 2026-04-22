<?php

namespace App\Services;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Jobs\SyncGpoJob;
use App\Models\Delegation;
use App\Models\DelegationHistory;
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
 *
 * Story 7.1 : toute mutation d'une délégation (grant / revoke / negate) écrit une
 * entrée dans `delegation_history` via `DelegationHistoryService`. Les signatures
 * acceptent un `?User $actor = null` — fallback sur `auth()->user()` si null.
 * Le paramètre étant optionnel, les appelants existants restent compatibles.
 */
class PermissionService
{
    /**
     * Story 7.1 — Review #4 (décision Henri 2026-04-23, Option B) :
     *   L'écriture d'audit est best-effort. Si `log()` retourne null, on signale
     *   cet échec à l'appelant via cette propriété, qui sert à afficher un toast
     *   warning côté UI ("délégation appliquée mais traçabilité non enregistrée").
     *
     *   Le flag est réinitialisé à `false` au début de chaque mutation
     *   (grantDelegation / revokeDelegation / negateDelegation).
     */
    public bool $lastAuditFailed = false;

    public function __construct(
        private readonly ?DelegationHistoryService $historyService = null
    ) {
    }

    /**
     * Résout le service d'historique — fallback container Laravel si null
     * (ctor peut être appelé sans injection dans l'existant legacy).
     */
    private function history(): DelegationHistoryService
    {
        return $this->historyService ?? app(DelegationHistoryService::class);
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
     * Accorde une délégation à un utilisateur sur un WorkstationGroup.
     *
     * Idempotent : appelée 2× avec les mêmes (user, group, permission, is_negative=false),
     * une seule ligne existe en base (via `updateOrCreate`). L'entrée d'historique
     * n'est créée QUE si `wasRecentlyCreated` est true — sinon on a déjà tracé
     * le grant initial lors du 1er appel.
     *
     * Story 7.1 : le paramètre `$grantedBy` (déjà existant) sert de fallback si
     * `auth()->user()` est null (ex. appel depuis un Job sans contexte HTTP).
     */
    public function grantDelegation(
        User $user,
        string $permissionName,
        WorkstationGroup $group,
        ?User $grantedBy = null,
        ?\DateTimeInterface $expiresAt = null
    ): Delegation {
        // Story 7.1 — Review #4 : reset du flag audit à chaque mutation.
        $this->lastAuditFailed = false;

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

        // AC5 / Tâche 2.4 : historique — uniquement si on vient de créer la ligne.
        // `updateOrCreate` peut aussi mettre à jour `expires_at` ou `granted_by`
        // sur une ligne existante — dans ce cas on ne retrace pas un nouveau `grant`.
        if ($delegation->wasRecentlyCreated) {
            $actor = $this->history()->resolveActor($grantedBy);
            $historyEntry = $this->history()->log(
                action: DelegationHistory::ACTION_GRANT,
                actor: $actor,
                target: $user,
                group: $group,
                permissionName: $permissionName,
                isNegative: false,
                context: $this->buildContext(),
            );
            // Story 7.1 — Review #4 : best-effort + signalisation. L'appelant
            // (drawer / rights-management) consulte $lastAuditFailed pour émettre
            // un toast warning quand la délégation est posée mais non tracée.
            $this->lastAuditFailed = ($historyEntry === null);
        }

        // Dispatch GPO sync si nécessaire (computer.elevate)
        $perm = SambaPermission::tryFrom($permissionName);
        if ($perm?->requiresGpoSync()) {
            SyncGpoJob::dispatch($user->id, $group->id, 'grant');
        }

        return $delegation;
    }

    /**
     * Révoque une délégation (positive).
     *
     * Story 7.1 : `$actor` est optionnel — fallback sur `auth()->user()`.
     * L'historique `revoke` n'est créé que si une ligne a bien été supprimée
     * (sinon revoke sur néant = pas de trace à écrire).
     */
    public function revokeDelegation(
        User $user,
        string $permissionName,
        WorkstationGroup $group,
        ?User $actor = null
    ): bool {
        // Story 7.1 — Review #4 : reset du flag audit à chaque mutation.
        $this->lastAuditFailed = false;

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

        if ($deleted > 0) {
            $resolvedActor = $this->history()->resolveActor($actor);
            $historyEntry = $this->history()->log(
                action: DelegationHistory::ACTION_REVOKE,
                actor: $resolvedActor,
                target: $user,
                group: $group,
                permissionName: $permissionName,
                isNegative: false,
                context: $this->buildContext(),
            );
            $this->lastAuditFailed = ($historyEntry === null);

            // Dispatch GPO sync si nécessaire (computer.elevate)
            $perm = SambaPermission::tryFrom($permissionName);
            if ($perm?->requiresGpoSync()) {
                SyncGpoJob::dispatch($user->id, $group->id, 'revoke');
            }
        }

        return $deleted > 0;
    }

    /**
     * Crée une délégation négative (exclusion).
     *
     * Idempotent via la même clé unique composite que `grantDelegation`.
     * Historique `negate` écrit uniquement lors de la création réelle.
     *
     * Story 7.1 : `$actor` optionnel — fallback sur `auth()->user()`.
     */
    public function negateDelegation(
        User $user,
        string $permissionName,
        WorkstationGroup $group,
        ?User $actor = null
    ): Delegation {
        // Story 7.1 — Review #4 : reset du flag audit à chaque mutation.
        $this->lastAuditFailed = false;

        $permission = Permission::findByName($permissionName, 'web');

        $delegation = Delegation::updateOrCreate(
            [
                'user_id' => $user->id,
                'workstation_group_id' => $group->id,
                'permission_id' => $permission->id,
                'is_negative' => true,
            ],
            [
                'granted_by' => $actor?->id,
            ]
        );

        Log::info('[PermissionService] Délégation négative créée', [
            'user' => $user->login,
            'permission' => $permissionName,
            'workstation_group' => $group->name,
            'granted_by' => $actor?->login,
        ]);

        if ($delegation->wasRecentlyCreated) {
            $resolvedActor = $this->history()->resolveActor($actor);
            $historyEntry = $this->history()->log(
                action: DelegationHistory::ACTION_NEGATE,
                actor: $resolvedActor,
                target: $user,
                group: $group,
                permissionName: $permissionName,
                isNegative: true,
                context: $this->buildContext(),
            );
            $this->lastAuditFailed = ($historyEntry === null);
        }

        return $delegation;
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

        // 3. Vérifier qu'il n'y a pas de délégation négative active
        // Story 7.1 — Review #3 : chaîner `.active()` pour qu'une négative
        // expirée n'empêche plus l'accès (le jour où `negateDelegation` posera
        // un `expires_at`, la logique reste cohérente).
        $hasNegative = Delegation::where('user_id', $user->id)
            ->where('workstation_group_id', $group->id)
            ->forPermission($permissionName)
            ->negative()
            ->active()
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

        // Story 7.1 — Review #3 : ignorer les négatives expirées.
        $negativeGroupIds = Delegation::forUser($user)
            ->forPermission($permissionName)
            ->negative()
            ->active()
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

    /**
     * Contexte minimal à attacher aux entrées d'audit : IP + user-agent si dispo.
     * Renvoie un tableau vide si aucune requête HTTP n'est en cours (CLI, job).
     */
    private function buildContext(): array
    {
        $ctx = [];
        $request = request();
        if ($request !== null) {
            $ip = $request->ip();
            $ua = $request->userAgent();
            if ($ip) {
                $ctx['ip'] = $ip;
            }
            if ($ua) {
                $ctx['user_agent'] = mb_substr((string) $ua, 0, 500);
            }
        }
        return $ctx;
    }
}
