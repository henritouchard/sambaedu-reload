<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\UserSessionsService;
use App\Services\Wallpaper\CacheWallpaperContextRepository;
use App\Services\Wallpaper\Contracts\WallpaperContextRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider pour le module Wallpaper (Story 4.7).
 *
 * Bind le contrat `WallpaperContextRepository` vers l'implémentation Cache
 * (Story 16.15 — migration cache APCu vers `CacheWallpaperContextRepository`).
 *
 * Post-review #A : binde aussi `UserSessionsService` en singleton (utilisé
 * par le Composer pour la détection multi-session).
 */
class WallpaperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            WallpaperContextRepository::class,
            CacheWallpaperContextRepository::class,
        );

        $this->app->singleton(UserSessionsService::class);
    }
}
