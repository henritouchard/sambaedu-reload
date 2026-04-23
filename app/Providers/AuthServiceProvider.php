<?php

namespace App\Providers;

use App\Models\Delegation;
use App\Models\Workstation;
use App\Policies\AppCustomizationPolicy;
use App\Policies\DelegationPolicy;
use App\Policies\DhcpPolicy;
use App\Policies\GroupPolicy;
use App\Policies\MachinePolicy;
use App\Policies\PrinterPolicy;
use App\Policies\SharePolicy;
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
     * Mappings modèle → Policy.
     *
     * Story 7.2 : `MachinePolicy` et `DelegationPolicy` sont adossées à des
     * modèles Eloquent — elles peuvent donc être invoquées via `@can('view',
     * $machine)` ou `Gate::authorize('delete', $delegation)` directement, sans
     * passer par un nom de gate explicite. Les autres Policies restent
     * enregistrées via `Gate::define` (pattern trait `RegistersGates`).
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Workstation::class => MachinePolicy::class,
        Delegation::class => DelegationPolicy::class,
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

        // Enregistrer les gates pour les Policies (trait RegistersGates).
        ShortcutPolicy::registerGates();
        UserPolicy::registerGates();
        GroupPolicy::registerGates();
        WorkstationGroupPolicy::registerGates();
        // Story 4.8 — personnalisation applicative
        AppCustomizationPolicy::registerGates();

        // Story 7.2 — 5 nouvelles Policies (AC5).
        DelegationPolicy::registerGates();
        MachinePolicy::registerGates();
        PrinterPolicy::registerGates();
        SharePolicy::registerGates();
        DhcpPolicy::registerGates();
    }
}
