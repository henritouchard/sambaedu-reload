<?php

declare(strict_types=1);

namespace App\Services\Wallpaper;

use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ramasse-miettes d'assets de bibliothèque.
 *
 * Supprime un asset (ligne + fichier) UNIQUEMENT s'il n'est plus référencé
 * par aucune assignation. La vérification refcount + suppression de ligne se
 * font dans une transaction avec `lockForUpdate` sur l'asset, pour fermer la
 * fenêtre TOCTOU entre le check et la suppression (review F1). L'`unlink` du
 * fichier est fait APRÈS le commit (jamais dans la transaction) pour ne pas
 * perdre le fichier si la transaction venait à être annulée.
 */
class WallpaperAssetCollector
{
    public function collectIfOrphan(?int $assetId): void
    {
        if ($assetId === null) {
            return;
        }

        $pathToUnlink = DB::transaction(function () use ($assetId): ?string {
            $asset = WallpaperAsset::query()->whereKey($assetId)->lockForUpdate()->first();
            if ($asset === null) {
                return null;
            }
            // Toujours référencé → on ne touche à rien.
            if (Wallpaper::query()->where('asset_id', $assetId)->exists()) {
                return null;
            }
            $path = $asset->absolutePath;
            $asset->delete();

            return $path !== '' ? $path : null;
        });

        if ($pathToUnlink !== null && is_file($pathToUnlink) && ! @unlink($pathToUnlink)) {
            Log::warning('[WallpaperAssetCollector] unlink fichier asset échoué (orphan)', [
                'asset_id' => $assetId,
                'path' => $pathToUnlink,
            ]);
        }
    }
}
