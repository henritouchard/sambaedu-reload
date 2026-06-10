<?php

declare(strict_types=1);

namespace App\Services\Wallpaper;

use App\Models\Wallpaper;
use Illuminate\Support\Facades\DB;

/**
 * Suppression d'une assignation wallpaper.
 *
 * Refonte bibliothèque (2026-06) : supprime la ligne d'assignation, puis
 * délègue au {@see WallpaperAssetCollector} la collecte de l'asset
 * sous-jacent (transactionnelle, refcount) — un asset partagé entre plusieurs
 * parcs/users n'est pas supprimé tant qu'un lien subsiste.
 */
class WallpaperDeleteService
{
    public function __construct(
        private readonly WallpaperAssetCollector $collector = new WallpaperAssetCollector(),
    ) {}

    public function delete(Wallpaper $wallpaper): void
    {
        $assetId = $wallpaper->asset_id;

        DB::transaction(function () use ($wallpaper): void {
            $wallpaper->delete();
        });

        $this->collector->collectIfOrphan($assetId);
    }
}
