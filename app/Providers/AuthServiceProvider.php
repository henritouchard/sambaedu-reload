<?php

namespace App\Providers;

use App\Enums\SambaPermission;
use App\Models\Delegation;
use App\Models\User;
use App\Models\Workstation;
use App\Policies\AppCustomizationPolicy;
use App\Policies\CapabilityPolicy;
use App\Policies\DelegationPolicy;
use App\Policies\DhcpPolicy;
use App\Policies\FolderAccessRulePolicy;
use App\Policies\GroupPolicy;
use App\Policies\MachinePolicy;
use App\Policies\NetworkSharePolicy;
use App\Policies\PrinterPolicy;
use App\Policies\SharePolicy;
use App\Policies\UserPolicy;
use App\Policies\WorkstationGroupPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
        // ------------------------------------------------------------------
        // Compte d'administration protégé : couverture automatique des droits.
        //
        // Le compte `User::PROTECTED_ADMIN_LOGIN` détient TOUS les droits
        // déclarés dans le code. La décision ne consulte pas les lignes en base :
        // un droit ajouté à `SambaPermission` lui est acquis immédiatement.
        //
        // ⚠️ Le bypass est volontairement RESTREINT aux abilities qui sont des
        // droits (cases de `SambaPermission`). Les gates qui encodent une RÈGLE
        // MÉTIER restent soumises au pipeline d'autorisation normal — au premier
        // chef `modify-capability` (`CapabilityPolicy`), qui porte le verrou
        // amont du contrat managé : l'autorité amont prime sur tout acteur
        // local, compte protégé compris.
        Gate::before(function ($user, string $ability) {
            if (! $user instanceof User || ! $user->isProtectedAdmin()) {
                return null; // laisse le pipeline d'autorisation habituel décider
            }

            return SambaPermission::tryFrom($ability) !== null ? true : null;
        });

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
        // Story 29.2 — verrou amont sur l'édition d'une capacité (gate
        // `modify-capability`, DI de UpstreamLockResolver via le container).
        CapabilityPolicy::registerGates();

        // Story 7.2 — 5 nouvelles Policies (AC5).
        DelegationPolicy::registerGates();
        MachinePolicy::registerGates();
        PrinterPolicy::registerGates();
        SharePolicy::registerGates();
        DhcpPolicy::registerGates();
        // Story 34.2 — lecteurs réseau gérés (permissions dédiées networkshare.*).
        NetworkSharePolicy::registerGates();
        // Story 36.4 — règles d'accès aux dossiers (permissions dédiées folderrule.*).
        FolderAccessRulePolicy::registerGates();
    }
}
