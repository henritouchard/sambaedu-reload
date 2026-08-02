<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Config\SambaEduConfig;
use App\Enums\SambaRole;
use App\Http\Controllers\AuthController;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\AuthenticationService;
use App\Types\User as AdUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Story 49.2 — AC2 : le filet « œuf/poule » vit dans la CÉRÉMONIE DE LOGIN.
 *
 * Avant 49.2, l'auto-provisioning était dans le guard de session : toute ligne
 * `users` manquante y déclenchait un lookup annuaire, à chaque requête. Le
 * déplacer au login le ramène à une occurrence par session, dans une cérémonie
 * qui est DÉJÀ un contact AD (le bind vient de réussir).
 *
 * Le scénario protégé est réel : sur une installation fraîche, `admin` se
 * connecte avant la toute première synchronisation. Sans ce filet, le guard —
 * désormais Postgres-only — le refuserait indéfiniment.
 */
class LoginAutoProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Contrôleur réel, dépendances mockées : `AuthenticationService` (bind LDAP)
     * et `UserRepository` (lookup annuaire).
     */
    private function makeController(
        AuthenticationService $authService,
        UserRepository $userRepository,
    ): AuthController {
        return new AuthController(
            $authService,
            app(SambaEduConfig::class),
            $userRepository,
        );
    }

    private function successfulAuthService(): AuthenticationService
    {
        $mock = Mockery::mock(AuthenticationService::class);
        $mock->shouldReceive('authenticate')->andReturn([
            'success' => true,
            'login' => 'peu-importe',
            'code' => 'SUCCESS',
        ]);

        return $mock;
    }

    private function loginRequest(string $login): Request
    {
        $request = Request::create('/login', 'POST', [
            'login' => $login,
            'password' => 'secret',
        ]);
        $session = new Store('login-test-' . uniqid(), new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        $this->app['session']->setDefaultDriver('array');

        return $request;
    }

    private function adDto(string $login, bool $active = true, string $role = 'prof'): AdUser
    {
        return new AdUser(
            login: $login,
            fullname: ucfirst($login),
            firstname: 'Prénom',
            lastname: 'Nom',
            email: "{$login}@etab.fr",
            isActive: $active,
            dn: "CN={$login},OU=Profs,DC=etab,DC=local",
            role: $role,
        );
    }

    #[Test]
    public function a_missing_users_row_is_created_at_login(): void
    {
        $this->assertSame(0, User::count());

        $repo = Mockery::mock(UserRepository::class);
        $repo->shouldReceive('findByLogin')->once()->with('prof.neuf')
            ->andReturn($this->adDto('prof.neuf'));

        $this->makeController($this->successfulAuthService(), $repo)
            ->authenticate($this->loginRequest('prof.neuf'));

        $user = User::findByLogin('prof.neuf');
        $this->assertNotNull($user, 'La cérémonie de login doit créer la ligne manquante');
        $this->assertSame('prof', $user->role);
        $this->assertSame('Prof.neuf', $user->fullname);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->ad_synced_at);
    }

    #[Test]
    public function an_already_synchronised_user_triggers_no_directory_lookup(): void
    {
        // Cas nominal : 100 % du parc en régime établi. Le `never()` est
        // l'assertion centrale — c'est tout le bénéfice de la bascule.
        User::create(['login' => 'prof.connu', 'role' => 'prof', 'is_active' => true]);

        $repo = Mockery::mock(UserRepository::class);
        $repo->shouldReceive('findByLogin')->never();

        $this->makeController($this->successfulAuthService(), $repo)
            ->authenticate($this->loginRequest('prof.connu'));

        $this->assertSame(1, User::count());
    }

    #[Test]
    public function is_active_mirrors_the_directory_and_is_never_true_by_default(): void
    {
        // L'ancien `ensureEloquentUser` du guard écrivait `'is_active' => true`
        // EN DUR. Recopier ce littéral aurait ressuscité en base un compte
        // désactivé dans l'annuaire — le piège symétrique de celui que 49.3
        // vient de fermer (`is_active` est un MIROIR, jamais une valeur inventée).
        $repo = Mockery::mock(UserRepository::class);
        $repo->shouldReceive('findByLogin')->once()
            ->andReturn($this->adDto('compte.desactive', active: false));

        $this->makeController($this->successfulAuthService(), $repo)
            ->authenticate($this->loginRequest('compte.desactive'));

        $user = User::findByLogin('compte.desactive');
        $this->assertNotNull($user);
        $this->assertFalse(
            $user->is_active,
            'Un compte désactivé dans l\'annuaire ne doit pas être ressuscité par son login',
        );
    }

    #[Test]
    public function the_protected_admin_receives_its_rights_immediately(): void
    {
        // Fresh install : `admin` est dans SYSTEM_ACCOUNTS, il ne sera JAMAIS
        // servi par le balayage de sync. S'il n'obtient pas ses droits ici, il
        // ne les obtient nulle part.
        (new PermissionSeeder())->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $repo = Mockery::mock(UserRepository::class);
        $repo->shouldReceive('findByLogin')->once()
            ->andReturn($this->adDto(User::PROTECTED_ADMIN_LOGIN, role: 'autre'));

        $this->makeController($this->successfulAuthService(), $repo)
            ->authenticate($this->loginRequest(User::PROTECTED_ADMIN_LOGIN));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::findByLogin(User::PROTECTED_ADMIN_LOGIN);
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole(SambaRole::SuperAdmin->value));
    }

    #[Test]
    public function a_login_absent_from_the_directory_creates_nothing(): void
    {
        $repo = Mockery::mock(UserRepository::class);
        $repo->shouldReceive('findByLogin')->once()->andReturn(null);

        $this->makeController($this->successfulAuthService(), $repo)
            ->authenticate($this->loginRequest('inconnu.partout'));

        $this->assertSame(0, User::count());
    }

    #[Test]
    public function a_directory_failure_never_breaks_a_successful_authentication(): void
    {
        // Un échec de provisioning ne doit pas transformer une authentification
        // réussie en erreur : on journalise, et le guard tranchera à la requête
        // suivante.
        $repo = Mockery::mock(UserRepository::class);
        $repo->shouldReceive('findByLogin')->once()->andThrow(new \RuntimeException('LDAP down'));

        $response = $this->makeController($this->successfulAuthService(), $repo)
            ->authenticate($this->loginRequest('malchanceux'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, User::count());
    }

    #[Test]
    public function a_failed_authentication_provisions_nothing(): void
    {
        $authService = Mockery::mock(AuthenticationService::class);
        $authService->shouldReceive('authenticate')->andReturn([
            'success' => false,
            'error' => 'Nom d\'utilisateur ou mot de passe incorrects',
            'code' => 'INVALID_CREDENTIALS',
        ]);

        $repo = Mockery::mock(UserRepository::class);
        $repo->shouldReceive('findByLogin')->never();

        $this->makeController($authService, $repo)
            ->authenticate($this->loginRequest('usurpateur'));

        $this->assertSame(0, User::count());
    }

    // ========================================================================
    // Correction de review — le filet vaut pour les TROIS points d'entrée
    // ========================================================================

    /**
     * `handleCasAuthenticated()` et `entCallback()` créent une session sans
     * passer par `authenticate()` : avant cette correction, le filet œuf/poule
     * ne les couvrait pas. Ils partagent
     * `ensureEloquentUserForCurrentSession()`, qui provisionne le login QUE LE
     * GUARD IRA CHERCHER — `getCurrentUser()`, le même oracle — plutôt que de
     * rejouer la composition du login de session (CAS 3.0 retient un `cn`, ENT
     * le login AD local : deux règles différentes, une seule vérité).
     */
    #[Test]
    public function the_shared_helper_provisions_the_login_the_guard_will_look_up(): void
    {
        $authService = Mockery::mock(AuthenticationService::class);
        // La session porte le login AD local, PAS le login du fournisseur.
        $authService->shouldReceive('getCurrentUser')->andReturn('prof.local');

        $repo = Mockery::mock(UserRepository::class);
        $repo->shouldReceive('findByLogin')->once()->with('prof.local')
            ->andReturn($this->adDto('prof.local'));

        $controller = $this->makeController($authService, $repo);

        $method = new \ReflectionMethod($controller, 'ensureEloquentUserForCurrentSession');
        $method->invoke($controller);

        $user = User::findByLogin('prof.local');
        self::assertNotNull($user, 'la ligne doit être créée pour le login de SESSION');
        self::assertTrue($user->is_active);
    }

    #[Test]
    public function the_shared_helper_is_a_no_op_without_a_session_login(): void
    {
        $authService = Mockery::mock(AuthenticationService::class);
        $authService->shouldReceive('getCurrentUser')->andReturn(null);

        $repo = Mockery::mock(UserRepository::class);
        $repo->shouldReceive('findByLogin')->never();

        $controller = $this->makeController($authService, $repo);
        (new \ReflectionMethod($controller, 'ensureEloquentUserForCurrentSession'))->invoke($controller);

        self::assertSame(0, User::count());
    }

    /**
     * Verrou de CÂBLAGE. Le trou corrigé ici n'était pas une erreur de logique
     * mais un oubli de branchement : les deux cérémonies existaient, le filet
     * aussi, personne ne les avait reliés. Un test comportemental sur CAS/ENT
     * exigerait de simuler phpCAS et un provider OAuth2 ; ce verrou-ci coûte
     * trois lignes et échoue si le branchement disparaît.
     */
    #[Test]
    public function both_sso_ceremonies_wire_the_provisioning_helper(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/AuthController.php'));

        foreach (['handleCasAuthenticated', 'entCallback'] as $ceremony) {
            $start = strpos($source, "function {$ceremony}(");
            self::assertNotFalse($start, "cérémonie {$ceremony} introuvable");

            // Fenêtre bornée à la cérémonie : jusqu'au prochain `private`/`public`.
            $next = strpos($source, "\n    private function ", $start + 1);
            $nextPublic = strpos($source, "\n    public function ", $start + 1);
            if ($nextPublic !== false && ($next === false || $nextPublic < $next)) {
                $next = $nextPublic;
            }
            $body = substr($source, $start, ($next === false ? strlen($source) : $next) - $start);

            self::assertStringContainsString(
                'ensureEloquentUserForCurrentSession()',
                $body,
                "{$ceremony} doit provisionner la ligne `users` : sans elle, un compte AD "
                    . 'légitime pas encore synchronisé réussit son SSO puis boucle sur le refus du guard'
            );
        }
    }
}
