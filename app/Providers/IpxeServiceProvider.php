<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ipxe\Contracts\IpxeAuthorizes;
use App\Ipxe\Iso\Services\WindowsIsoDownloadOrchestrator;
use App\Ipxe\Iso\Services\WindowsIsoSourcesReader;
use App\Ipxe\Iso\Services\WindowsIsoUrlValidator;
use App\Ipxe\Services\IpxeActionResolver;
use App\Ipxe\Services\IpxeAuthService;
use App\Ipxe\Services\IpxeEnrollmentMenuBuilder;
use App\Ipxe\Services\IpxeEnrollmentOrchestrator;
use App\Ipxe\Services\IpxeHostnameSanitizer;
use App\Ipxe\Services\IpxeMenuRenderer;
use App\Ipxe\Services\IpxeService;
use App\Ipxe\Services\LinuxInstallMenuBuilder;
use App\Ipxe\Services\LinuxPostInstallTracker;
use App\Ipxe\Services\LinuxPreseedService;
use App\Ipxe\Services\WindowsActionCmdBuilder;
use App\Ipxe\Services\WindowsInstallBatBuilder;
use App\Ipxe\Services\WindowsInstallMenuBuilder;
use App\Ipxe\Services\WindowsPostInstallTracker;
use App\Ipxe\Services\WindowsUnattendBuilder;
use App\Ipxe\Services\WorkstationEnrollmentService;
use App\Ipxe\Services\WorkstationLocator;
use App\Ldap\AdMachineManager;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Story 3.1 — D1 — IpxeServiceProvider.
 *
 * Service Provider du module iPXE (boot réseau + déploiement OS — Epic 3).
 *
 * **Décision DO-1** : le provider est placé dans `App\Providers\` (et non
 * `App\Ipxe\`) pour cohérence stricte avec les autres modules namespacés
 * du projet : `AuthV1ServiceProvider`, `GpoServiceProvider`,
 * `WpkgDeploymentServiceProvider`. Cela facilite la lecture de
 * `config/app.php` qui liste les providers d'application au même endroit.
 *
 * **Au boot** :
 *
 *  1. Binde les 3 services principaux en singletons (stateless réutilisables) :
 *      - `WorkstationLocator` — résolution PostgreSQL MAC/UUID → Workstation.
 *      - `IpxeMenuRenderer` — rendu des 3 templates Blade.
 *      - `IpxeService` — orchestrateur de l'endpoint `/ipxe/boot`.
 *  2. Merge config — pas de mergeConfigFrom (le fichier `config/ipxe.php` est
 *     directement chargé par Laravel via le mécanisme standard).
 *  3. **Crée le dossier `storage/logs/ipxe/`** si absent (parité
 *     `GpoServiceProvider`/`AuthV1ServiceProvider`) — sinon Monolog plante au
 *     premier write sur le channel `ipxe` (RotatingFileHandler ne crée pas
 *     le dossier parent).
 *
 * Skip création dossier en environnement `testing` (pattern iso
 * `GpoServiceProvider` — pas de warning bruyant sur runners CI).
 */
class IpxeServiceProvider extends ServiceProvider
{
    /** Mode des dossiers de logs créés (rwxr-xr-x). */
    private const DIR_MODE_LOGS = 0755;

    public function register(): void
    {
        $this->mergeConfigFrom(
            base_path('config/ipxe.php'),
            'ipxe',
        );

        // Services stateless réutilisables (parité 16.10/16.12).
        $this->app->singleton(WorkstationLocator::class, fn () => new WorkstationLocator());

        $this->app->singleton(IpxeMenuRenderer::class, fn ($app) => new IpxeMenuRenderer(
            $app->make(ViewFactory::class),
            $app->make(LinuxInstallMenuBuilder::class),
            $app->make(WindowsInstallMenuBuilder::class),
        ));

        // Story 3.2 — D9 / AC9.2 — résolveur d'actions whitelistées rendant
        // les templates `ipxe.actions.*`.
        $this->app->singleton(IpxeActionResolver::class, fn ($app) => new IpxeActionResolver(
            $app->make(ViewFactory::class),
            $app->make(\App\Services\ServiceCredentials::class),
        ));

        // Story 4.10 — IpxeAuthService centralise l'auth iPXE
        // (AD bind + permission Spatie `computer.install`).
        $this->app->singleton(IpxeAuthService::class, fn ($app) => new IpxeAuthService(
            $app->make(\App\Services\AuthenticationService::class),
        ));

        // Story 4.10 (correctif review #12) — binding contrat → impl concrète.
        // Tous les consommateurs iPXE type-hintent désormais `IpxeAuthorizes`
        // (pas `IpxeAuthService`), ce qui permet de stubber l'auth en test
        // sans toucher au `final` de la classe concrète de prod.
        $this->app->singleton(IpxeAuthorizes::class, fn ($app) => $app->make(IpxeAuthService::class));

        $this->app->singleton(IpxeService::class, fn ($app) => new IpxeService(
            $app->make(WorkstationLocator::class),
            $app->make(IpxeMenuRenderer::class),
            $app->make(IpxeActionResolver::class),
            $app->make(IpxeAuthorizes::class),
        ));

        // Story 3.3 — D5/D6 — bindings enrollment.
        $this->app->singleton(IpxeHostnameSanitizer::class, fn () => new IpxeHostnameSanitizer());

        $this->app->singleton(IpxeEnrollmentMenuBuilder::class, fn () => new IpxeEnrollmentMenuBuilder());

        $this->app->singleton(WorkstationEnrollmentService::class, fn ($app) => new WorkstationEnrollmentService(
            $app->make(AdMachineManager::class),
            $app->make(IpxeHostnameSanitizer::class),
        ));

        $this->app->singleton(IpxeEnrollmentOrchestrator::class, fn ($app) => new IpxeEnrollmentOrchestrator(
            $app->make(WorkstationLocator::class),
            $app->make(WorkstationEnrollmentService::class),
            $app->make(IpxeEnrollmentMenuBuilder::class),
            $app->make(IpxeMenuRenderer::class),
            $app->make(IpxeHostnameSanitizer::class),
            $app->make(IpxeAuthorizes::class),
        ));

        // Story 3.4 — D11 / AC9.3 — bindings installation Linux.
        $this->app->singleton(LinuxPreseedService::class, fn () => new LinuxPreseedService());
        $this->app->singleton(LinuxInstallMenuBuilder::class, fn () => new LinuxInstallMenuBuilder());
        $this->app->singleton(LinuxPostInstallTracker::class, fn () => new LinuxPostInstallTracker());

        // Story 3.5 — D11 / AC9.3 — bindings installation Windows.
        // se4install : mot de passe effectif (TOTP) via ServiceCredentials injecté.
        $this->app->singleton(WindowsUnattendBuilder::class, fn ($app) => new WindowsUnattendBuilder(
            $app->make(\App\Services\ServiceCredentials::class),
        ));
        $this->app->singleton(WindowsInstallBatBuilder::class, fn ($app) => new WindowsInstallBatBuilder(
            $app->make(\App\Services\ServiceCredentials::class),
        ));
        $this->app->singleton(WindowsInstallMenuBuilder::class, fn () => new WindowsInstallMenuBuilder());
        $this->app->singleton(WindowsPostInstallTracker::class, fn () => new WindowsPostInstallTracker());

        // Story 3.8 — D8 / AC3.6 / AC9.2 — orchestrateur 6 builders cmd batch
        // post-OOBE Windows (sysprep/nosysprep/join/renomme/post/wpkg).
        $this->app->singleton(WindowsActionCmdBuilder::class, fn ($app) => new WindowsActionCmdBuilder(
            $app->make(ViewFactory::class),
            $app->make(\App\Services\ServiceCredentials::class),
        ));

        // Story 3.6 — D7 / AC6.4 — bindings gestion ISO Windows (sous-namespace
        // dédié `App\Ipxe\Iso\*` pour cohérence frontière D1).
        $this->app->singleton(WindowsIsoUrlValidator::class, fn () => new WindowsIsoUrlValidator());
        $this->app->singleton(WindowsIsoSourcesReader::class, fn ($app) => new WindowsIsoSourcesReader(
            $app->make(Filesystem::class),
        ));
        $this->app->singleton(WindowsIsoDownloadOrchestrator::class, fn ($app) => new WindowsIsoDownloadOrchestrator(
            $app->make(WindowsIsoUrlValidator::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        $this->ensureLogChannelDirectory();
    }

    /**
     * Crée `storage/logs/ipxe/` si absent. `storage/logs/` est git-ignored
     * donc le dossier n'est pas propagé par un déploiement neuf.
     *
     * Failover : si la création échoue, on logue sur le channel par défaut
     * (pas sur `ipxe`, sinon chicken-and-egg).
     */
    private function ensureLogChannelDirectory(): void
    {
        $logDir = storage_path('logs/ipxe');
        if (is_dir($logDir)) {
            return;
        }
        if (! @mkdir($logDir, self::DIR_MODE_LOGS, true) && ! is_dir($logDir)) {
            Log::warning('[ipxe] impossible de créer le dossier de logs', [
                'path' => $logDir,
                'php_user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? null)
                    : null,
            ]);
        }
    }
}
