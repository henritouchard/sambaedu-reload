<?php

namespace App\Providers;

use App\Services\FileManagerService;
use App\Services\SE4FSService;
use App\Services\ShortcutsService;
use App\Services\UserService;
use App\Services\StatsService;
use App\Services\CacheService;
use App\Services\UtilityService;
use App\Services\AuthenticationService;
use App\Services\AdDataTransformer;
use App\Services\ImageManagerService;
use App\Services\UserGroupService;
use App\Config\SambaEduConfig;
use App\Config\LdapDnHelper;
use App\Config\LegacyConfigBridge;
use App\Models\AppProfile;
use App\Models\Shortcut;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\AppProfileObserver;
use App\Observers\ShortcutObserver;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Repositories\EstablishmentRepository;
use App\Repositories\GroupRepository;
use App\Repositories\UserGroupRepository;
use App\Services\AdSync\AdSyncService;
use App\Services\AdSync\UserGroupAdSyncService;
use App\Services\Legacy\LegacyParcBridgeService;
use App\Services\ShortcutCompilerService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Queue;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobFailed;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     * 
     * Permet à laravel de résoudre les services automatiquement pour créer des instances singleton de ces services.
     * Utilisation: 
     * $service = app(Service::class);
     * ou
     * $service = app('sambaedu.service');
     * ou
     * $service = app('sambaedu.service', ['param1' => 'value1', 'param2' => 'value2']);
     * 
     * Il s'agit d'instances dont l'état interne ne change pas suivant les utilisateurs qui les utilisent.
     * Exemple: Pour faire un appel à LDAP, j'utilise toujours les mêmes identifiants et mêmes paramètres 
     * donc pas besoin de réintancier la classe et de créer une nouvelle connexion.
     */
    public function register(): void
    {
        // Services utilitaires génériques
        $this->app->singleton(FileManagerService::class);
        $this->app->singleton(ImageManagerService::class);

        // Services métier et configuration
        $this->app->singleton(AdDataTransformer::class);
        $this->app->singleton(CacheService::class);
        $this->app->singleton(SambaEduConfig::class);
        $this->app->singleton(LdapDnHelper::class);
        $this->app->singleton(LegacyConfigBridge::class);
        $this->app->singleton(EstablishmentRepository::class);
        $this->app->singleton(GroupRepository::class);
        $this->app->singleton(SE4FSService::class);
        $this->app->singleton(ShortcutsService::class);
        $this->app->singleton(ShortcutCompilerService::class);
        $this->app->singleton(StatsService::class);
        $this->app->singleton(UserService::class);
        $this->app->singleton(UserGroupRepository::class);
        $this->app->singleton(UserGroupService::class);
        $this->app->singleton(UtilityService::class);

        // Services avec dépendances (auto-résolution Laravel)
        $this->app->singleton(AuthenticationService::class);

        // Services de synchronisation AD
        $this->app->singleton(AdSyncService::class);
        $this->app->singleton(UserGroupAdSyncService::class);
        $this->app->singleton(\App\Services\AdSync\AdSyncChecker::class);
        $this->app->singleton(\App\Services\AdSync\AppProfileAdSyncService::class);

        // Service de pont legacy pour les parcs
        $this->app->singleton(LegacyParcBridgeService::class);

        // Alias pour faciliter l'utilisation via app('sambaedu.config') qui fournit l'instance singleton de SambaeduConfig
        $this->app->alias(AdDataTransformer::class, 'sambaedu.transformer');
        $this->app->alias(AuthenticationService::class, 'sambaedu.auth');
        $this->app->alias(CacheService::class, 'sambaedu.cache');
        $this->app->alias(SambaEduConfig::class, 'sambaedu.config');
        $this->app->alias(UtilityService::class, 'sambaedu.utility');

        $this->fixHadPermitSignatureForLivewireFileUpload();
    }


    /**
     * Résout le problème d'upload de fichier Livewire en production avec reverse proxy
     * 
     * PROBLÈME:
     * - Laravel génère une URL signée: https://moncollege.fr/identifiantcollege/livewire/upload-file?expires=XXX&signature=YYY
     * - La signature est calculée avec le chemin complet incluant /identifiantcollege/
     * - Lors de la validation, Laravel utilise $request->url() qui reconstruit l'URL à partir des headers HTTP
     * - Avec le reverse proxy, $request->url() retourne: https://moncollege95.fr/livewire/upload-file (sans /identifiantcollege/)
     * - Les signatures ne correspondent pas → 401 Unauthorized
     * 
     * SOLUTION:
     * - Surcharge la méthode hasValidSignature() de Laravel via des macros
     * - Utilise url($request->path()) au lieu de $request->url() pour reconstruire l'URL
     * - url() helper utilise APP_URL configuré dans .env, garantissant le chemin complet /0950000x/
     * - Les signatures correspondent maintenant → Upload fonctionne
     * 
     * TODO: Cette solution globale surcharge une méthode de sécurité critique de Laravel.
     * Idéalement, quand Laravel sera à la racine du serveur web, utiliser la solution officielle:
     * https://github.com/livewire/livewire/discussions/3084#discussioncomment-892295
     */
    public function fixHadPermitSignatureForLivewireFileUpload(): void
    {
        // Macro 1: Validation de la signature en reconstruisant l'URL avec APP_URL
        URL::macro('alternateHasCorrectSignature', function (Request $request, $absolute = true, array $ignoreQuery = []) {
            // Exclure le paramètre 'signature' de la validation
            $ignoreQuery[] = 'signature';

            // CLEF DU FIX: Utiliser url() au lieu de $request->url()
            // url($request->path()) reconstruit l'URL en utilisant APP_URL configuré
            // Exemple: url('livewire/upload-file') → https://moncollege.fr/identifiantcollege/livewire/upload-file
            $absoluteUrl = url($request->path());
            $url = $absolute ? $absoluteUrl : '/' . $request->path();

            // Récupérer tous les paramètres de query string sauf ceux ignorés
            $queryString = collect(explode('&', (string) $request->server->get('QUERY_STRING')))
                ->reject(fn($parameter) => in_array(Str::before($parameter, '='), $ignoreQuery))
                ->join('&');

            // Reconstruire l'URL complète pour le calcul de signature
            $original = rtrim($url . '?' . $queryString, '?');

            // Récupérer la clé d'application pour HMAC
            $key = config('app.key');

            if (empty($key)) {
                throw new \RuntimeException('Application key is not set.');
            }

            // Calculer la signature HMAC et comparer avec celle fournie
            $signature = hash_hmac('sha256', $original, $key);
            return hash_equals($signature, (string) $request->query('signature', ''));
        });

        // Macro 2: Validation complète (signature + expiration)
        URL::macro('alternateHasValidSignature', function (Request $request, $absolute = true, array $ignoreQuery = []) {
            return URL::alternateHasCorrectSignature($request, $absolute, $ignoreQuery)
                && URL::signatureHasNotExpired($request);
        });

        // Macro 3: Surcharge globale de Request::hasValidSignature()
        // ATTENTION: Toutes les validations de signature dans l'application utilisent maintenant cette méthode
        Request::macro('hasValidSignature', function ($absolute = true, array $ignoreQuery = []) {
            return URL::alternateHasValidSignature($this, $absolute, $ignoreQuery);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enregistrer les observers pour la synchronisation AD
        WorkstationGroup::observe(WorkstationGroupObserver::class);
        UserGroup::observe(UserGroupObserver::class);
        AppProfile::observe(AppProfileObserver::class);
        Shortcut::observe(ShortcutObserver::class);

        // Forcer l'URL root et HTTPS pour le reverse proxy
        if ($appUrl = config('app.url')) {
            $parsedUrl = parse_url($appUrl);
            if (isset($parsedUrl['path']) && $parsedUrl['path'] !== '/') {
                URL::forceRootUrl($appUrl);
            }
            // Forcer HTTPS si APP_URL est en https
            if (isset($parsedUrl['scheme']) && $parsedUrl['scheme'] === 'https') {
                URL::forceScheme('https');
            }
        }

        \Livewire\Livewire::setUpdateRoute(function ($handle) {
            // Route principale : utilisée pour générer l'URL frontend (via forceRootUrl)
            $route = \Illuminate\Support\Facades\Route::post('/livewire/update', $handle)
                ->middleware(['web', 'sambaedu.auth']);

            // Route alias : quand le reverse proxy ne strip pas le préfixe APP_URL
            // Ex: APP_URL=https://host/laravel → proxy envoie /laravel/livewire/update au backend
            $urlPath = parse_url(config('app.url'), PHP_URL_PATH) ?? '';
            $prefix = trim($urlPath, '/');
            if ($prefix) {
                \Illuminate\Support\Facades\Route::post("/{$prefix}/livewire/update", $handle)
                    ->middleware(['web', 'sambaedu.auth']);
            }

            return $route;
        }
    }

    private function registerQueueTaskTracking(): void
    {
        Queue::before(function (JobProcessing $event): void {
            if (!Schema::hasTable('queue_task_runs')) {
                return;
            }

            $payload = $event->job->payload();
            $taskUuid = (string) ($payload['uuid'] ?? sha1($event->job->getRawBody()));
            $jobName = (string) ($payload['displayName'] ?? $payload['job'] ?? 'UnknownJob');

            DB::table('queue_task_runs')->updateOrInsert(
                ['task_uuid' => $taskUuid],
                [
                    'queue' => (string) $event->job->getQueue(),
                    'job_name' => $jobName,
                    'status' => 'running',
                    'started_at' => now(),
                    'finished_at' => null,
                    'failed_at' => null,
                    'error_message' => null,
                    'log_lines' => "[" . now()->toDateTimeString() . "] START {$jobName}",
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        });

        Queue::after(function (JobProcessed $event): void {
            if (!Schema::hasTable('queue_task_runs')) {
                return;
            }

            $payload = $event->job->payload();
            $taskUuid = (string) ($payload['uuid'] ?? sha1($event->job->getRawBody()));
            $jobName = (string) ($payload['displayName'] ?? $payload['job'] ?? 'UnknownJob');

            $existingLogs = (string) (DB::table('queue_task_runs')->where('task_uuid', $taskUuid)->value('log_lines') ?? '');
            $appendedLogs = trim($existingLogs . "\n[" . now()->toDateTimeString() . "] DONE {$jobName}");

            DB::table('queue_task_runs')->updateOrInsert(
                ['task_uuid' => $taskUuid],
                [
                    'queue' => (string) $event->job->getQueue(),
                    'job_name' => $jobName,
                    'status' => 'done',
                    'finished_at' => now(),
                    'updated_at' => now(),
                    'log_lines' => $appendedLogs,
                ],
            );
        });

        Queue::failing(function (JobFailed $event): void {
            if (!Schema::hasTable('queue_task_runs')) {
                return;
            }

            $payload = $event->job->payload();
            $taskUuid = (string) ($payload['uuid'] ?? sha1($event->job->getRawBody()));
            $jobName = (string) ($payload['displayName'] ?? $payload['job'] ?? 'UnknownJob');
            $message = $event->exception->getMessage();

            $existingLogs = (string) (DB::table('queue_task_runs')->where('task_uuid', $taskUuid)->value('log_lines') ?? '');
            $appendedLogs = trim($existingLogs . "\n[" . now()->toDateTimeString() . "] FAILED {$jobName}: {$message}");

            DB::table('queue_task_runs')->updateOrInsert(
                ['task_uuid' => $taskUuid],
                [
                    'queue' => (string) $event->job->getQueue(),
                    'job_name' => $jobName,
                    'status' => 'failed',
                    'failed_at' => now(),
                    'error_message' => $message,
                    'updated_at' => now(),
                    'log_lines' => $appendedLogs,
                ],
            );
        });
    }

    /**
     * TODO: supprimer le discovery (endpoint obsolète)
     * Configure les rate limits pour les endpoints SE4FS
     */
    private function configureRateLimits(): void
    {
        // Rate limit pour discovery: 30 req/min par IP
        \Illuminate\Support\Facades\RateLimiter::for('discovery', function ($request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by($request->ip());
        });

        // Rate limit pour APIs authentifiées: 100 req/min par token
        \Illuminate\Support\Facades\RateLimiter::for('se4fs-api', function ($request) {
            $token = $request->attributes->get('se4fs_token', $request->ip());
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(100)->by($token);
        });
    }
}
