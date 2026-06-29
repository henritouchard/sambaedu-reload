<?php

namespace App\Providers;

use App\Events\ControlHubContractChanged;
use App\Listeners\NotifyQuotaOverageOnLogin;
use App\Listeners\ProvisionOrderedApplications;
use App\Listeners\ReconcileImposedWorkstationGroups;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // Story 5.1c (D5=A) — Toast warning si user en dépassement quota
        // (lecture `users.quota_snapshot`). Émis 1×/session naturellement
        // (Login event = vrai login effectif, pas re-session cookie).
        Login::class => [
            NotifyQuotaOverageOnLogin::class,
        ],

        // Story 30.3 — Garantie d'existence des groupes imposés par le contrat
        // amont (controlHub). 1er consommateur de cet événement (inerte depuis
        // 28.2) : à chaque mutation du contrat, la réconciliation crée/confirme
        // les WorkstationGroup imposés et lève le verrou des groupes non-imposés.
        // shouldDiscoverEvents() === false → enregistrement explicite obligatoire.
        // Story 31.3 — 2e consommateur : approvisionne en inventaire les applications
        // ORDONNÉES par le contrat amont (matérialisation depuis la source de dépôt,
        // status Available, sans install serveur) → comble le gap D4 de 31.2.
        ControlHubContractChanged::class => [
            ReconcileImposedWorkstationGroups::class,
            ProvisionOrderedApplications::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
