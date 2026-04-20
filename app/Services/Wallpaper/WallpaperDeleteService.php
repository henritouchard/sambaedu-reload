<?php

declare(strict_types=1);

namespace App\Services\Wallpaper;

use App\Models\Wallpaper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service de suppression d'un wallpaper (DB + filesystem).
 *
 * Story 4.7 — AC 8.
 */
class WallpaperDeleteService
{
    public function delete(Wallpaper $wallpaper): void
    {
        $path = (string) $wallpaper->path;
        $id = $wallpaper->id;

        DB::transaction(function () use ($wallpaper): void {
            $wallpaper->delete();
        });

        if ($path !== '' && is_file($path)) {
            if (! @unlink($path)) {
                Log::warning('[WallpaperDelete] unlink failed (orphan fichier)', [
                    'wallpaper_id' => $id,
                    'path' => $path,
                ]);
            }
        }
    }
}
