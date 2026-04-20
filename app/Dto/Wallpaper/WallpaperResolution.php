<?php

declare(strict_types=1);

namespace App\Dto\Wallpaper;

/**
 * Résultat de la résolution wallpaper / lockscreen.
 *
 * Produit par {@see \App\Services\Wallpaper\WallpaperResolver::resolve}.
 * Indique quel niveau legacy a matché + le chemin source effectif.
 *
 * Niveaux (cf. story 4.7, AC 4) :
 *   1 — default.jpg système
 *   2 — wallpaper.jpg (défaut étab)
 *   3 — wallpaper@<salle>.jpg
 *   4 — wallpaper@<type principal>.jpg (Profs/Eleves/Administratifs)
 *   5 — wallpaper@<groupe AD>.jpg
 *   6 — wallpaper@<user login>.jpg
 *   7 — /home/<user>/Photos/wallpaper.jpg
 *   99 — override quota (flag transversal)
 */
final readonly class WallpaperResolution
{
    public const LEVEL_DEFAULT_SYSTEM = 1;
    public const LEVEL_DEFAULT_ETAB = 2;
    public const LEVEL_SALLE = 3;
    public const LEVEL_MAIN_TYPE = 4;
    public const LEVEL_GROUP = 5;
    public const LEVEL_USER = 6;
    public const LEVEL_HOME_PERSO = 7;
    public const LEVEL_QUOTA_OVERRIDE = 99;

    public function __construct(
        public string $sourcePath,
        public int $level,
        public ?string $ownerType = null,
        public ?string $ownerName = null,
        public bool $isQuotaOverride = false,
    ) {}

    public static function quotaOverride(): self
    {
        return new self(
            sourcePath: '',
            level: self::LEVEL_QUOTA_OVERRIDE,
            ownerType: null,
            ownerName: null,
            isQuotaOverride: true,
        );
    }
}
