<?php

declare(strict_types=1);

namespace App\Providers;

use App\Wpkg\Deployment\Events\AppProfileApplicationChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationGroupChanged;
use App\Wpkg\Deployment\Events\WorkstationActivated;
use App\Wpkg\Deployment\Events\WorkstationArchived;
use App\Wpkg\Deployment\Events\WorkstationGroupMembershipChanged;
use App\Wpkg\Deployment\Events\WorkstationOptionsChanged;
use App\Wpkg\Deployment\Listeners\InvalidateWorkstationPackagesCache;
use App\Wpkg\Deployment\Listeners\RegenerateWorkstationIniOnOptionsChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Service Provider du pipeline déploiement WPKG (Story 15.1).
 *
 * Au boot : tente de créer les 4 chemins partage WPKG (deploy / ini / inbox /
 * archive) s'ils n'existent pas, puis vérifie qu'ils sont accessibles en
 * lecture/écriture par le user PHP-FPM. Toute violation (création échouée
 * ou permission insuffisante) émet un warning explicite sur le channel
 * `wpkg-deploy`.
 *
 * La création évite que les admins SER aient à provisionner manuellement
 * l'arborescence (parité legacy : les anciens scripts shell le faisaient).
 *
 * Le check est non-bloquant : le boot Laravel doit toujours réussir, même
 * sans les partages Samba. Le but est de fournir un signal clair en logs.
 */
class WpkgDeploymentServiceProvider extends ServiceProvider
{
    /** @var list<string> Clés config dont on vérifie l'accessibilité au boot. */
    private const PATH_KEYS = [
        'sambaedu.wpkg.deploy_path',
        'sambaedu.wpkg.ini_path',
        'sambaedu.wpkg.reports_inbox',
        'sambaedu.wpkg.reports_archive',
    ];

    /** Mode des dossiers créés (rwxr-xr-x — owner PHP-FPM, group + others read+exec). */
    private const DIR_MODE = 0755;

    public function boot(): void
    {
        // Story 15.2 — Listeners du pipeline (cache invalidation + regen .ini).
        // Enregistrés ici plutôt que dans EventServiceProvider pour garder la
        // cohésion namespace `App\Wpkg\Deployment`. Actifs en testing aussi
        // pour permettre le test feature dispatch sans fake.
        $this->registerWpkgListeners();

        // Skip en environnement de test : les chemins legacy n'existent pas
        // sur les CI / runners locaux et le warning est bruyant sans valeur.
        if ($this->app->environment('testing')) {
            return;
        }

        // Ordre critique : on prépare le dossier de logs `wpkg-deploy` AVANT
        // d'écrire dans le channel — sinon Monolog plante au premier write
        // (RotatingFileHandler ne crée pas le dossier parent).
        $this->ensureLogChannelDirectory();

        foreach ($this->ensurePaths(self::PATH_KEYS) as $violation) {
            Log::channel('wpkg-deploy')->warning(
                '[wpkg-deploy] chemin partage inaccessible',
                $violation,
            );
        }
    }

    /**
     * Crée le dossier `storage/logs/wpkg-deploy/` si absent. `storage/logs/`
     * est git-ignored donc le dossier n'est pas propagé par `git clone` /
     * déploiement — il faut le provisionner au boot.
     *
     * Failover : on logge sur le channel par défaut (pas sur `wpkg-deploy`,
     * sinon chicken-and-egg).
     */
    private function ensureLogChannelDirectory(): void
    {
        $logDir = storage_path('logs/wpkg-deploy');
        if (is_dir($logDir)) {
            return;
        }
        if (! @mkdir($logDir, self::DIR_MODE, true) && ! is_dir($logDir)) {
            Log::warning('[wpkg-deploy] impossible de créer le dossier de logs', [
                'path' => $logDir,
                'php_user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? null)
                    : null,
            ]);
        }
    }
    
    /**
     * Story 15.2 / AC4.5 — Routing des events WPKG vers leurs listeners.
     */
    private function registerWpkgListeners(): void
    {
        $cacheInvalidator = [InvalidateWorkstationPackagesCache::class, 'handle'];

        Event::listen(AppProfileWorkstationGroupChanged::class, $cacheInvalidator);
        Event::listen(AppProfileWorkstationChanged::class, $cacheInvalidator);
        Event::listen(AppProfileApplicationChanged::class, $cacheInvalidator);
        Event::listen(WorkstationGroupMembershipChanged::class, $cacheInvalidator);
        Event::listen(WorkstationActivated::class, $cacheInvalidator);
        Event::listen(WorkstationArchived::class, $cacheInvalidator);

        Event::listen(
            WorkstationOptionsChanged::class,
            [RegenerateWorkstationIniOnOptionsChanged::class, 'handle'],
        );
    }

    /**
     * Pour chaque clé config : tente `mkdir -p` si absent, puis vérifie R/W.
     * Retourne la liste des chemins toujours en violation après tentative
     * (création impossible ou permissions insuffisantes).
     *
     * @param  list<string>  $keys
     * @return list<array<string,mixed>>
     */
    public function ensurePaths(array $keys): array
    {
        $violations = [];

        foreach ($keys as $key) {
            $path = (string) config($key, '');
            if ($path === '') {
                continue;
            }

            $createAttempted = false;
            $createSucceeded = null;
            if (! is_dir($path)) {
                $createAttempted = true;
                // @ : on absorbe les warnings PHP, le résultat false suffit
                // pour reporter la violation. Le warning utile est tracé
                // par notre code, pas par PHP.
                $createSucceeded = @mkdir($path, self::DIR_MODE, true) || is_dir($path);
            }

            $exists = is_dir($path);
            $isReadable = $exists && is_readable($path);
            $isWritable = $exists && is_writable($path);

            if ($isReadable && $isWritable) {
                continue;
            }

            $violations[] = [
                'config_key' => $key,
                'path' => $path,
                'exists' => $exists,
                'readable' => $isReadable,
                'writable' => $isWritable,
                'create_attempted' => $createAttempted,
                'create_succeeded' => $createSucceeded,
                'php_user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? null)
                    : null,
            ];
        }

        return $violations;
    }
}
