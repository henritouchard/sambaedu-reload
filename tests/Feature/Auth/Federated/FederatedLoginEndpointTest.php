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
        // Story 20.3 — D-1 : la résolution est un lookup direct dans les rôles
        // EXISTANTS. Les tests seedent en base le rôle attendu (auparavant
        // déclaré dans `config('federated_auth.role_map')`, table supprimée).
        Role::firstOrCreate(['name' => SambaRole::Technicien->value, 'guard_name' => 'web']);
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
            new \App\Auth\Federated\ExternalIdentityLifecycleService(),
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
    public function role_absent_from_db_returns_403_and_creates_no_role(): void
    {
        // Story 20.3 — AC3 / D-2 end-to-end : le rôle asséré n'EXISTE PAS dans
        // l'instance → 403, aucune session, et AUCUN rôle créé à la volée
        // (Role::count() inchangé). Aucune table de correspondance n'est
        // consultée : la résolution est un lookup direct dans `roles`.
        $rolesBefore = Role::count();

        $emitted = $this->issueFederatedJwt(['sub' => 'ext-absent', 'role' => 'role-inexistant']);

        try {
            $this->makeController()->callback($this->requestWithToken($emitted['token']));
            $this->fail('Expected 403 (rôle absent en base)');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, User::where('source', 'federated')->count());
        $this->assertFalse(Auth::check());
        // AUCUNE création de rôle à la volée.
        $this->assertSame($rolesBefore, Role::count());
        $this->assertFalse(Role::where('name', 'role-inexistant')->exists());
    }

    #[Test]
    public function second_existing_role_applies_correct_spatie_role(): void
    {
        // Story 20.3 — AC1 : un 2e rôle EXISTANT (seedé en base, pas en config)
        // applique le rôle Spatie correspondant.
        Role::firstOrCreate(['name' => SambaRole::ReferentNumerique->value, 'guard_name' => 'web']);

        $emitted = $this->issueFederatedJwt(['sub' => 'ext-ref', 'role' => 'referent-numerique']);
        $response = $this->makeController()->callback($this->requestWithToken($emitted['token']));

        $this->assertSame(302, $response->getStatusCode());
        $user = User::where('source', 'federated')->first();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($user->fresh()->hasRole(SambaRole::ReferentNumerique->value));
    }

    #[Test]
    public function asserted_role_is_case_insensitive(): void
    {
        // Story 20.3 — AC2 : normalisation casse/espaces. Le rôle `technicien`
        // est seedé dans le setUp ; l'IdP asserte ` TECHNICIEN `.
        $emitted = $this->issueFederatedJwt(['sub' => 'ext-case', 'role' => ' TECHNICIEN ']);
        $response = $this->makeController()->callback($this->requestWithToken($emitted['token']));

        $this->assertSame(302, $response->getStatusCode());
        $user = User::where('source', 'federated')->first();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($user->fresh()->hasRole(SambaRole::Technicien->value));
    }

    #[Test]
    public function super_admin_is_applied_when_it_exists(): void
    {
        // Story 20.3 — AC5 / D-5 : super-admin n'est pas bloqué. S'il existe en
        // base, il est demandable comme tout autre rôle existant.
        Role::firstOrCreate(['name' => SambaRole::SuperAdmin->value, 'guard_name' => 'web']);

        $emitted = $this->issueFederatedJwt(['sub' => 'ext-god', 'role' => 'super-admin']);
        $response = $this->makeController()->callback($this->requestWithToken($emitted['token']));

        $this->assertSame(302, $response->getStatusCode());
        $user = User::where('source', 'federated')->first();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($user->fresh()->hasRole(SambaRole::SuperAdmin->value));
    }

    #[Test]
    public function unknown_role_returns_403_and_opens_no_session(): void
    {
        $rolesBefore = Role::count();

        $emitted = $this->issueFederatedJwt(['sub' => 'ext-bbb', 'role' => 'pirate-role']);

        try {
            $this->makeController()->callback($this->requestWithToken($emitted['token']));
            $this->fail('Expected 403 HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, User::where('source', 'federated')->count());
        $this->assertFalse(Auth::check());
        // Invariant D-2 : aucune création de rôle à la volée.
        $this->assertSame($rolesBefore, Role::count());
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
    public function anonymized_identity_is_refused_on_reconnection(): void
    {
        // Story 20.2 — AC11 / D-4 : anti-résurrection end-to-end. Un 1er login
        // crée l'identité ; on l'anonymise via le service ; une reconnexion
        // (nouveau jti) doit être refusée 403, sans réactivation ni recréation.
        $first = $this->issueFederatedJwt(['sub' => 'ext-anon-e2e', 'jti' => 'jti-anon-1']);
        $this->makeController()->callback($this->requestWithToken($first['token']));
        Auth::logout();

        $identity = ExternalIdentity::where('external_sub', 'ext-anon-e2e')->firstOrFail();
        (new \App\Auth\Federated\ExternalIdentityLifecycleService())->anonymize($identity);

        // Le sub clair a été réécrit (D-5) ; on rejoue le MÊME sub clair.
        $second = $this->issueFederatedJwt(['sub' => 'ext-anon-e2e', 'jti' => 'jti-anon-2']);
        try {
            $this->makeController()->callback($this->requestWithToken($second['token']));
            $this->fail('Expected 403 (identité anonymisée)');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Aucune identité « fraîche » recréée pour ce sub clair (anti-contournement).
        $this->assertSame(0, ExternalIdentity::withTrashed()->where('external_sub', 'ext-anon-e2e')->count());
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
    public function native_user_flow_is_unchanged_for_non_federated_sessions(): void
    {
        // Régression AC15 (Story 20.1), RECADRÉE par la Story 49.2.
        //
        // Ce que ce test verrouille n'a PAS changé : une session NON fédérée
        // suit son flux propre, et ce flux VALIDE l'existence de l'utilisateur
        // avant de le laisser passer (le contraire serait un trou d'authz).
        //
        // Ce qui a changé, c'est la SOURCE de cette validation : l'assertion
        // « `UserRepository::findByLogin` est appelé » n'a plus d'objet — le
        // guard n'a plus de `UserRepository` du tout (FR-R4, existence lue en
        // Postgres). L'assertion devient donc : le flux natif passe SI ET
        // SEULEMENT SI la ligne `users` existe.
        User::create([
            'login' => 'prof.dupont', 'fullname' => 'Prof Dupont',
            'role' => 'prof', 'is_active' => true,
        ]);

        $authService = Mockery::mock(AuthenticationService::class);
        $authService->shouldReceive('isAlreadyAuthenticated')->andReturn(true);
        $authService->shouldReceive('getCurrentUser')->andReturn('prof.dupont');
        $authService->shouldReceive('logout')->andReturnNull();

        $guard = new SambaEduAuthGuard($authService, new \App\Auth\Federated\ExternalIdentityLifecycleService());

        $request = Request::create('/app/dashboard', 'GET');
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        $called = false;
        $guard->handle($request, function () use (&$called) {
            $called = true;
            return new \Symfony\Component\HttpFoundation\Response('ok', 200);
        });

        $this->assertTrue($called, 'Le flux natif doit passer quand la ligne users existe');

        // Contre-épreuve : sans ligne `users`, le même flux refuse. Sans elle,
        // le test ci-dessus passerait aussi avec un guard qui ne vérifie RIEN.
        User::where('login', 'prof.dupont')->delete();

        $secondRequest = Request::create('/app/dashboard', 'GET');
        $secondSession = new Store('test2', new ArraySessionHandler(120));
        $secondSession->start();
        $secondRequest->setLaravelSession($secondSession);

        $calledAgain = false;
        $guard->handle($secondRequest, function () use (&$calledAgain) {
            $calledAgain = true;
            return new \Symfony\Component\HttpFoundation\Response('ok', 200);
        });

        $this->assertFalse($calledAgain, 'Un login absent de Postgres doit être refusé');
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
     * Guard configuré pour les sessions fédérées.
     *
     * Story 49.2 — le garde-fou « le LDAP ne doit JAMAIS être interrogé pour un
     * externe » était porté par un `shouldReceive('findByLogin')->never()` sur
     * un `UserRepository` mocké. Il est devenu STRUCTUREL : le guard n'a plus
     * de `UserRepository` en constructeur, il ne peut donc plus interroger
     * l'annuaire — ni pour un externe, ni pour personne. La vérification par
     * mock n'a plus de sujet ; c'est le test d'architecture du guard qui la
     * remplace ({@see \Tests\Feature\Auth\SambaEduAuthGuardPostgresTest}).
     */
    private function makeGuardSkippingLdap(): SambaEduAuthGuard
    {
        $user = User::where('source', 'federated')->first();

        $authService = Mockery::mock(AuthenticationService::class);
        $authService->shouldReceive('isAlreadyAuthenticated')->andReturn(true);
        $authService->shouldReceive('getCurrentUser')->andReturn($user?->login);
        $authService->shouldReceive('logout')->andReturnNull();

        return new SambaEduAuthGuard($authService, new \App\Auth\Federated\ExternalIdentityLifecycleService());
    }
}
