<?php

declare(strict_types=1);

namespace App\Services\Wallpaper;

use App\Dto\Wallpaper\WallpaperContext;
use App\Dto\Wallpaper\WallpaperResolution;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\WorkstationGroup;
use App\Services\QuotaService;
use Illuminate\Support\Facades\Log;

/**
 * Résolution wallpaper / lockscreen — reproduit les 7 niveaux legacy.
 *
 * Story 4.7 — AC 4.
 *
 * Priorité croissante (dernier match gagne) :
 *   1. default.jpg (système)
 *   2. wallpaper.jpg (défaut étab, owner=NULL is_default=true)
 *   3. wallpaper@<salle>.jpg (WorkstationGroup physique)
 *   4. wallpaper@<type>.jpg (UserGroup Profs/Eleves/Administratifs)
 *   5. wallpaper@<groupe AD>.jpg (un des groupes user, premier match)
 *   6. wallpaper@<login>.jpg (User)
 *   7. /home/<user>/Photos/wallpaper.jpg (perso_wallpaper activé)
 *   Override — quota hard-over → WallpaperResolution::quotaOverride()
 *
 * Lockscreen : niveaux 1→3 uniquement (fidèle `make_lockscreen`).
 *
 * Optimisation : la DB est interrogée par **2 queries max** :
 *   - 1 query `whereIn` sur les IDs d'owners candidates (défaut NULL + salle + main type + groupes user + user)
 *   - 1 query pour résoudre les IDs (User/UserGroup/WorkstationGroup par name/login)
 *   → total ≤ 3 queries par appel (cf. AC 12 perf).
 */
