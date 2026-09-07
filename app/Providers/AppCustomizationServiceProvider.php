<?php

declare(strict_types=1);

namespace App\Providers;

use App\Policies\AppCustomizationPolicy;
use App\Services\AppCustomization\CacheAppContextRepository;
use App\Services\AppCustomization\CacheAppContextWriter;
use App\Services\AppCustomization\AppPolicyRegistry;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use App\Services\AppCustomization\Contracts\AppContextWriter;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider pour le module AppCustomization.
 *
 * - Bind le contrat `AppContextRepository` vers l'implémentation Cache.
 * - Bind `AppPolicyRegistry` en singleton (cache d'instances d'adapters).
 * - Enregistre les gates de `AppCustomizationPolicy` au boot.
 */
class AppCustomizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            AppContextRepository::class,
            CacheAppContextRepository::class,
        );

        // Pendant l'écriture du repository (qui, lui, ne fait que lire).
        // Migration vers CacheAppContextWriter.
        $this->app->bind(
            AppContextWriter::class,
            CacheAppContextWriter::class,
        );

        $this->app->singleton(AppPolicyRegistry::class, function ($app) {
            return new AppPolicyRegistry($app);
        });
    }

    public function boot(): void
    {
        AppCustomizationPolicy::registerGates();
    }
}
