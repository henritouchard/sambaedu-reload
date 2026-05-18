<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\V1\Http\Middleware\EnsureRefreshToken;
use App\Auth\V1\Http\Middleware\EnsureSecureApiHeaders;
use App\Auth\V1\Http\Middleware\EnsureWorkstationJwt;
use App\Auth\V1\Http\Middleware\RequireBootstrapToken;
use App\Auth\V1\Jwt\WorkstationJwtIssuer;
use App\Auth\V1\Jwt\WorkstationJwtRefreshService;
use App\Auth\V1\Jwt\WorkstationJwtRevocationChecker;
use App\Auth\V1\Jwt\WorkstationJwtVerifier;
use App\Auth\V1\Pki\CaInitializer;
use App\Auth\V1\Services\LegacyBootstrapTokenValidator;
use App\Console\Commands\AuthCaInit;
use App\Console\Commands\WorkstationJwtRotateKeys;
use App\Console\Commands\WorkstationRevoke;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Story 16.10 — AC6.2.
 *
 * Service Provider du module Auth V1.
 *
 *  - Bindings singletons (services stateless réutilisables).
 *  - Auto-création des dossiers `storage/logs/auth-v1/`, `storage/keys/jwt/`,
 *    `storage/keys/pki/` au boot (parité `GpoServiceProvider`).
 *  - Warning si les clés JWT sont absentes (boot ne bloque pas — l'API
 *    `/api/v1/agent/*` retournera 500 à la première requête sans clés).
 *  - Enregistrement Artisan commands `auth:ca:init`, `workstation:revoke`,
 *    `workstation:jwt:rotate-keys`.
 *  - Enregistrement des alias de middleware via le router (compatibilité
 *    Laravel 12).
 *
 * Skip complet en environnement `testing` (pattern iso
 * `GpoServiceProvider` — pas de warning bruyant sur runners CI).
 */
class AuthV1ServiceProvider extends ServiceProvider
{
    /** Mode des dossiers créés. */
    private const DIR_MODE_LOGS = 0755;

    /** Mode des dossiers de clés (rwx propriétaire seul). */
    private const DIR_MODE_KEYS = 0700;

    public function register(): void
    {
        $this->mergeConfigFrom(
            base_path('config/auth_v1.php'),
            'auth_v1',
        );

        // PKI + JWT services en singletons (stateless, réutilisables)
        $this->app->singleton(CaInitializer::class, fn () => new CaInitializer());
        $this->app->singleton(WorkstationJwtRevocationChecker::class, fn () => new WorkstationJwtRevocationChecker());
        $this->app->singleton(LegacyBootstrapTokenValidator::class, fn () => new LegacyBootstrapTokenValidator());
        $this->app->singleton(WorkstationJwtIssuer::class, fn () => new WorkstationJwtIssuer());

        $this->app->singleton(WorkstationJwtVerifier::class, fn ($app) => new WorkstationJwtVerifier(
            $app->make(WorkstationJwtRevocationChecker::class),
        ));

        $this->app->singleton(WorkstationJwtRefreshService::class, fn ($app) => new WorkstationJwtRefreshService(
            $app->make(WorkstationJwtIssuer::class),
        ));

        // Commandes Artisan
        if ($this->app->runningInConsole()) {
            $this->commands([
                AuthCaInit::class,
                WorkstationRevoke::class,
                WorkstationJwtRotateKeys::class,
            ]);
        }
    }

    public function boot(Router $router): void
    {
        // Alias middlewares (toujours, y compris tests pour pouvoir invoquer
        // les routes /api/v1/agent/* en Feature test).
        $router->aliasMiddleware('auth.v1.workstation', EnsureWorkstationJwt::class);
        $router->aliasMiddleware('auth.v1.bootstrap', RequireBootstrapToken::class);
        $router->aliasMiddleware('auth.v1.refresh', EnsureRefreshToken::class);
        $router->aliasMiddleware('auth.v1.secure-headers', EnsureSecureApiHeaders::class);

        if ($this->app->environment('testing')) {
            return;
        }

        $this->ensureLogDirectory();
        $this->ensureKeyDirectories();
        $this->warnIfKeysMissing();
    }

    private function ensureLogDirectory(): void
    {
        $dir = storage_path('logs/auth-v1');
        if (is_dir($dir)) {
            return;
        }
        if (! @mkdir($dir, self::DIR_MODE_LOGS, true) && ! is_dir($dir)) {
            Log::warning('[auth-v1] cannot create log directory', ['path' => $dir]);
        }
    }

    private function ensureKeyDirectories(): void
    {
        $candidates = [
            storage_path('keys/jwt'),
            storage_path('keys/pki'),
        ];
        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                continue;
            }
            if (! @mkdir($dir, self::DIR_MODE_KEYS, true) && ! is_dir($dir)) {
                Log::warning('[auth-v1] cannot create key directory', ['path' => $dir]);
            }
        }
    }

    /**
     * Warn (mais ne bloque pas) si la paire JWT du `active_kid` est absente.
     */
    private function warnIfKeysMissing(): void
    {
        $kid = (string) config('auth_v1.jwt.active_kid', '');
        if ($kid === '') {
            Log::channel('auth-v1')->warning('[auth-v1] auth_v1.jwt.active_kid not configured');

            return;
        }
        $priv = (string) config('auth_v1.jwt.keys.' . $kid . '.private', '');
        $pub = (string) config('auth_v1.jwt.keys.' . $kid . '.public', '');

        if ($priv === '' || ! is_file($priv)) {
            Log::channel('auth-v1')->warning('[auth-v1] JWT private key missing', [
                'kid' => $kid,
                'path' => $priv,
                'hint' => 'run php artisan auth:ca:init',
            ]);
        }
        if ($pub === '' || ! is_file($pub)) {
            Log::channel('auth-v1')->warning('[auth-v1] JWT public key missing', [
                'kid' => $kid,
                'path' => $pub,
                'hint' => 'run php artisan auth:ca:init',
            ]);
        }
    }
}
