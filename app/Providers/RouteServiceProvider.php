<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Story 8.4 — DDNS piloté par DHCP. Seau DÉDIÉ : l'appelant unique est
        // le serveur lui-même (dhcpd co-localisé), donc un limiteur anonyme
        // partagerait sa clé (domaine|IP) avec les autres routes machine et
        // plafonnerait le parc entier. Seuil large : au retour d'une coupure,
        // plusieurs centaines de baux commitent dans la même minute, et un 429
        // sur un `delete` n'est jamais rejoué (aucun renouvellement derrière).
        RateLimiter::for('ddns', fn (Request $request) => Limit::perMinute(2000)->by('ddns'));

        // Story 56.4 (review #3) — API extensions. MÊME motif que `ddns` :
        // toutes les extensions `app` tournent sur CET hôte, derrière le
        // reverse-proxy Apache — leurs appels arrivent donc tous de la même IP.
        // Le limiteur anonyme aurait fait partager un unique seau de 60/min à
        // TOUTES les extensions du serveur : une extension bavarde, ou en
        // boucle d'erreur, aurait mis les autres en 429. Le seau est donc par
        // CLIENT OIDC — l'identité posée par `ext.token` juste avant (ce
        // limiteur ne s'applique qu'APRÈS ce middleware).
        //
        // Repli sur l'IP : impossible en pratique (sans client résolu, la
        // requête a déjà été refusée en 401), gardé par prudence — un limiteur
        // dont la clé peut être vide plafonnerait tout le monde ensemble.
        RateLimiter::for('ext-api', function (Request $request) {
            $clientId = $request->attributes->get('ext.client')?->client_id;

            return Limit::perMinute(60)->by(is_string($clientId) && $clientId !== ''
                ? 'ext-api:'.$clientId
                : 'ext-api:ip:'.$request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
