<?php

declare(strict_types=1);

namespace App\Services\Wallpaper;

use App\Dto\Wallpaper\WallpaperContext;
use App\Dto\Wallpaper\WallpaperResolution;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\WorkstationGroup;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Support\Facades\Log;

/**
 * Résolution wallpaper / lockscreen — reproduit les 7 niveaux legacy.
 *
 * Story 4.7 — AC 4. Refonte bibliothèque (2026-06) : la résolution se fait
 * **exclusivement** via les assignations DB (owner → asset). Le fallback
 * historique par convention de nom de fichier (`<type>@<name>.jpg`) a été
 * supprimé — il produisait des « wallpapers fantômes » (fichier sur disque
 * sans lien DB explicite). Tout fichier legacy a été rapatrié en asset par
 * la migration `backfill_wallpaper_assets`.
 *
 * Priorité croissante (dernier match gagne) :
 *   1. default.jpg (système, fichier hors bibliothèque)
 *   2. défaut étab (assignation owner=NULL is_default=true)
 *   3. salle (WorkstationGroup physique)
 *   4. type principal (UserGroup Profs/Eleves/Administratifs)
 *   5. groupe AD (un des groupes user, premier match)
 *   6. user
 *   7. /home/<user>/Photos/wallpaper.jpg (perso_wallpaper activé — fichier
 *      personnel hors bibliothèque)
 *   Override — quota hard-over → WallpaperResolution::quotaOverride()
 *
 * Lockscreen : niveaux 1→3 uniquement (fidèle `make_lockscreen`).
 *
 * Perf : ≤ 4 queries (3 lookups d'IDs + 1 query assignations jointe aux assets).
 */
class WallpaperResolver
{
    public function __construct(
        private readonly ?XfsQuotaService $quotaService = null,
    ) {}

    /**
     * @param  string  $type  'wallpaper' | 'lockscreen'
     */
    public function resolve(WallpaperContext $ctx, string $type): WallpaperResolution
    {
        // Override quota — wallpaper uniquement (pas lockscreen)
        if (
            $type === Wallpaper::TYPE_WALLPAPER
            && $ctx->userLogin !== ''
            && $this->quotaService !== null
            && $this->isUserOverQuotaSafe($ctx->userLogin)
        ) {
            return WallpaperResolution::quotaOverride();
        }

        $isLockscreen = $type === Wallpaper::TYPE_LOCKSCREEN;

        // Résolution des owner_id candidats (1 query par table concernée).
        $salleId = $ctx->salleName !== ''
            ? $this->lookupWorkstationGroupId($ctx->salleName)
            : null;

        $userGroupMap = [];
        $userId = null;
        if (! $isLockscreen) {
            $groupNames = array_values(array_filter(array_merge(
                $ctx->mainUserType !== null && $ctx->mainUserType !== '' ? [$ctx->mainUserType] : [],
                $ctx->groupsUser,
            )));
            if ($groupNames !== []) {
                $userGroupMap = $this->lookupUserGroupIds($groupNames);
            }
            $userId = $ctx->userLogin !== '' ? $this->lookupUserId($ctx->userLogin) : null;
        }

        // Query unique : assignations jointes aux assets (les lignes sans asset
        // sont écartées par le INNER JOIN).
        $dbRows = $this->queryWallpapers($type, $salleId, $userGroupMap, $userId);

        $dbByOwner = [];
        $dbDefault = null;
        foreach ($dbRows as $row) {
            if ($row->owner_id === null && $row->is_default) {
                $dbDefault = $row;
            } else {
                $dbByOwner[$row->owner_type . ':' . $row->owner_id] = $row;
            }
        }

        // Niveau 1 — fallback système (fichier hors bibliothèque).
        $best = $this->fallbackDefaultSystem();

        // Niveau 2 — défaut étab.
        if ($dbDefault !== null) {
            $best = new WallpaperResolution(
                sourcePath: $this->assetPath($dbDefault->asset_filename),
                level: WallpaperResolution::LEVEL_DEFAULT_ETAB,
                ownerType: null,
                ownerName: 'étab',
            );
        }

        // Niveau 3 — salle.
        if ($salleId !== null) {
            $best = $this->pickOwner(
                $dbByOwner, $best, WorkstationGroup::class, $salleId,
                $ctx->salleName, WallpaperResolution::LEVEL_SALLE,
            );
        }

        if (! $isLockscreen) {
            $mainTypeName = $ctx->mainUserType;

            // Niveau 4 — type principal.
            if ($mainTypeName !== null && $mainTypeName !== '' && isset($userGroupMap[$mainTypeName])) {
                $best = $this->pickOwner(
                    $dbByOwner, $best, UserGroup::class, $userGroupMap[$mainTypeName],
                    $mainTypeName, WallpaperResolution::LEVEL_MAIN_TYPE,
                );
            }

            // Niveau 5 — groupes AD (premier match wins).
            foreach ($ctx->groupsUser as $groupName) {
                if ($groupName === $mainTypeName || ! isset($userGroupMap[$groupName])) {
                    continue;
                }
                $candidate = $this->pickOwner(
                    $dbByOwner, $best, UserGroup::class, $userGroupMap[$groupName],
                    $groupName, WallpaperResolution::LEVEL_GROUP,
                );
                if ($candidate !== $best) {
                    $best = $candidate;
                    break;
                }
            }

            // Niveau 6 — user.
            if ($userId !== null) {
                $best = $this->pickOwner(
                    $dbByOwner, $best, User::class, $userId,
                    $ctx->userLogin, WallpaperResolution::LEVEL_USER,
                );
            }

            // Niveau 7 — perso (<base>/<login>/Photos/wallpaper.jpg, fichier perso).
            if ((bool) config('wallpapers.perso_wallpaper', false) && $ctx->userLogin !== '') {
                $baseHome = rtrim((string) config('wallpapers.personal_base_path', '/home'), '/');
                $personal = $baseHome . '/' . $ctx->userLogin . '/Photos/wallpaper.jpg';
                if (is_file($personal)) {
                    $best = new WallpaperResolution(
                        sourcePath: $personal,
                        level: WallpaperResolution::LEVEL_HOME_PERSO,
                        ownerType: User::class,
                        ownerName: $ctx->userLogin,
                    );
                }
            }
        }

        return $best;
    }

