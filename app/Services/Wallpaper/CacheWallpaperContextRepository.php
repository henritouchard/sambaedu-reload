<?php

declare(strict_types=1);

namespace App\Services\Wallpaper;

use App\Dto\Wallpaper\WallpaperContext;
use App\Services\Wallpaper\Contracts\WallpaperContextRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Implémentation Cache (Laravel) du contexte wallpaper.
 *
 * Lit `Cache::store('app_context')->get("apps.$id")` — clé posée par
 * `CacheAppContextWriter` (Story 16.15). Le store `app_context` est
 * déclaré dans `config/cache.php` avec `prefix => ''` pour interop
 * avec le shim legacy `LegacyBootstrapTokenValidator`.
 *
 * Story 4.7 — AC 3 (origine). Story 16.15 — AC4 (migration Cache).
 */
class CacheWallpaperContextRepository implements WallpaperContextRepository
{
    public function findById(string $id): ?WallpaperContext
    {
        if ($id === '' || ! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return null;
        }

        $payload = Cache::store('app_context')->get('apps.' . $id);
        if (! is_array($payload)) {
            return null;
        }

        return WallpaperContext::fromApcuArray($payload);
    }
}
