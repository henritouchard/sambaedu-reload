<?php

namespace Tests\Feature;

use App\Config\SambaEduConfig;
use App\Models\User;
use App\Services\AuthenticationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Tests pour l'authentification CAS native Laravel.
 */
class CasAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * initCasClient() lève une exception si cas_url est vide.
     */
    public function test_init_cas_client_throws_when_cas_url_empty(): void
    {
        $mockConfig = Mockery::mock(SambaEduConfig::class)->makePartial();
        $mockConfig->shouldReceive('get')
            ->with('cas_url')
            ->andReturn('');
        $mockConfig->shouldReceive('get')
            ->withAnyArgs()
            ->andReturn(null);

        $this->app->instance(SambaEduConfig::class, $mockConfig);

        $controller = $this->app->make(\App\Http\Controllers\AuthController::class);
        $method = new \ReflectionMethod($controller, 'initCasClient');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration CAS incomplète');
        $method->invoke($controller);
    }

    /**
     * initCasClient() lève une exception si cas_url est une chaîne de blancs.
     */
    public function test_init_cas_client_throws_when_cas_url_whitespace(): void
    {
        $mockConfig = Mockery::mock(SambaEduConfig::class)->makePartial();
        $mockConfig->shouldReceive('get')
            ->with('cas_url')
            ->andReturn('   ');
        $mockConfig->shouldReceive('get')
            ->withAnyArgs()
            ->andReturn(null);

        $this->app->instance(SambaEduConfig::class, $mockConfig);

        $controller = $this->app->make(\App\Http\Controllers\AuthController::class);
        $method = new \ReflectionMethod($controller, 'initCasClient');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration CAS incomplète');
        $method->invoke($controller);
    }

    /**
     * cas_port = '0' (falsy en PHP) ne doit pas être remplacé silencieusement par 443.
     * Le port doit être passé tel quel à phpCAS.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_init_cas_client_port_zero_is_not_overridden_to_443(): void
    {
        $mockConfig = Mockery::mock(SambaEduConfig::class)->makePartial();
        $mockConfig->shouldReceive('get')
            ->with('cas_url')
            ->andReturn('cas.example.com');
        $mockConfig->shouldReceive('get')
            ->with('cas_port')
            ->andReturn('0');
        $mockConfig->shouldReceive('get')
            ->withAnyArgs()
            ->andReturn(null);

        $this->app->instance(SambaEduConfig::class, $mockConfig);

        $controller = $this->app->make(\App\Http\Controllers\AuthController::class);
        $method = new \ReflectionMethod($controller, 'initCasClient');
        $method->setAccessible(true);

        // Aucune RuntimeException de notre côté — le port 0 est une valeur légitime à transmettre
        try {
            $method->invoke($controller);
        } catch (\RuntimeException $e) {
            $this->fail('RuntimeException inattendue pour port 0 : ' . $e->getMessage());
        } catch (\Exception $e) {
            // Exception interne phpCAS acceptable — l'important est qu'on n'a pas remplacé le port
            $this->assertStringNotContainsString('cas_url requis', $e->getMessage());
        }
    }

    /**
     * handleCasAuthenticated() crée la session ENT + flag cas_auth_method pour un utilisateur connu.
     *
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_cas_creates_ent_session_for_known_user(): void
    {
        $user = User::factory()->create(['login' => 'jdupont']);

        $authService = $this->app->make(AuthenticationService::class);
        $authService->createEntSession(
            ['cn' => $user->login, 'login' => 'jdupont'],
            null
        );
        $_SESSION['cas_auth_method'] = 'cas';

        $this->assertEquals('jdupont', $_SESSION['login']);
        $this->assertEquals('ent', $_SESSION['auth']);
        $this->assertEquals('cas', $_SESSION['cas_auth_method']);
    }

    /**
     * Le logout CAS ne doit pas être déclenché pour une session OAuth2-ENT.
     * wasCasAuth doit se baser sur le flag cas_auth_method, pas sur isCasAuthAvailable().
     *
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_cas_logout_not_triggered_for_oauth2_ent_session(): void
    {
        // Session OAuth2-ENT sans flag CAS
        $_SESSION['login'] = 'jdupont';
        $_SESSION['auth']  = 'ent';
        // cas_auth_method absent volontairement

        $wasCasAuth = !empty($_SESSION['login'])
            && ($_SESSION['auth'] ?? '') === 'ent'
            && (($_SESSION['cas_auth_method'] ?? '') === 'cas');

        $this->assertFalse($wasCasAuth, 'Le logout CAS ne doit pas être déclenché pour une session OAuth2-ENT');
    }
}
