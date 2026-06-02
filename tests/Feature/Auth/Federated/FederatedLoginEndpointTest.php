<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\Federated;

use App\Auth\Federated\FederatedRoleMapper;
use App\Auth\Federated\Http\FederatedLoginController;
use App\Auth\Federated\Jwt\FederatedJwtReplayChecker;
use App\Auth\Federated\Jwt\FederatedJwtVerifier;
use App\Auth\Federated\Session\FederatedSession;
use App\Enums\SambaRole;
use App\Http\Middleware\Auth\SambaEduAuthGuard;
use App\Models\ExternalIdentity;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\AuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Support\Facades\Auth;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\IssuesFederatedJwt;
use Tests\TestCase;

/**
 * Story 20.1 — Feature : endpoint de login fédéré + réconciliation guard (D-5).
 *
 * Couvre AC1, AC11-15. Pour rester déterministe sur le host (pas de LDAP/PG),
 * le test exerce directement le controller d'entrée et le guard de session,
 * avec une vraie DB SQLite et des mocks Mockery côté LDAP.
 */
class FederatedLoginEndpointTest extends TestCase
{
    use IssuesFederatedJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFederatedAuth();
        $this->ensureFederatedTables();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeController(): FederatedLoginController
    {
        return new FederatedLoginController(
            new FederatedJwtVerifier(),
            new FederatedRoleMapper(),
            new FederatedJwtReplayChecker(),
        );
    }

    private function requestWithToken(string $token): Request
    {
        $request = Request::create('/auth/federated/callback', 'POST', ['token' => $token]);
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }

