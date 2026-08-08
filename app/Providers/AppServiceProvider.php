<?php

namespace App\Providers;

use App\Services\FileManagerService;
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
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\AppProfileObserver;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Observers\WorkstationObserver;
use App\Repositories\EstablishmentRepository;
use App\Repositories\GroupRepository;
use App\Repositories\UserGroupRepository;
use App\Services\ErrorLoggerService;
use App\Services\AdSync\AdSyncService;
use App\Services\AdSync\UserGroupAdSyncService;
use App\Services\Legacy\LegacyParcBridgeService;
use App\Services\Parc\MachinePowerService;
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
        $this->app->singleton(ShortcutsService::class);
        $this->app->singleton(StatsService::class);
        $this->app->singleton(UserService::class);
        $this->app->singleton(UserGroupRepository::class);
        $this->app->singleton(UserGroupService::class);
        $this->app->singleton(UtilityService::class);

        // Services avec dépendances (auto-résolution Laravel)
        $this->app->singleton(AuthenticationService::class);

        // Error logger (singleton pour le shim LDAP legacy)
        $this->app->singleton(ErrorLoggerService::class);

        // Services de synchronisation AD
        $this->app->singleton(AdSyncService::class);
        $this->app->singleton(UserGroupAdSyncService::class);
        $this->app->singleton(\App\Services\AdSync\AdSyncChecker::class);
        // AppProfileAdSyncService retiré en 38.7 : OU=Parcs est en lecture seule,
        // un AppProfile n'a plus de représentation AD à écrire.

        // Services Parc
        $this->app->singleton(MachinePowerService::class);

        // Story 16.14 Q2 — cache santé GPO 24h + warm-up 22h (singleton).
        $this->app->singleton(\App\Gpo\Support\CachedGpoLookups::class);

        // Service de pont legacy pour les parcs
        $this->app->singleton(LegacyParcBridgeService::class);

        // Story 60.3 — résolution des backends de fichiers PAR NOM (ligne de
        // contrat du plan de fichiers). Le registre est sans état : il ne fait que
        // traduire un nom en implémentation, via le conteneur.
        $this->app->singleton(\App\Services\Filesystem\Backend\FileBackendRegistry::class);
        $this->app->singleton(\App\Services\Filesystem\Backend\PreviewBackend::class);

        // Binding AuthGuard — swap Phase 2 : remplacer SambaEduAuthGuard par KeycloakAuthGuard
        // Sous APP_ENV=dusk : guard de test sans LDAP (voir DuskAuthGuard).
        $this->app->bind(
            \App\Http\Middleware\Auth\AuthGuardInterface::class,
            $this->app->environment('dusk')
                ? \App\Http\Middleware\Auth\DuskAuthGuard::class
                : \App\Http\Middleware\Auth\SambaEduAuthGuard::class
        );

        // Story 6.1 — CommandRunner injectable pour les services CUPS.
        // Permet de mocker les exec dans les tests via FakeCommandRunner.
        $this->app->bind(
            \App\Services\Print\Contracts\CommandRunner::class,
            \App\Services\Print\RealCommandRunner::class,
        );

        // Story 56.2 — LE seam privilégié du moteur d'installation d'extensions
        // (patron CommandRunner ci-dessus). Toute la surface root du système
        // d'extensions passe par cette interface : les tests la remplacent par
        // `FakeExtensionHelperRunner` et observent la séquence exacte des appels
        // (apt, systemd, Apache) sans jamais exécuter quoi que ce soit.
        $this->app->bind(
            \App\Services\Extensions\Contracts\ExtensionHelperRunner::class,
            \App\Services\Extensions\SudoExtensionHelperRunner::class,
        );

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
        // Story 62.1 — LA COUTURE DE PURETÉ. Le namespace du plan de fichiers vit
        // au-dessus de la ligne de contrat : il n'interroge rien, et un test
        // d'architecture le vérifie sur le TEXTE des fichiers. Le vocabulaire de
        // rôle d'arête, lui, est désormais une table administrable. La source
        // ENTRE donc dans le plan par injection, une fois, ici — le plan ne va
        // jamais la chercher. Sans cette ligne, le normalizer retomberait sur son
        // repli littéral (`member|manager|owner`) et un rôle nouveau du catalogue
        // serait refusé par la résolution.
        \App\Services\Filesystem\Plan\GroupNameNormalizer::useEdgeRoles(
            static fn (): array => \App\Support\RoleCatalog::keys(),
        );

        // Review 62.1 #1 — la mémo du catalogue est une propriété STATIQUE, et
        // `RoleCatalog::flush()` n'est déclenchée que par les hooks d'écriture du
        // modèle, donc uniquement dans le PROCESSUS qui a écrit. Sous PHP-FPM ça
        // suffit (chaque requête repart d'un moteur neuf) ; un worker
        // `queue:work --queue=sync --max-time=3600`
        // (`scripts/config/laravel-queue-sync.service`) est un process CLI qui
        // enchaîne les jobs SANS réinitialiser les statiques : un rôle créé à
        // l'écran resterait invisible du worker jusqu'à une heure. Conséquence
        // réelle mesurée sur le code : `UserGroupService` (projection AD) lit
        // `UserGroupUserPivot::roles()` et traite toute valeur absente du
        // vocabulaire comme « hors vocabulaire » — l'arête `tuteur` d'un
        // enseignant serait projetée avec le rôle DÉRIVÉ, dans le mauvais groupe
        // d'annuaire. On repart donc du catalogue à chaque job, au prix d'une
        // requête par job. Le patron `Queue::before` est déjà en service plus bas
        // pour `queue_task_runs`.
        Queue::before(static function (): void {
            \App\Support\RoleCatalog::flush();
        });

        // Enregistrer les observers pour la synchronisation AD
        WorkstationGroup::observe(WorkstationGroupObserver::class);
        UserGroup::observe(UserGroupObserver::class);
        AppProfile::observe(AppProfileObserver::class);

        // Story 4.9 — Observer Workstation : enregistré uniquement hors
        // environnement de test (queue=sync en PHPUnit → tout dispatch
        // tape LDAP/AD réel et casse les tests qui touchent Workstation
        // sans muter l'event dispatcher). Les tests qui veulent l'observer
        // l'enregistrent explicitement (cf. WorkstationObserverTest::setUp).
        if (! $this->app->environment('testing')) {
            Workstation::observe(WorkstationObserver::class);
        }

        // Story 5.2 (D5=A) — Observer sur le pivot user_group_user pour
        // synchroniser les ACLs FS lors d'un changement de classe d'élève.
        \App\Models\Pivot\UserGroupUserPivot::observe(
            \App\Observers\UserGroupUserPivotObserver::class
        );

        // Story 36.1 (corr. review #2b) — garde-fou d'authoring fs_acl RÉEL :
        // une projection windows/fs_acl dangereuse (Q2 : deny descendant sur
        // racine protégée, deny principal système, nom court 8.3, deny sans
        // warning…) ne peut plus être persistée (FsAclAuthoringException).
        // Story 36.2 — le MÊME enregistrement gate AUSSI le garde-fou firewall :
        // l'observer dispatche par mécanisme (fs_acl → FsAclAuthoringGuard ;
        // firewall → FirewallAuthoringGuard, Q3 = refus block couvrant le LAN),
        // une projection windows/firewall dangereuse lève FirewallAuthoringException.
        // Story 35.6 — idem pour le mécanisme privilege (privilege →
        // PrivilegeAuthoringGuard, SeDeny*-only : un droit *grant* verrouillerait
        // la machine) : une projection windows/privilege fautive lève
        // PrivilegeAuthoringException.
        // Story 36.5 — idem pour le mécanisme app_profile (app_profile →
        // AppProfileAuthoringGuard, piège n°1 : un nom de profil bâti sur le
        // radical « sambaedu » collisionnerait avec le nettoyage legacy_cleanup) :
        // une projection windows/app_profile fautive lève AppProfileAuthoringException.
        // Enregistré hors environnement de test (patron Workstation ci-dessous) :
        // de nombreux tests unitaires du provider fabriquent volontairement des
        // specs fs_acl ADVERSARIALES via factory Eloquent pour prouver la
        // non-émission défensive ; l'observer les ferait échouer. Les tests qui
        // veulent l'observer l'enregistrent explicitement (cf.
        // CapabilityProjectionObserverTest::setUp). Le seed passe de toute façon
        // (Query Builder → aucun événement Eloquent).
        if (! $this->app->environment('testing')) {
            \App\Models\CapabilityProjection::observe(
                \App\Observers\CapabilityProjectionObserver::class
            );
        }

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
        });

        // Story 29.9 — réactive le tracking d'exécution des jobs queue.
        // Cet appel avait été retiré de boot() dans le commit 997df15 (« fix
        // livewire update redirection ») ; conséquence : le dashboard /workers
        // (WorkerMonitoringService → queue_task_runs) n'enregistrait plus aucun
        // run. On le rétablit ici. Le garde Schema::hasTable rend les handlers
        // inertes si la table n'existe pas encore.
        // Dette connue (hors-scope 29.9, à traiter en story dédiée) : rétention
        // de queue_task_runs (croissance non bornée) et coût DB par job.
        $this->registerQueueTaskTracking();

        $this->applyConfigurableSessionLifetime();
    }

    /**
     * Déconnexion automatique sur inactivité — applique le réglage admin
     * `SystemSetting('security.session_idle')` à `config('session.lifetime')`.
     *
     * La session admin est une session Laravel classique (rolling / idle-based) :
     * réduire `session.lifetime` = déconnexion après N minutes sans activité.
     * boot() s'exécute avant le middleware StartSession, l'override est donc pris
     * en compte. Piloté depuis /admin/settings/security.
     *
     * Inerte en console (worker/migrations) et tant que la table n'existe pas
     * (greenfield), pour ne jamais casser un bootstrap.
     */
    private function applyConfigurableSessionLifetime(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        try {
            if (! Schema::hasTable('system_settings')) {
                return;
            }

            $cfg = \App\Models\SystemSetting::get('security.session_idle', null);

            if (is_array($cfg) && ($cfg['enabled'] ?? false) === true) {
                $minutes = (int) ($cfg['minutes'] ?? 0);
                // Borne défensive iso-UI (5 min .. 24h) : jamais 0 (= session infinie).
                if ($minutes >= 5 && $minutes <= 1440) {
                    config(['session.lifetime' => $minutes]);
                }
            }
        } catch (\Throwable $e) {
            // Ne jamais faire échouer le boot pour un réglage de confort.
            \Illuminate\Support\Facades\Log::warning(
                'AppServiceProvider: échec application session_idle',
                ['error' => $e->getMessage()],
            );
        }
    }

    private function registerQueueTaskTracking(): void
    {
        // Story 29.10 — Mémoïsation : Schema::hasTable est une introspection
        // coûteuse (information_schema sur PG). On mémoïse UNIQUEMENT le `true` :
        // dès que la table est vue présente, plus aucune introspection (coût/job
        // = 0 en régime établi, AC#2). Tant qu'elle est absente (fenêtre greenfield
        // : worker démarré avant `migrate`), on re-vérifie à chaque événement — son
        // apparition en cours de vie du worker est ainsi captée (comportement
        // préservé ; les chemins INSERT de `after`/`failing` restent atteignables).
        // Variable partagée par référence entre les trois closures.
        $tableExists = false;
        $checkTable = static function () use (&$tableExists): bool {
            if (! $tableExists) {
                $tableExists = Schema::hasTable('queue_task_runs');
            }
            return $tableExists;
        };

        Queue::before(function (JobProcessing $event) use ($checkTable): void {
            if (! $checkTable()) {
                return;
            }

            $payload = $event->job->payload();
            $taskUuid = (string) ($payload['uuid'] ?? sha1($event->job->getRawBody()));
            $jobName = (string) ($payload['displayName'] ?? $payload['job'] ?? 'UnknownJob');

            // Closure : `created_at` est posé UNIQUEMENT à l'INSERT.
            // Sur un retry (UPDATE — même task_uuid), `created_at` d'origine est
            // PRÉSERVÉ. Les autres champs (reset intentionnel) sont écrits dans les
            // deux cas. (Story 29.9 — correctif du bug pré-existant.)
            DB::table('queue_task_runs')->updateOrInsert(
                ['task_uuid' => $taskUuid],
                fn (bool $exists): array => array_merge([
                    'queue' => (string) $event->job->getQueue(),
                    'job_name' => $jobName,
                    'status' => 'running',
                    'started_at' => now(),
                    'finished_at' => null,
                    'failed_at' => null,
                    'error_message' => null,
                    'log_lines' => "[" . now()->toDateTimeString() . "] START {$jobName}",
                    'updated_at' => now(),
                ], $exists ? [] : ['created_at' => now()]),
            );
        });

        Queue::after(function (JobProcessed $event) use ($checkTable): void {
            if (! $checkTable()) {
                return;
            }

            $payload = $event->job->payload();
            $taskUuid = (string) ($payload['uuid'] ?? sha1($event->job->getRawBody()));
            $jobName = (string) ($payload['displayName'] ?? $payload['job'] ?? 'UnknownJob');

            $existingLogs = (string) (DB::table('queue_task_runs')->where('task_uuid', $taskUuid)->value('log_lines') ?? '');
            $appendedLogs = trim($existingLogs . "\n[" . now()->toDateTimeString() . "] DONE {$jobName}");

            // Closure iso-`before` : si `before` n'a pas tourné (course rare,
            // table migrée en cours de vie du worker), l'INSERT par `after` pose
            // `created_at` ; sur l'UPDATE normal, `created_at` d'origine préservé.
            DB::table('queue_task_runs')->updateOrInsert(
                ['task_uuid' => $taskUuid],
                fn (bool $exists): array => array_merge([
                    'queue' => (string) $event->job->getQueue(),
                    'job_name' => $jobName,
                    'status' => 'done',
                    'finished_at' => now(),
                    'updated_at' => now(),
                    'log_lines' => $appendedLogs,
                ], $exists ? [] : ['created_at' => now()]),
            );
        });

        Queue::failing(function (JobFailed $event) use ($checkTable): void {
            if (! $checkTable()) {
                return;
            }

            $payload = $event->job->payload();
            $taskUuid = (string) ($payload['uuid'] ?? sha1($event->job->getRawBody()));
            $jobName = (string) ($payload['displayName'] ?? $payload['job'] ?? 'UnknownJob');
            $message = $event->exception->getMessage();

            $existingLogs = (string) (DB::table('queue_task_runs')->where('task_uuid', $taskUuid)->value('log_lines') ?? '');
            $appendedLogs = trim($existingLogs . "\n[" . now()->toDateTimeString() . "] FAILED {$jobName}: {$message}");

            // Closure iso-`before` : INSERT (cas où seul `failing` est observé)
            // pose `created_at` ; UPDATE préserve le `created_at` d'origine.
            DB::table('queue_task_runs')->updateOrInsert(
                ['task_uuid' => $taskUuid],
                fn (bool $exists): array => array_merge([
                    'queue' => (string) $event->job->getQueue(),
                    'job_name' => $jobName,
                    'status' => 'failed',
                    'failed_at' => now(),
                    'error_message' => $message,
                    'updated_at' => now(),
                    'log_lines' => $appendedLogs,
                ], $exists ? [] : ['created_at' => now()]),
            );
        });
    }
}
