<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Miniatures wallpaper pour l'UI admin Livewire.
 *
 * Story 27.14 — les méthodes `legacyOut()` (ex-`gpo/wallpaper_out.php`) et
 * `apiV1()` (ex-`/api/v1/workstation-config/wallpaper`) ont été supprimées
 * avec le canal de config legacy. Le wallpaper du poste est désormais résolu
 * par le canal agent (`WallpaperStateProvider` → `WallpaperResolver`, qui
 * survit) ; l'info dynamique (badges/cartouches) passe par l'overlay. Ce
 * controller ne sert plus que les miniatures admin :
 * - `thumbnail(Wallpaper)` : miniature PNG d'un wallpaper en base (AC 8).
 * - `assetThumbnail(WallpaperAsset)` : miniature d'un asset de bibliothèque.
 */
class WallpaperController extends Controller
{
    /**
     * Miniature PNG d'un wallpaper en base (UI admin).
     */
    public function thumbnail(Wallpaper $wallpaper): Response
    {
        $path = $wallpaper->absolutePath;
        if ($path === '' || ! is_file($path)) {
            abort(404, 'Fichier wallpaper introuvable');
        }

        if (! class_exists('Imagick')) {
            return response()->file($path, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        try {
            $imagick = new \Imagick($path);
            $imagick->scaleImage(0, 160);
            $imagick->setImageFormat('png');
            $blob = (string) $imagick->getImageBlob();
            $imagick->destroy();

            $etag = '"' . sha1(($wallpaper->updated_at?->toDateTimeString() ?? '') . '|' . $wallpaper->id) . '"';

            return response($blob, 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=3600',
                'ETag' => $etag,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[WallpaperController] thumbnail failed', [
                'wallpaper_id' => $wallpaper->id,
                'error' => $e->getMessage(),
            ]);
            return response()->file($path, [
                'Content-Type' => 'image/jpeg',
            ]);
        }
    }

    /**
     * Miniature PNG d'un asset de bibliothèque (sélecteur UI).
     */
    public function assetThumbnail(WallpaperAsset $asset): Response
    {
        $path = $asset->absolutePath;
        if ($path === '' || ! is_file($path)) {
            abort(404, 'Fichier asset introuvable');
        }

        if (! class_exists('Imagick')) {
            return response()->file($path, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        try {
            $imagick = new \Imagick($path);
            $imagick->scaleImage(0, 160);
            $imagick->setImageFormat('png');
            $blob = (string) $imagick->getImageBlob();
            $imagick->destroy();

            return response($blob, 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=3600',
                'ETag' => '"asset-' . $asset->id . '-' . substr($asset->checksum, 0, 12) . '"',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[WallpaperController] asset thumbnail failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            return response()->file($path, [
                'Content-Type' => 'image/jpeg',
            ]);
        }
    }
}
