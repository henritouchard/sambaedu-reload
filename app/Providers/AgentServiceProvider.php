<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Services\Agent\Enrollment\EnrollmentService;
use App\Services\Agent\Enrollment\TokenRotationService;
use App\Services\Agent\Providers\OverlayStateProvider;
use App\Services\Agent\Providers\WallpaperStateProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateHasher;
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
        $this->app->singleton(
            EnrollmentService::class,
            fn ($app) => new EnrollmentService($app->make(TokenRotationService::class)),
        );
        $this->app->singleton(StateHasher::class, fn () => new StateHasher());
        $this->app->singleton(StateCompiler::class, fn ($app) => new StateCompiler(
            $app->make(StateHasher::class),
            [
                $app->make(WallpaperStateProvider::class),
                $app->make(OverlayStateProvider::class),
            ],
        ));
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('agent.token', AuthenticateAgentToken::class);
    }
}