class WallpaperResolver
{
    public function __construct(
        private readonly ?QuotaService $quotaService = null,
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

        // Build candidate list (niveau + lookup clé)
        // Pour lockscreen : niveaux 1 (default), 2 (étab), 3 (salle) seulement.
        $isLockscreen = $type === Wallpaper::TYPE_LOCKSCREEN;

        $candidates = [
            // niveau 1 traité en fallback filesystem après les lookups DB
            // niveau 2 : défaut étab en DB (owner NULL + is_default=true)
            'etab' => ['level' => WallpaperResolution::LEVEL_DEFAULT_ETAB],
            // niveau 3 : salle
            'salle' => [
                'level' => WallpaperResolution::LEVEL_SALLE,
                'name' => $ctx->salleName,
            ],
        ];

        if (! $isLockscreen) {
            $candidates['main_type'] = [
                'level' => WallpaperResolution::LEVEL_MAIN_TYPE,
                'name' => $ctx->mainUserType,
            ];
            $candidates['groups'] = [
                'level' => WallpaperResolution::LEVEL_GROUP,
                'names' => $ctx->groupsUser,
            ];
            $candidates['user'] = [
                'level' => WallpaperResolution::LEVEL_USER,
                'login' => $ctx->userLogin,
            ];
        }

        // Query 1+2 : résoudre owner_id pour chaque candidate en DB (1 par table)
        $salleId = $candidates['salle']['name'] !== ''
            ? $this->lookupWorkstationGroupId((string) $candidates['salle']['name'])
            : null;

        $userGroupMap = [];
        if (! $isLockscreen) {
            $groupNames = array_values(array_filter(array_merge(
                $candidates['main_type']['name'] !== null ? [$candidates['main_type']['name']] : [],
                $candidates['groups']['names'],
            )));
            if ($groupNames !== []) {
                $userGroupMap = $this->lookupUserGroupIds($groupNames);
            }
        }

        $userId = ! $isLockscreen && $ctx->userLogin !== ''
            ? $this->lookupUserId($ctx->userLogin)
            : null;

        // Query 3 : one-shot DB query sur tous les owners possibles + défaut étab
        $dbRows = $this->queryWallpapers($type, $salleId, $userGroupMap, $userId);

        // Index par (owner_type, owner_id) et défaut étab
        $dbByOwner = [];
        $dbDefault = null;
        foreach ($dbRows as $row) {
            if ($row->owner_id === null && $row->is_default) {
                $dbDefault = $row;
            } else {
                $key = $row->owner_type . ':' . $row->owner_id;
                $dbByOwner[$key] = $row;
            }
        }

        // Walk niveaux du plus bas (1) au plus haut (7) — dernier match gagne
        $best = $this->fallbackDefaultSystem();

        // niveau 2 : défaut étab
        if ($dbDefault !== null) {
            $best = new WallpaperResolution(
                sourcePath: (string) $dbDefault->path,
                level: WallpaperResolution::LEVEL_DEFAULT_ETAB,
                ownerType: null,
                ownerName: 'étab',
            );
        } else {
            // fallback filesystem wallpaper.jpg / lockscreen.jpg étab
            $fsPath = $this->storagePath() . '/' . $type . '.jpg';
            if (is_file($fsPath)) {
                $best = new WallpaperResolution(
                    sourcePath: $fsPath,
                    level: WallpaperResolution::LEVEL_DEFAULT_ETAB,
                    ownerType: null,
                    ownerName: 'étab',
                );
            }
        }

        // niveau 3 : salle
        if ($salleId !== null) {
            $best = $this->pickOwnerOrFallback(
                $dbByOwner,
                $best,
                WorkstationGroup::class,
                $salleId,
                $type,
                $candidates['salle']['name'],
                WallpaperResolution::LEVEL_SALLE,
            );
        } elseif ($candidates['salle']['name'] !== '') {
            // DB n'a pas d'entry mais peut-être un fichier FS pré-seed
            $best = $this->fallbackFs($best, $candidates['salle']['name'], $type, WallpaperResolution::LEVEL_SALLE, 'salle');
        }

        if (! $isLockscreen) {
            // niveau 4 : type principal (Profs/Eleves/Administratifs)
            $mainTypeName = $candidates['main_type']['name'];
            if ($mainTypeName !== null && $mainTypeName !== '') {
                if (isset($userGroupMap[$mainTypeName])) {
                    $best = $this->pickOwnerOrFallback(
                        $dbByOwner,
                        $best,
                        UserGroup::class,
                        $userGroupMap[$mainTypeName],
                        $type,
                        $mainTypeName,
                        WallpaperResolution::LEVEL_MAIN_TYPE,
                    );
                } else {
                    $best = $this->fallbackFs($best, $mainTypeName, $type, WallpaperResolution::LEVEL_MAIN_TYPE, 'main_type');
                }
            }

            // niveau 5 : groupes AD (premier match wins)
            // Post-review #C : pour éviter n×is_file() quand l'utilisateur a
            // beaucoup de groupes AD non-DB, on ne fallback FS QUE sur les
            // groupes dont la présence DB a déjà matché. Si un groupe n'est
            // pas dans le map DB et n'a pas de fichier legacy attendu, pas
            // de stat() inutile.
            foreach ($candidates['groups']['names'] as $groupName) {
                if ($groupName === $mainTypeName) {
                    continue; // déjà traité niveau 4
                }
                if (isset($userGroupMap[$groupName])) {
                    // Présent en DB : on tente DB + fallback FS pour CE groupe uniquement
                    $candidateBest = $this->pickOwnerOrFallback(
                        $dbByOwner,
                        $best,
                        UserGroup::class,
                        $userGroupMap[$groupName],
                        $type,
                        $groupName,
                        WallpaperResolution::LEVEL_GROUP,
                    );
                    if ($candidateBest !== $best) {
                        $best = $candidateBest;
                        break;
                    }
                }
                // Absent du map DB → skip direct, pas de fallback FS. Si un
                // fichier legacy `wallpaper@<groupe>.jpg` existe sans
                // correspondance DB, le seeder doit être relancé pour créer
                // l'entrée UserGroup.
            }

            // niveau 6 : user
            if ($userId !== null) {
                $best = $this->pickOwnerOrFallback(
                    $dbByOwner,
                    $best,
                    User::class,
                    $userId,
                    $type,
                    $ctx->userLogin,
                    WallpaperResolution::LEVEL_USER,
                );
            } elseif ($ctx->userLogin !== '') {
                $best = $this->fallbackFs($best, $ctx->userLogin, $type, WallpaperResolution::LEVEL_USER, 'user');
            }

            // niveau 7 : perso_wallpaper (<base>/<login>/Photos/wallpaper.jpg)
            // Post-review #8 : base path configurable via
            // `config('wallpapers.personal_base_path')` (default /home).
            if (
                (bool) config('wallpapers.perso_wallpaper', false)
                && $ctx->userLogin !== ''
            ) {
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
     * Construit une nouvelle résolution si row DB trouvée pour cet owner —
     * sinon tente le fallback filesystem `<type>@<name>.jpg`.
     *
     * @param  array<string, object>  $dbByOwner  indexé par `owner_type:owner_id`
     */
    private function pickOwnerOrFallback(
        array $dbByOwner,
        WallpaperResolution $current,
        string $ownerType,
        int $ownerId,
        string $type,
        ?string $name,
        int $level,
    ): WallpaperResolution {
        $key = $ownerType . ':' . $ownerId;
        if (isset($dbByOwner[$key])) {
            return new WallpaperResolution(
                sourcePath: (string) $dbByOwner[$key]->path,
                level: $level,
                ownerType: $ownerType,
                ownerName: $name,
            );
        }

        return $this->fallbackFs($current, $name ?? '', $type, $level, $ownerType);
    }

    private function fallbackFs(
        WallpaperResolution $current,
        string $name,
        string $type,
        int $level,
        string $ownerType,
    ): WallpaperResolution {
        if ($name === '') {
            return $current;
        }
        $fsPath = $this->storagePath() . '/' . $type . '@' . $name . '.jpg';
        if (is_file($fsPath)) {
            return new WallpaperResolution(
                sourcePath: $fsPath,
                level: $level,
                ownerType: $ownerType,
                ownerName: $name,
            );
        }

        return $current;
    }

    /**
     * @param  array<string,int>  $userGroupMap  name → id
     * @return list<object>  rows avec ->path, ->owner_type, ->owner_id, ->is_default
     */
    private function queryWallpapers(
        string $type,
        ?int $salleId,
        array $userGroupMap,
        ?int $userId,
    ): array {
        $query = Wallpaper::query()->ofType($type);

        $query->where(function ($q) use ($salleId, $userGroupMap, $userId): void {
            // Défaut étab (owner NULL is_default=true)
            $q->orWhere(fn ($qq) => $qq->whereNull('owner_id')->where('is_default', true));

            if ($salleId !== null) {
                $q->orWhere(fn ($qq) => $qq
                    ->where('owner_type', WorkstationGroup::class)
                    ->where('owner_id', $salleId));
            }

            if ($userGroupMap !== []) {
                $q->orWhere(fn ($qq) => $qq
                    ->where('owner_type', UserGroup::class)
                    ->whereIn('owner_id', array_values($userGroupMap)));
            }

            if ($userId !== null) {
                $q->orWhere(fn ($qq) => $qq
                    ->where('owner_type', User::class)
                    ->where('owner_id', $userId));
            }
        });

        return $query->get(['path', 'owner_type', 'owner_id', 'is_default'])->all();
    }

    private function lookupWorkstationGroupId(string $name): ?int
    {
        try {
            /** @var int|null $id */
            $id = WorkstationGroup::query()
                ->where('name', $name)
                ->value('id');

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
                ->map(static fn($v): int => (int) $v)
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
            $id = User::query()
                ->where('login', $login)
                ->value('id');

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

    private function storagePath(): string
    {
        return rtrim((string) config('wallpapers.storage_path'), '/');
    }
}
