<?php

declare(strict_types=1);

namespace App\Services\Wallpaper;

use App\Dto\Wallpaper\WallpaperContext;
use App\Services\Wallpaper\Contracts\WallpaperContextRepository;

/**
 * Implémentation APCu du contexte wallpaper.
 *
 * Lit `apcu_fetch("apps.$id")` — clé posée par le legacy
 * `applications.inc.php::get_apps()` (TTL 1800s).
 *
 * Story 4.7 — AC 3. Extension APCu requise (cf. mémoire `apcu_risk.md`).
 */
class ApcuWallpaperContextRepository implements WallpaperContextRepository
{
    public function findById(string $id): ?WallpaperContext
    {
        if ($id === '' || ! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return null;
        }

        if (! function_exists('apcu_fetch') || ! function_exists('apcu_enabled') || ! apcu_enabled()) {
            return null;
        }

        $success = false;
        $payload = apcu_fetch('apps.' . $id, $success);

        if (! $success || ! is_array($payload)) {
            return null;
        }

        return WallpaperContext::fromApcuArray($payload);
    }
}
