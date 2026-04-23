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

    // ========================================================================
    // Story 7.2 — Rapatriement profils LDAP custom (AC4)
    // ========================================================================

    /**
     * Noms des 5 profils seedés à l'installation, qu'on NE rapatrie PAS depuis
     * l'AD (déjà gérés par `PermissionSeeder` via l'enum `SambaRole`).
     *
     * Source legacy : `sambaedu/includes/ldap.inc.php:739-743`.
     */
    private const SEEDED_PROFILE_NAMES = [
        'se3_is_admin',
        'computer_is_admin',
        'Annu_is_admin',
        'password_is_admin',
        'RefNum',
    ];

    /**
     * Mapping des profils legacy historiques (non seedés, mais reconnus par
     * fallback dans `annu/profiles.php:56-63`) vers les rôles Spatie.
     */
    private const HISTORIC_PROFILE_TO_ROLE = [
        'sovajon_is_admin'    => 'eleve-admin',       // → `SambaRole::EleveAdmin`
        'annu_can_read'       => 'prof',              // → `SambaRole::Prof` (scoping classe)
        'password_can_reinit' => null,                // délégation ciblée, pas un rôle
    ];

    /**
     * Rapatrie les profils custom de la branche LDAP `rights_rdn` vers la base
     * SER, sans écraser les profils existants.
     *
     * Story 7.2 (AC4) — Non-destructif strict :
     *  - Un profil seedé (cf. `SEEDED_PROFILE_NAMES`) : ignoré (géré par le seeder).
     *  - Un profil historique (cf. `HISTORIC_PROFILE_TO_ROLE`) : le rôle Spatie
     *    correspondant est créé via `firstOrCreate` si absent, sans re-sync
     *    des permissions.
     *  - Un profil custom : `Role::firstOrCreate` + `syncPermissions(...)`
     *    **seulement** si `wasRecentlyCreated` (première rencontre). Les
     *    profils custom déjà en base SER ne sont jamais modifiés.
     *  - Bug legacy fallback `Annu_is_admin` sans `info` → `SE_COMPUTER_ADMIN`
     *    (cf. matrice §8 #6) : **ignoré**. On log `warning` + mapping forcé
     *    sur `user-admin` (seed d'origine 0xFF) si ce cas se présente.
     *
     * @return array{
     *   scanned: int,
     *   seeded_skipped: int,
     *   historic_mapped: int,
     *   custom_new: int,
     *   custom_unchanged: int,
     *   errors: int,
     * }
     */
    public function importCustomProfilesFromAd(?callable $logger = null, ?callable $profilesFetcher = null): array
    {
        $log = $logger ?? fn(string $lvl, string $msg) => Log::log($lvl, "[PermissionService/importCustomProfilesFromAd] {$msg}");

        $stats = [
            'scanned'          => 0,
            'seeded_skipped'   => 0,
            'historic_mapped'  => 0,
            'custom_new'       => 0,
            'custom_unchanged' => 0,
            'errors'           => 0,
        ];

        // Story 7.2 — Tests : on injecte un fetcher de profils mocké. Par défaut,
        // on utilise le shim AD réel via LdapRightGroup.
        $fetcher = $profilesFetcher ?? fn() => \App\LdapModels\LdapRightGroup::getAllRightsValues();

        try {
            $rightsValues = $fetcher();
        } catch (\Throwable $e) {
            $log('error', "Impossible de scanner la branche Rights : " . $e->getMessage());
            $stats['errors']++;
            return $stats;
        }

        foreach ($rightsValues as $cn => $info) {
            $stats['scanned']++;

            try {
                // Cas 1 : profil seedé — ignoré (géré par PermissionSeeder).
                if (in_array($cn, self::SEEDED_PROFILE_NAMES, true)) {
                    $stats['seeded_skipped']++;
                    continue;
                }

                // Cas 2 : profil historique reconnu par mapping.
                if (array_key_exists($cn, self::HISTORIC_PROFILE_TO_ROLE)) {
                    $targetRole = self::HISTORIC_PROFILE_TO_ROLE[$cn];
                    if ($targetRole !== null) {
                        $role = Role::firstOrCreate(
                            ['name' => $targetRole, 'guard_name' => 'web']
                        );
                        if ($role->wasRecentlyCreated) {
                            // Seulement à la première création — attacher les perms canoniques.
                            $sambaRole = SambaRole::tryFrom($targetRole);
                            if ($sambaRole !== null) {
                                $role->syncPermissions($sambaRole->permissionNames());
                            }
                        }
                        $stats['historic_mapped']++;
                    } else {
                        // Correction review 7.2 #9 — `password_can_reinit` mappe
                        // vers `null` (délégation ciblée, pas un rôle Spatie
                        // unique). On log explicitement plutôt que d'ignorer
                        // silencieusement pour que l'admin puisse les repérer
                        // et faire l'import manuel.
                        $log('warning', "Profil historique '{$cn}' mappé sur délégation ciblée — import manuel requis, profil non créé.");
                    }
                    continue;
                }

                // Cas 3 : profil custom.
                $role = Role::firstOrCreate(
                    ['name' => $cn, 'guard_name' => 'web']
                );

                if ($role->wasRecentlyCreated) {
                    // Première apparition : attacher les perms depuis le bitmask.
                    $bitmaskInt = (int) $info;
                    $perms = SambaPermission::fromBitmask($bitmaskInt);

                    // Correction review 7.2 #2 — Convention matrice §11 :
                    // `AppCustomize` partage le bit 0x800 avec `ComputerInstall`.
                    // On ne l'accorde à un profil custom QUE si le bitmask porte
                    // la totalité du composite `ComputerAdmin` (iso `gpo/firefox.php`
                    // qui était gardé par SE_COMPUTER_ADMIN). Sinon, un profil
                    // custom avec juste 0x900 (View+Install) recevrait `app.customize`
                    // par erreur.
                    $computerAdminMask = \App\Enums\LegacyRight::computerAdmin();
                    if (($bitmaskInt & $computerAdminMask) !== $computerAdminMask) {
                        $perms = array_values(array_filter(
                            $perms,
                            fn(string $p) => $p !== SambaPermission::AppCustomize->value
                        ));
                    }

                    if (!empty($perms)) {
                        $role->syncPermissions($perms);
                    }
                    $stats['custom_new']++;
                    $log('info', "Profil custom '{$cn}' rapatrié avec " . count($perms) . " permission(s) (info=0x" . dechex($bitmaskInt) . ")");
                } else {
                    // Déjà en base : on ne touche rien (strict non-destructif).
                    $stats['custom_unchanged']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                $log('warning', "Erreur sur le profil '{$cn}' : " . $e->getMessage());
            }
        }

        // Invalide le cache Spatie pour que les nouveaux rôles soient visibles immédiatement.
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $log('info', sprintf(
            "Rapatriement terminé : %d scannés, %d seedés ignorés, %d historiques mappés, %d custom nouveaux, %d custom inchangés, %d erreurs",
            $stats['scanned'], $stats['seeded_skipped'], $stats['historic_mapped'],
            $stats['custom_new'], $stats['custom_unchanged'], $stats['errors']
        ));

        return $stats;
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