    /** Fallback final : `default.jpg` système. */
    private function fallbackDefaultSystem(): WallpaperResolution
    {
        return new WallpaperResolution(
            sourcePath: (string) config('wallpapers.system_default_path'),
            level: WallpaperResolution::LEVEL_DEFAULT_SYSTEM,
        );
    }

    /**
     * Retourne une résolution si une assignation DB existe pour cet owner,
     * sinon conserve la résolution courante (plus de fallback filesystem).
     *
     * @param  array<string, object>  $dbByOwner  indexé par `owner_type:owner_id`
     */
    private function pickOwner(
        array $dbByOwner,
        WallpaperResolution $current,
        string $ownerType,
        int $ownerId,
        ?string $name,
        int $level,
    ): WallpaperResolution {
        $key = $ownerType . ':' . $ownerId;
        if (isset($dbByOwner[$key])) {
            return new WallpaperResolution(
                sourcePath: $this->assetPath($dbByOwner[$key]->asset_filename),
                level: $level,
                ownerType: $ownerType,
                ownerName: $name,
            );
        }

        return $current;
    }

    private function assetPath(?string $filename): string
    {
        if ($filename === null || $filename === '') {
            return '';
        }

        return WallpaperAsset::libraryPath() . '/' . $filename;
    }

    /**
     * @param  array<string,int>  $userGroupMap  name → id
     * @return list<object>  rows : ->owner_type, ->owner_id, ->is_default, ->asset_filename
     */
    private function queryWallpapers(
        string $type,
        ?int $salleId,
        array $userGroupMap,
        ?int $userId,
    ): array {
        $query = Wallpaper::query()
            ->from('wallpapers')
            ->ofType($type)
            ->join('wallpaper_assets', 'wallpapers.asset_id', '=', 'wallpaper_assets.id');

        $query->where(function ($q) use ($salleId, $userGroupMap, $userId): void {
            $q->orWhere(fn ($qq) => $qq->whereNull('wallpapers.owner_id')->where('wallpapers.is_default', true));

            if ($salleId !== null) {
                $q->orWhere(fn ($qq) => $qq
                    ->where('wallpapers.owner_type', WorkstationGroup::class)
                    ->where('wallpapers.owner_id', $salleId));
            }

            if ($userGroupMap !== []) {
                $q->orWhere(fn ($qq) => $qq
                    ->where('wallpapers.owner_type', UserGroup::class)
                    ->whereIn('wallpapers.owner_id', array_values($userGroupMap)));
            }

            if ($userId !== null) {
                $q->orWhere(fn ($qq) => $qq
                    ->where('wallpapers.owner_type', User::class)
                    ->where('wallpapers.owner_id', $userId));
            }
        });

        return $query->get([
            'wallpapers.owner_type',
            'wallpapers.owner_id',
            'wallpapers.is_default',
            'wallpaper_assets.filename as asset_filename',
        ])->all();
    }

    private function lookupWorkstationGroupId(string $name): ?int
    {
        try {
            /** @var int|null $id */
            $id = WorkstationGroup::query()->where('name', $name)->value('id');

            return $id !== null ? (int) $id : null;
        } catch (\Throwable $e) {
            Log::warning('[WallpaperResolver] lookup workstation_group failed', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  string[]  $names
     * @return array<string,int>  name → id
     */
    private function lookupUserGroupIds(array $names): array
    {
        if ($names === []) {
            return [];
        }
        try {
            return UserGroup::query()
                ->whereIn('name', $names)
                ->pluck('id', 'name')
                ->map(static fn ($v): int => (int) $v)
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[WallpaperResolver] lookup user_groups failed', [
                'names' => $names,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function lookupUserId(string $login): ?int
    {
        try {
            /** @var int|null $id */
            $id = User::query()->where('login', $login)->value('id');

            return $id !== null ? (int) $id : null;
        } catch (\Throwable $e) {
            Log::warning('[WallpaperResolver] lookup user failed', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function isUserOverQuotaSafe(string $login): bool
    {
        if ($this->quotaService === null) {
            return false;
        }
        try {
            // @phpstan-ignore-next-line
            return (bool) $this->quotaService->isUserOverQuota($login);
        } catch (\Throwable $e) {
            Log::warning('[WallpaperResolver] quota check failed', [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
