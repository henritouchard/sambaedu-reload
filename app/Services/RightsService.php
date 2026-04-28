<?php

namespace App\Services;

use App\Enums\LegacyRight;
use App\Enums\SambaPermission;
use App\Models\Delegation;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Repositories\RightRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service de gestion des droits SambaEdu
 *
 * Story 7.3 — Refactor Spatie-only (2026-04-25) :
 *  - `calculateRights()` ne lit plus les groupes LDAP (attribut `info`). Il
 *    reconstruit le bitmask uniquement à partir de Spatie (rôles + permissions
 *    individuelles + délégations scopées OR positifs / AND-NOT négatifs).
 *  - Le contrat public de retour (`int` bitmask) est préservé — aucun appelant
 *    n'a à modifier son code. La signature `(array $rightGroups, string $login)`
 *    est aussi préservée pour rétro-compatibilité.
 *  - Le bit `SE_COMPUTER_VIEW` est filtré systématiquement (droit web pur qui
 *    n'est jamais remonté au bitmask LDAP, cf. shim `legacy/ldap.inc.php:50`
 *    et legacy `sambaedu/includes/ldap.inc.php:2963`).
 *
 * Les appels legacy qui passent encore `(array $rightGroups, string $login)`
 * continuent de marcher :
 *  - `$login === 'admin'` → `SE_ADMIN (0xFFFF)` (cas spécial historique).
 *  - Autrement, on résout le `User` Eloquent via `$login` et on calcule son
 *    bitmask Spatie-only. Les `$rightGroups` en argument sont ignorés par
 *    7.3 — c'était une dépendance sur la lecture LDAP qui n'a plus de sens.
 *
 * Les anciennes méthodes statiques (`getRightDescription`, `getRightDetails`,
 * `getRightsDefinitions`) restent disponibles via `LegacyRight` pour le
 * rendu UI. Elles sont marquées `@deprecated` — la sunset sera effective
 * dans une PR séparée post-stabilisation prod (≥ 2 semaines).
 */
class RightsService
{
    private RightRepository $rightRepository;

    public function __construct(?RightRepository $rightRepository = null)
    {
        $this->rightRepository = $rightRepository ?? new RightRepository();
    }

    // ============================================
    // CONSTANTES LEGACY (délèguent vers LegacyRight enum)
    // @deprecated Utiliser LegacyRight enum directement
    // ============================================

    public const SE_NO_RIGHT = 0x00;
    public const SE_USER_PASSWORD_INIT = 0x01;
    public const SE_USER_READ = 0x02;
    public const SE_USER_MODIFY = 0x04;
    public const SE_USER_CREATE_TEMP = 0x08;
    public const SE_USER_ASSIGN_RIGHT = 0x10;
    public const SE_USER_DELEGATE = 0x20;
    public const SE_SHARE_VIEW = 0x40;
    public const SE_SHARE_REFRESH = 0x80;
    public const SE_SHARE_ADMIN = 0xC0;
    public const SE_ELEVE_ADMIN = 0x07;
    public const SE_USER_ADMIN = 0xFF;
    public const SE_COMPUTER_VIEW = 0x100;
    public const SE_COMPUTER_CONTROL = 0x200;
    public const SE_COMPUTER_ELEVATE = 0x400;
    public const SE_COMPUTER_INSTALL = 0x800;
    public const SE_WPKG_ASSIGN = 0x1000;
    public const SE_WPKG_ADD = 0x2000;
    public const SE_WPKG_CREATE = 0x4000;
    public const SE_COMPUTER_ADMIN = 0xEF00;
    public const SE_SERVER_ADMIN = 0x8000;
    public const SE_ADMIN = 0xFFFF;

    /**
     * Calcule le bitmask de droits pour un utilisateur (Spatie-only, Story 7.3).
     *
     * Contrat (inchangé depuis 7.1) :
     *  - Entrée : liste des groupes LDAP legacy (ignorée en 7.3 — conservée pour rétro-compat signature) + login
     *  - Sortie : `int` bitmask des droits applicatifs
     *
     * Pipeline interne (7.3) :
     *  1. Cas spécial `admin` / root → `SE_ADMIN`.
     *  2. Résolution du User Eloquent via `$login`.
     *  3. Récupération des permissions effectives Spatie (`getAllPermissions`).
     *  4. Projection vers bitmask via `SambaPermission::toBitmask`.
     *  5. Filtre `SE_COMPUTER_VIEW` (jamais bitmasqué — droit web pur).
     *
     * Aucune lecture LDAP/`RightRepository` n'est effectuée — garanti par le
     * test `RightsServiceSpatieRefactorTest::it_works_even_if_ldap_is_down`.
     *
     * @param  array<int,string>  $rightGroups  Legacy — ignoré en 7.3 (conservé signature)
     * @param  string  $login  Login de l'utilisateur
     * @return int  Bitmask agrégé
     */
    public function calculateRights(array $rightGroups, string $login = ''): int
    {
        // Cas spécial root/admin : `SE_ADMIN` (tous les droits).
        if ($login === 'admin') {
            return LegacyRight::admin();
        }

        if ($login === '') {
            return LegacyRight::none();
        }

        // Résolution robuste : si la table `users` n'existe pas (tests legacy
        // sans schéma Spatie) ou si l'user est introuvable, on retourne `none`.
        try {
            $user = User::where('login', $login)->first();
        } catch (Throwable $e) {
            Log::debug('[RightsService] Résolution User Eloquent impossible', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);

            return LegacyRight::none();
        }

        if ($user === null) {
            return LegacyRight::none();
        }

        return $this->calculateRightsForUser($user);
    }

