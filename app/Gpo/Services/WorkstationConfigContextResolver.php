<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\Dto\AppCustomization\AppContext;
use App\Dto\Gpo\WorkstationConfigContext;
use App\Dto\Wallpaper\WallpaperContext;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;

/**
 * Story 16.13 — D3 / AC4.
 *
 * Service de résolution serveur du contexte poste à partir du JWT
 * `workstation_uuid` (claim `sub`) injecté par le middleware
 * `auth.v1.workstation` (Story 16.10). Remplace la lecture APCu legacy
 * `apps.$id` (posée par `gpo/applications.php`) pour les postes migrés
 * qui n'utilisent plus la chaîne md5/APCu.
 *
 * **Source-of-truth DB** :
 *  - `Workstation::where('uuid', $workstationUuid)->first()`
 *  - Relation `Workstation::groups()` → `WorkstationGroup` (héritée 15.2).
 *    Heuristique iso `ApplicationsScriptsController::resolveInfo` :130-141 :
 *    premier `WorkstationGroup` avec `is_physical=true` (= salle) ; fallback
 *    sur le premier groupe attaché si aucun physique.
 *  - `User::where('login', $userLogin)->first()` si `$userLogin` non-vide.
 *
 * **Mappers spécialisés (option β, cf. story D3)** :
 *  - `toWallpaperContext()` : retourne `\App\Dto\Wallpaper\WallpaperContext`
 *    consommable directement par `WallpaperResolver::resolve()`.
 *  - `toAppContext()` : retourne `\App\Dto\AppCustomization\AppContext`
 *    consommable directement par `AppCustomizationService` /
 *    `NetworkScriptGenerator` / `VeyonConfigGenerator` /
 *    `AssociationsResolver`.
 *
 * **Sécurité** : `$workstationUuid` doit IMPÉRATIVEMENT être lu via
 * `$request->attributes->get('auth_v1.workstation_uuid')` côté controller
 * (pattern iso 16.12). Jamais depuis query/body. Le service ne valide pas
 * la provenance du paramètre — c'est la responsabilité du caller.
 */
final class WorkstationConfigContextResolver
{
    /**
     * Reconstruit le contexte runtime poste à partir du JWT workstation_uuid
     * + paramètres query non-sensibles.
     *
     * @param  string  $workstationUuid  UUID extrait du JWT (claim `sub`).
     * @param  string  $os               « linux » | « windows » (query).
     * @param  string  $userLogin        Login user courant (query `?user=...`).
     * @param  string  $userProfile      Chemin profil Windows (query `?userprofile=...`).
     *
     * @return WorkstationConfigContext|null  null si le `workstation_uuid`
     *         est inconnu en DB (poste enrôlé mais row supprimée — cas
     *         marginal). Le controller doit alors retourner 404 +
     *         log warning (cf. story D5).
     */
    public function resolve(
        string $workstationUuid,
        string $os = 'linux',
        string $userLogin = '',
        string $userProfile = '',
    ): ?WorkstationConfigContext {
        $workstation = Workstation::query()
            ->where('uuid', $workstationUuid)
            ->first();

        if ($workstation === null) {
            return null;
        }

        // Heuristique iso `ApplicationsScriptsController::resolveInfo` :130 —
        // groupe principal = premier `WorkstationGroup` physique attaché
        // (= salle) ; fallback premier groupe logique si aucun physique.
        $primaryGroup = $this->resolvePrimaryGroup($workstation);

        $user = ($userLogin !== '')
            ? User::query()->where('login', $userLogin)->first()
            : null;

        return new WorkstationConfigContext(
            workstationUuid: $workstationUuid,
            machineName: (string) ($workstation->name ?? ''),
            salleName: (string) ($primaryGroup?->name ?? ''),
            userLogin: $userLogin,
            os: $os,
            userProfile: $userProfile,
            machineId: (int) $workstation->id,
            userId: $user?->id !== null ? (int) $user->id : null,
            groupId: $primaryGroup?->id !== null ? (int) $primaryGroup->id : null,
        );
    }

