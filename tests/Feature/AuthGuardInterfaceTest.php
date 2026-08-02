<?php

namespace Tests\Feature;

use App\Http\Middleware\Auth\AuthGuardInterface;
use App\Http\Middleware\Auth\KeycloakAuthGuard;
use App\Http\Middleware\Auth\SambaEduAuthGuard;
use App\Services\AuthenticationService;
use App\Auth\Federated\ExternalIdentityLifecycleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Tests de l'architecture AuthGuard (Story 1.4)
 *
 * Valide :
 * - L'interface est correctement implémentée par les deux guards
 * - Le middleware délègue bien au guard actif
 * - Le comportement auth (non-authentifié → redirect, authentifié → passage) est préservé
 * - Le binding IoC résout SambaEduAuthGuard par défaut
 */
class AuthGuardInterfaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    // ─── AC1 : Interface ────────────────────────────────────────────────────────

    /**
     * AC1 — SambaEduAuthGuard implémente AuthGuardInterface
     */
    public function test_sambaedu_auth_guard_implements_interface(): void
    {
        $authService = $this->createMock(AuthenticationService::class);

        $guard = new SambaEduAuthGuard($authService, new ExternalIdentityLifecycleService());

        $this->assertInstanceOf(AuthGuardInterface::class, $guard);
    }

    /**
     * AC4 — KeycloakAuthGuard implémente AuthGuardInterface
     */
    public function test_keycloak_auth_guard_implements_interface(): void
    {
        $guard = new KeycloakAuthGuard();

        $this->assertInstanceOf(AuthGuardInterface::class, $guard);
    }

    // ─── AC5 : Binding IoC ──────────────────────────────────────────────────────

    /**
     * AC5 — Le conteneur résout AuthGuardInterface → SambaEduAuthGuard par défaut
     */
    public function test_ioc_binding_resolves_sambaedu_auth_guard(): void
    {
        $resolved = $this->app->make(AuthGuardInterface::class);

        $this->assertInstanceOf(SambaEduAuthGuard::class, $resolved);
    }

    // ─── AC2, AC6 : Comportement middleware ─────────────────────────────────────

    /**
     * AC2, AC6 — Utilisateur non authentifié → redirect vers route('auth.login')
     *
     * Le guard réel SambaEduAuthGuard est utilisé avec AuthenticationService mocké.
     */
    public function test_unauthenticated_request_redirects_to_login(): void
    {
        // Mocker AuthenticationService : isAlreadyAuthenticated() → false
        $authServiceMock = $this->createMock(AuthenticationService::class);
        $authServiceMock->method('isAlreadyAuthenticated')->willReturn(false);

        // Remplacer les bindings dans le conteneur
        $this->app->instance(AuthenticationService::class, $authServiceMock);

        // Forcer la recréation du guard avec les nouveaux mocks
        $this->app->bind(
            AuthGuardInterface::class,
            fn ($app) => new SambaEduAuthGuard(
                $app->make(AuthenticationService::class),
                $app->make(ExternalIdentityLifecycleService::class),
            ),
        );

        $response = $this->get('/test-auth');

        $response->assertRedirect(route('auth.login'));
    }

    /**
     * AC2, AC6 — Utilisateur authentifié avec guard passant → $next($request) appelé
     *
     * On swape le binding pour un guard mock qui appelle $next($request).
     * Ce test valide que le middleware délègue correctement au guard actif.
     */
    public function test_authenticated_request_passes_through(): void
    {
        // Guard mock : laisse toujours passer la requête
        $passThroughGuard = new class implements AuthGuardInterface {
            public function handle(Request $request, Closure $next): Response
            {
                return $next($request);
            }
        };

        $this->app->instance(AuthGuardInterface::class, $passThroughGuard);

        $response = $this->get('/test-auth');

        $response->assertStatus(200);
        $response->assertJson(['authenticated' => true]);
    }

    // ─── AC3, AC6 : Comportement guard avec login vide ──────────────────────────

    /**
     * AC3, AC6 — Authentifié mais login vide → redirect (comportement inchangé)
     */
    public function test_empty_login_redirects_to_login(): void
    {
        $authServiceMock = $this->createMock(AuthenticationService::class);
        $authServiceMock->method('isAlreadyAuthenticated')->willReturn(true);
        $authServiceMock->method('getCurrentUser')->willReturn('');

        $this->app->instance(AuthenticationService::class, $authServiceMock);

        $this->app->bind(
            AuthGuardInterface::class,
            fn ($app) => new SambaEduAuthGuard(
                $app->make(AuthenticationService::class),
                $app->make(ExternalIdentityLifecycleService::class),
            ),
        );

        $response = $this->get('/test-auth');

        $response->assertRedirect(route('auth.login'));
    }
}
