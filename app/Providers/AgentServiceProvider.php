<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Services\Agent\Enrollment\EnrollmentCampaign;
use App\Services\Agent\Enrollment\EnrollmentMatchService;
use App\Services\Agent\Enrollment\EnrollmentService;
use App\Services\Agent\Enrollment\TokenRotationService;
use App\Services\Agent\Providers\DrivesStateProvider;
use App\Services\Agent\Providers\OverlayMachineStateProvider;
use App\Services\Agent\Providers\OverlayStateProvider;
use App\Services\Agent\Providers\PrintersStateProvider;
use App\Services\Agent\Providers\RegistryMachineStateProvider;
use App\Services\Agent\Providers\RegistryUserStateProvider;
use App\Services\Agent\Providers\ShortcutsStateProvider;
use App\Services\Agent\Providers\WallpaperStateProvider;
use App\Services\Agent\Releases\ReleaseCreationService;
use App\Services\Agent\Releases\ReleaseManifestService;
use App\Services\Agent\Reporting\ConformityService;
use App\Services\Agent\Reporting\ReportIngestService;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateHasher;
use App\Services\Agent\SyncRequestService;
use App\Services\Agent\WorkstationEnvironmentResolver;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Story 23.2 — Service Provider du canal agent desired-state (Epic 23).
 *
 * Frontière nette ancien/nouveau : ce provider est le foyer du canal NEUF
 * (bearer token custom, zéro AD), distinct d'`AuthV1ServiceProvider` (canal
 * JWT legacy-migration, intouché pendant la transition).
 *
 *  - Binding singleton `TokenRotationService` (stateless réutilisable).
 *  - Binding singleton `EnrollmentService` (Story 23.3 — enrôlement porte 1).
 *  - Binding singleton `ReportIngestService` (Story 24.1 — ingestion des
 *    rapports de conformité, POST /report).
 *  - Registry des StateProviders + binding singleton `StateCompiler`
 *    (Story 23.4) : ajouter un type de ressource = ajouter UNE ligne au
 *    tableau ci-dessous (Epic 27), zéro modification du compilateur.
 *  - Alias middleware `agent.token` (toujours, y compris tests — les Feature
 *    tests montent des routes éphémères derrière cet alias).
 *
 * Évolutions prévues : complétion `config/agent.php` (23.5).
 */
class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenRotationService::class, fn () => new TokenRotationService());
        // Story 25.3 — Porte 2 : rapprochement du faisceau + mode campagne
        // (anti-usurpation jamais débrayé) injectés dans EnrollmentService.
        $this->app->singleton(EnrollmentMatchService::class, fn () => new EnrollmentMatchService());
        $this->app->singleton(EnrollmentCampaign::class, fn () => new EnrollmentCampaign());
        $this->app->singleton(
            EnrollmentService::class,
            fn ($app) => new EnrollmentService(
                $app->make(TokenRotationService::class),
                $app->make(EnrollmentMatchService::class),
                $app->make(EnrollmentCampaign::class),
            ),
        );
        // Story 24.1 — ingestion des rapports de conformité (POST /report).
        $this->app->singleton(ReportIngestService::class, fn () => new ReportIngestService());
        // Story 24.7 — « forcer la synchro » (UI request / report fulfill) +
        // lecture agrégée de conformité pour les pages parc (stateless).
        $this->app->singleton(SyncRequestService::class, fn () => new SyncRequestService());
        $this->app->singleton(ConformityService::class, fn () => new ConformityService());
        // Story 25.1 — distribution des releases (D6) : création vérifiée
        // hash + manifest résolu par ring (stateless tous les deux).
        $this->app->singleton(ReleaseCreationService::class, fn () => new ReleaseCreationService());
        $this->app->singleton(ReleaseManifestService::class, fn () => new ReleaseManifestService());
        // Story 26.1 — résolution de la nature du poste (précédence
        // nomade>personal_local>shared_local, défaut shared_local, Postgres-only),
        // consommable par les StateProviders de l'Epic 27. Stateless.
        $this->app->singleton(WorkstationEnvironmentResolver::class, fn () => new WorkstationEnvironmentResolver());
        $this->app->singleton(StateHasher::class, fn () => new StateHasher());
        $this->app->singleton(StateCompiler::class, fn ($app) => new StateCompiler(
            $app->make(StateHasher::class),
            [
                $app->make(WallpaperStateProvider::class),
                $app->make(OverlayStateProvider::class),
                // Story 27.10 — volet MACHINE de l'overlay : la salle
                // (`{kind:"machine", room}`) passe en portée machine (cache
                // persistant) pour précharger poste+salle au logon sans attendre
                // le fetch per-user. `room` retiré de l'item identity session.
                $app->make(OverlayMachineStateProvider::class),
                // Story 27.1 — type `shortcuts` (aggregate / machine_user) :
                // union des raccourcis des mailles, chemin du bureau résolu
                // serveur (fix Bug C). Une ligne, zéro modif du compilateur.
                $app->make(ShortcutsStateProvider::class),
                // Story 27.2 — types `printers` (aggregate / session) : union
                // des imprimantes des mailles POSTE, défaut exclusif réglé par
                // WG (physique > logique) ; et `drives` (aggregate / session) :
                // projection des partages de classe en montages réseau (MVP-A,
                // pas de table). Deux lignes, zéro modif du compilateur.
                $app->make(PrintersStateProvider::class),
                $app->make(DrivesStateProvider::class),
                // Story 27.3 — type `registry` (exclusive PAR IDENTITÉ DE CLÉ) :
                // catalogue de réglages registre activables par parc, compilés
                // en items concrets {hive,path,name,type,value}. DEUX providers,
                // UN handler Go (D-Q2) : HKLM → portée machine (service SYSTEM),
                // HKCU → portée session (compagnon). Une table catalogue,
                // chaque provider filtre par hive. Zéro modif du routage
                // compilateur (le scope() de chaque provider suffit).
                $app->make(RegistryMachineStateProvider::class),
                $app->make(RegistryUserStateProvider::class),
            ],
        ));
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('agent.token', AuthenticateAgentToken::class);
    }
}
