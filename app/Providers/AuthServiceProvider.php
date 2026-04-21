<?php

namespace App\Providers;

use App\Policies\AppCustomizationPolicy;
use App\Policies\GroupPolicy;
use App\Policies\UserPolicy;
use App\Policies\WorkstationGroupPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Providers\LdapUserProvider;
use App\Policies\ShortcutPolicy;
use App\Services\AuthenticationService;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // Les raccourcis n'ont pas de modèle Eloquent, on utilise Gate::define
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Enregistrer le provider LDAP SambaEdu
        Auth::provider('sambaedu', function ($app, array $config) {
            return new LdapUserProvider(
                $app->make(AuthenticationService::class)
            );
        });

        // Enregistrer les gates pour les raccourcis
        ShortcutPolicy::registerGates();
        UserPolicy::registerGates();
        GroupPolicy::registerGates();
        WorkstationGroupPolicy::registerGates();
        // Story 4.8 — personnalisation applicative
        AppCustomizationPolicy::registerGates();

    }
}