    /**
     * Calcule le bitmask effectif d'un User Eloquent depuis Spatie uniquement.
     *
     * Accepte optionnellement un `WorkstationGroup` pour scope les délégations
     * (ajoute les positives actives sur ce scope, retranche les négatives
     * actives — sémantique AND-NOT cf. matrice §7).
     *
     * @param  User  $user  Utilisateur cible (Eloquent)
     * @param  WorkstationGroup|null  $scope  Scope optionnel pour délégations
     */
    public function calculateRightsForUser(User $user, ?WorkstationGroup $scope = null): int
    {
        // 1. Permissions effectives Spatie (rôles + directes).
        $permissionNames = [];
        try {
            $permissionNames = $user->getAllPermissions()->pluck('name')->toArray();
        } catch (Throwable $e) {
            Log::warning('[RightsService] getAllPermissions a échoué', [
                'user' => $user->login ?? '?',
                'error' => $e->getMessage(),
            ]);
        }

        $bitmask = SambaPermission::toBitmask($permissionNames);

        // 2. Délégations scopées (seulement si scope fourni — matrice §7).
        if ($scope !== null) {
            try {
                // Positives actives sur ce scope → OR au bitmask.
                $positivePerms = Delegation::forUser($user)
                    ->where('workstation_group_id', $scope->id)
                    ->positive()
                    ->active()
                    ->with('permission')
                    ->get()
                    ->pluck('permission.name')
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                if (! empty($positivePerms)) {
                    $bitmask |= SambaPermission::toBitmask($positivePerms);
                }

                // Négatives actives sur ce scope → AND-NOT au bitmask.
                $negativePerms = Delegation::forUser($user)
                    ->where('workstation_group_id', $scope->id)
                    ->negative()
                    ->active()
                    ->with('permission')
                    ->get()
                    ->pluck('permission.name')
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                if (! empty($negativePerms)) {
                    $bitmask &= ~SambaPermission::toBitmask($negativePerms);
                }
            } catch (Throwable $e) {
                Log::warning('[RightsService] Lecture délégations scopées a échoué', [
                    'user' => $user->login ?? '?',
                    'scope' => $scope->name ?? '?',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3. Filtre SE_COMPUTER_VIEW (jamais bitmasqué — droit web pur).
        //    Cf. shim `legacy/ldap.inc.php:50` et legacy `ldap.inc.php:2963`.
        $bitmask &= ~LegacyRight::ComputerView->value;

        return $bitmask;
    }

    /**
     * Vérifie si un bitmask de droits contient un droit spécifique
     *
     * @param int $userRights Bitmask des droits de l'utilisateur
     * @param int $requiredRight Droit requis à vérifier
     * @param bool $or Si true, vérifie si AU MOINS UN des bits est présent
     * @return bool
     */
    public function hasRight(int $userRights, int $requiredRight, bool $or = false): bool
    {
        if ($or) {
            // Au moins un des droits demandés est présent
            return ($requiredRight & $userRights) !== 0;
        } else {
            // Tous les droits demandés sont présents
            return (~(~$requiredRight | $userRights)) === 0;
        }
    }

    /**
     * Invalide le cache des groupes de droits
     *
     * @deprecated since 7.3 — le calcul ne passe plus par le cache LDAP. Gardé
     * pour rétro-compat des appelants qui invalident après édition LDAP.
     */
    public function invalidateCache(): void
    {
        $this->rightRepository->invalidateCache();
    }

    /**
     * Retourne la description d'un droit
     * @deprecated Utiliser LegacyRight::fromBitmask() + ->label()
     */
    public static function getRightDescription(int $right): array
    {
        return collect(LegacyRight::fromBitmask($right))
            ->mapWithKeys(fn(LegacyRight $r) => [$r->value => $r->label()])
            ->toArray();
    }

    /**
     * Retourne les informations détaillées des droits effectifs
     * @deprecated Utiliser LegacyRight::fromBitmask() directement
     */
    public static function getRightDetails(int $right): array
    {
        return collect(LegacyRight::fromBitmask($right))
            ->mapWithKeys(fn(LegacyRight $r) => [$r->value => [
                'name' => $r->constantName(),
                'label' => $r->label(),
                'description' => $r->description(),
            ]])
            ->toArray();
    }

    /**
     * Définitions complètes des droits avec nom, label et description
     * @deprecated Utiliser LegacyRight::definitions()
     */
    public static function getRightsDefinitions(): array
    {
        return LegacyRight::definitions();
    }
}
