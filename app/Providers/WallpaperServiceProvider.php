<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\UserSessionsService;
use App\Services\Wallpaper\ApcuWallpaperContextRepository;
use App\Services\Wallpaper\Contracts\WallpaperContextRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider pour le module Wallpaper (Story 4.7).
 *
 * Bind le contrat `WallpaperContextRepository` vers l'implémentation APCu.
 * Le contrat permet de swap vers `CacheWallpaperContextRepository` quand
 * `applications.php` sera porté en Laravel (hors scope 4-7).
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
            ApcuWallpaperContextRepository::class,
        );

        $this->app->singleton(UserSessionsService::class);
    }
}
