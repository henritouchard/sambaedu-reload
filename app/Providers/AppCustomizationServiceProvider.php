<?php

declare(strict_types=1);

namespace App\Providers;

use App\Policies\AppCustomizationPolicy;
use App\Services\AppCustomization\ApcuAppContextRepository;
use App\Services\AppCustomization\AppPolicyRegistry;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider pour le module AppCustomization (Story 4.8).
 *
 * - Bind le contrat `AppContextRepository` vers l'implémentation APCu.
 * - Bind `AppPolicyRegistry` en singleton (cache d'instances d'adapters).
 * - Enregistre les gates de `AppCustomizationPolicy` au boot.
 */
class AppCustomizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            AppContextRepository::class,
            ApcuAppContextRepository::class,
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