    #[Test]
    public function valid_login_opens_session_and_applies_role(): void
    {
        $emitted = $this->issueFederatedJwt(['sub' => 'ext-aaa', 'role' => 'technicien']);

        $response = $this->makeController()->callback($this->requestWithToken($emitted['token']));

        $this->assertSame(302, $response->getStatusCode());

        $user = User::where('source', 'federated')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->dn);
        $this->assertNull($user->ad_guid);
        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());

        // Rôle Spatie appliqué.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($user->fresh()->hasRole(SambaRole::Technicien->value));
    }

    #[Test]
    public function unknown_role_returns_403_and_opens_no_session(): void
    {
        $emitted = $this->issueFederatedJwt(['sub' => 'ext-bbb', 'role' => 'pirate-role']);

        try {
            $this->makeController()->callback($this->requestWithToken($emitted['token']));
            $this->fail('Expected 403 HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, User::where('source', 'federated')->count());
        $this->assertFalse(Auth::check());
    }

    #[Test]
    public function external_identity_created_then_reused_by_sub(): void
    {
        $first = $this->issueFederatedJwt(['sub' => 'ext-ccc', 'jti' => 'jti-1']);
        $this->makeController()->callback($this->requestWithToken($first['token']));

        $this->assertSame(1, ExternalIdentity::where('external_sub', 'ext-ccc')->count());
        $identityId = ExternalIdentity::where('external_sub', 'ext-ccc')->value('id');
        $userCount = User::where('source', 'federated')->count();

        // 2e login (jti différent, même sub) → même identité + même user.
        Auth::logout();
        $second = $this->issueFederatedJwt(['sub' => 'ext-ccc', 'jti' => 'jti-2']);
        $this->makeController()->callback($this->requestWithToken($second['token']));

        $this->assertSame(1, ExternalIdentity::where('external_sub', 'ext-ccc')->count());
        $this->assertSame($identityId, ExternalIdentity::where('external_sub', 'ext-ccc')->value('id'));
        $this->assertSame($userCount, User::where('source', 'federated')->count());
    }

    #[Test]
    public function deactivated_identity_is_refused_on_fresh_login(): void
    {
        // Q1 / review #1 : un admin a désactivé l'identité externe. Un nouveau
        // JWT valide ne doit PAS la réarmer silencieusement → 403, pas de session.
        $first = $this->issueFederatedJwt(['sub' => 'ext-revoked', 'jti' => 'jti-rev-1']);
        $this->makeController()->callback($this->requestWithToken($first['token']));
        Auth::logout();

        // Désactivation administrative.
        $identity = ExternalIdentity::where('external_sub', 'ext-revoked')->firstOrFail();
        $identity->is_active = false;
        $identity->save();

        // Fresh login avec un nouveau jeton valide → refusé.
        $second = $this->issueFederatedJwt(['sub' => 'ext-revoked', 'jti' => 'jti-rev-2']);
        try {
            $this->makeController()->callback($this->requestWithToken($second['token']));
            $this->fail('Expected 403 HttpException (identité révoquée)');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // L'identité reste désactivée (pas de réactivation silencieuse).
        $this->assertFalse((bool) $identity->fresh()->is_active);
        $this->assertFalse(Auth::check());
    }

    #[Test]
    public function soft_deleted_identity_is_refused_on_fresh_login(): void
    {
        // Variante Q1 : identité soft-deletée → révocation effective, 403.
        $first = $this->issueFederatedJwt(['sub' => 'ext-trashed', 'jti' => 'jti-tr-1']);
        $this->makeController()->callback($this->requestWithToken($first['token']));
        Auth::logout();

        ExternalIdentity::where('external_sub', 'ext-trashed')->firstOrFail()->delete();

        $second = $this->issueFederatedJwt(['sub' => 'ext-trashed', 'jti' => 'jti-tr-2']);
        try {
            $this->makeController()->callback($this->requestWithToken($second['token']));
            $this->fail('Expected 403 HttpException (identité soft-deletée)');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Toujours soft-deletée (pas de restauration silencieuse).
        $this->assertNotNull(ExternalIdentity::withTrashed()->where('external_sub', 'ext-trashed')->first()->deleted_at);
        $this->assertFalse(Auth::check());
    }

    #[Test]
    public function replayed_token_is_rejected_on_second_post(): void
    {
        $emitted = $this->issueFederatedJwt(['sub' => 'ext-ddd', 'jti' => 'jti-replay']);

        // 1er POST OK.
        $this->makeController()->callback($this->requestWithToken($emitted['token']));

        // 2e POST du MÊME jeton → 401.
        try {
            $this->makeController()->callback($this->requestWithToken($emitted['token']));
            $this->fail('Expected 401 replay HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    #[Test]
    public function federated_session_is_not_logged_out_on_next_request(): void
    {
        // 1. Login fédéré.
        $emitted = $this->issueFederatedJwt(['sub' => 'ext-eee', 'role' => 'technicien']);
        $loginRequest = $this->requestWithToken($emitted['token']);
        $this->makeController()->callback($loginRequest);

        $user = User::where('source', 'federated')->first();
        $this->assertNotNull($user);
        $this->assertTrue(FederatedSession::isFederated($loginRequest));

        // 2. Requête authentifiée suivante passant par le guard de session.
        $guard = $this->makeGuardSkippingLdap();
        $nextRequest = Request::create('/app/dashboard', 'GET');
        $nextRequest->setLaravelSession($loginRequest->session());

        $called = false;
        $response = $guard->handle($nextRequest, function ($req) use (&$called) {
            $called = true;
            return new \Symfony\Component\HttpFoundation\Response('ok', 200);
        });

        // L'externe N'EST PAS déconnecté (le guard a sauté le LDAP).
        $this->assertTrue($called, 'Le guard fédéré aurait dû laisser passer la requête');
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function deactivated_external_identity_is_logged_out_on_next_request(): void
    {
        $emitted = $this->issueFederatedJwt(['sub' => 'ext-fff', 'role' => 'technicien']);
        $loginRequest = $this->requestWithToken($emitted['token']);
        $this->makeController()->callback($loginRequest);

        // Désactivation côté SE5 (révocation effective indépendante de l'AD).
        ExternalIdentity::where('external_sub', 'ext-fff')->update(['is_active' => false]);

        $guard = $this->makeGuardSkippingLdap();
        $nextRequest = Request::create('/app/dashboard', 'GET');
        $nextRequest->setLaravelSession($loginRequest->session());

        $called = false;
        $response = $guard->handle($nextRequest, function ($req) use (&$called) {
            $called = true;
            return new \Symfony\Component\HttpFoundation\Response('ok', 200);
        });

        $this->assertFalse($called, 'Le guard aurait dû refuser une identité désactivée');
        $this->assertNotSame(200, $response->getStatusCode());
    }

    #[Test]
    public function ldap_user_flow_is_unchanged_for_non_federated_sessions(): void
    {
        // Régression AC15 : une session NON fédérée passe par le flux LDAP
        // standard (findByLogin appelé), strictement inchangé.
        // Pré-crée l'Eloquent pour court-circuiter ensureEloquentUser.
        User::create([
            'login' => 'prof.dupont', 'fullname' => 'Prof Dupont', 'source' => 'ad',
            'role' => 'prof', 'is_active' => true,
        ]);

        $ldapUser = new \App\Types\User(
            login: 'prof.dupont', fullname: 'Prof Dupont', firstname: 'Prof',
            lastname: 'Dupont', email: 'p@d.fr', isActive: true, dn: 'CN=prof,DC=x', role: 'prof',
        );

        $authService = Mockery::mock(AuthenticationService::class);
        $authService->shouldReceive('isAlreadyAuthenticated')->andReturn(true);
        $authService->shouldReceive('getCurrentUser')->andReturn('prof.dupont');

        $userRepo = Mockery::mock(UserRepository::class);
        $userRepo->shouldReceive('findByLogin')->once()->with('prof.dupont')->andReturn($ldapUser);

        $guard = new SambaEduAuthGuard($authService, $userRepo);

        $request = Request::create('/app/dashboard', 'GET');
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        $called = false;
        $guard->handle($request, function () use (&$called) {
            $called = true;
            return new \Symfony\Component\HttpFoundation\Response('ok', 200);
        });

        $this->assertTrue($called, 'Le flux LDAP standard doit passer (findByLogin appelé)');
    }

    #[Test]
    public function no_secret_or_jwt_leaks_in_role_unknown_path(): void
    {
        $emitted = $this->issueFederatedJwt(['sub' => 'ext-ggg', 'role' => 'pirate-role']);

        try {
            $this->makeController()->callback($this->requestWithToken($emitted['token']));
            $this->fail('Expected 403');
        } catch (HttpException $e) {
            // Le message d'erreur ne contient ni le JWT brut ni de clé.
            $this->assertStringNotContainsString($emitted['token'], (string) $e->getMessage());
            $this->assertStringNotContainsString('BEGIN', (string) $e->getMessage());
        }
    }

    /**
     * Guard configuré pour les sessions fédérées : `AuthenticationService` et
     * `UserRepository` mockés. `findByLogin` ne DOIT PAS être appelé (le guard
     * saute le LDAP). On lit le login depuis le marqueur fédéré.
     */
    private function makeGuardSkippingLdap(): SambaEduAuthGuard
    {
        $user = User::where('source', 'federated')->first();

        $authService = Mockery::mock(AuthenticationService::class);
        $authService->shouldReceive('isAlreadyAuthenticated')->andReturn(true);
        $authService->shouldReceive('getCurrentUser')->andReturn($user?->login);
        $authService->shouldReceive('logout')->andReturnNull();

        $userRepo = Mockery::mock(UserRepository::class);
        // Garde-fou : le LDAP ne doit JAMAIS être interrogé pour un externe.
        $userRepo->shouldReceive('findByLogin')->never();

        return new SambaEduAuthGuard($authService, $userRepo);
    }
}
