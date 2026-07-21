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

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