    /**
     * Mapper vers le DTO `WallpaperContext` (4.7) — consommable directement
     * par `WallpaperResolver::resolve($ctx, $type)` +
     * `WallpaperComposer::composeWallpaper(...)`.
     *
     * Les champs `groupsUser` / `mainUserType` sont **vides** dans cette
     * version (la résolution AD groups est hors-scope 16.13 — délégué à
     * une future story d'intégration AD côté JWT). Conséquence : la chaîne
     * `WallpaperResolver` retombera sur les niveaux template/auto/default/WG
     * sans appliquer les overrides per-user-group. Acceptable Phase 2 (les
     * surcharges per-user-group restent rares en pratique terrain).
     */
    public function toWallpaperContext(
        string $workstationUuid,
        string $os = 'linux',
        string $userLogin = '',
        string $userProfile = '',
    ): ?WallpaperContext {
        $ctx = $this->resolve($workstationUuid, $os, $userLogin, $userProfile);
        if ($ctx === null) {
            return null;
        }

        return new WallpaperContext(
            userLogin: $ctx->userLogin,
            userFullname: $ctx->userLogin, // pas de fullname AD disponible 16.13
            userIsAdmin: false,
            machineName: $ctx->machineName,
            salleName: $ctx->salleName,
            groupsUser: [],
            mainUserType: null,
            os: $ctx->os,
            timestamp: time(),
            raw: [
                'uuid' => $ctx->workstationUuid,
                'machine' => ['cn' => $ctx->machineName],
                'user' => ['cn' => $ctx->userLogin],
                'salle' => $ctx->salleName,
                'os' => $ctx->os,
                'userprofile' => $ctx->userProfile,
                'list_u' => [],
                'time' => time(),
                'admin' => false,
            ],
        );
    }

    /**
     * Mapper vers le DTO `AppContext` (4.8) — consommable par
     * `AppCustomizationService::resolvePoliciesForMachine($wg, $user, ...)`
     * (Firefox / Thunderbird) ainsi que `NetworkScriptGenerator`,
     * `VeyonConfigGenerator` et `AssociationsResolver`.
     *
     * Idem `toWallpaperContext()` : `groupsUser` / `mainUserType` vides
     * (résolution AD groups hors-scope 16.13).
     */
    public function toAppContext(
        string $workstationUuid,
        string $os = 'linux',
        string $userLogin = '',
        string $userProfile = '',
    ): ?AppContext {
        $ctx = $this->resolve($workstationUuid, $os, $userLogin, $userProfile);
        if ($ctx === null) {
            return null;
        }

        return new AppContext(
            userLogin: $ctx->userLogin,
            machineName: $ctx->machineName,
            salleName: $ctx->salleName,
            groupsUser: [],
            mainUserType: null,
            os: $ctx->os,
            timestamp: time(),
            raw: [
                'uuid' => $ctx->workstationUuid,
                'machine' => ['cn' => $ctx->machineName],
                'user' => ['cn' => $ctx->userLogin],
                'salle' => $ctx->salleName,
                'os' => $ctx->os,
                'userprofile' => $ctx->userProfile,
                'list_u' => [],
                'list' => [],
                'time' => time(),
            ],
        );
    }

    /**
     * Résout `WorkstationGroup` + `User` Eloquent pour les controllers qui
     * en ont besoin (AppPolicyController). Évite à chaque controller de
     * refaire les lookups.
     *
     * @return array{wg: ?WorkstationGroup, user: ?User}
     */
    public function resolveAppPolicyScope(
        string $workstationUuid,
        string $userLogin = '',
    ): array {
        $workstation = Workstation::query()
            ->where('uuid', $workstationUuid)
            ->first();

        if ($workstation === null) {
            return ['wg' => null, 'user' => null];
        }

        $wg = $this->resolvePrimaryGroup($workstation);
        $user = ($userLogin !== '')
            ? User::query()->where('login', $userLogin)->first()
            : null;

        return ['wg' => $wg, 'user' => $user];
    }

    /**
     * Heuristique de sélection du groupe principal d'un poste.
     *
     * Story 16.13 — itéré sur les groupes attachés via la relation
     * `groups()` (héritée 15.2). Priorité au premier `is_physical=true`
     * (= salle) ; fallback sur le premier attaché.
     *
     * Si aucun groupe n'est attaché → null (parité behavior : pas de salle).
     */
    private function resolvePrimaryGroup(Workstation $workstation): ?WorkstationGroup
    {
        $groups = $workstation->groups()->get();

        if ($groups->isEmpty()) {
            return null;
        }

        /** @var WorkstationGroup|null $physical */
        $physical = $groups->first(static fn (WorkstationGroup $g) => (bool) $g->is_physical === true);
        if ($physical !== null) {
            return $physical;
        }

        /** @var WorkstationGroup|null $first */
        $first = $groups->first();

        return $first;
    }
}
