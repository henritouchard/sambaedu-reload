<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Config;

use App\Auth\V1\Support\JwtErrorCodes;
use App\Gpo\Services\ApplicationLoggerService;
use App\Gpo\Services\ApplicationScriptsAssembler;
use App\Gpo\Services\ApplicationScriptsGenerator;
use App\Gpo\Services\ApplicationTemplatesScanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Story 16.13 — AC5.2 (`/api/v1/workstation-config/applications-scripts`).
 *
 * Service `ApplicationScriptsGenerator` mocké pour éviter side effects
 * AD + APCu (la chaîne complète est testée en 16.7).
 */
class ApplicationsScriptsApiV1Test extends TestCase
{
    use IssuesWorkstationJwt;
    use SeedsWorkstationConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
        $this->seedWorkstationContextSchemas();
        Cache::store('array')->flush();

        // Mock pour neutraliser AD + APCu.
        $genMock = Mockery::mock(ApplicationScriptsGenerator::class);
        $genMock->shouldReceive('resolveInfo')->andReturn([
            'id' => str_repeat('a', 32),
            'action' => 'logon',
            'remote' => false,
            'context' => '',
            'application' => '',
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'post01'],
            'salle' => 'salle-test',
            'list' => ['jdoe', 'post01', 'salle-test'],
            'list_u' => ['jdoe'],
            'list_ue' => ['jdoe'],
            'list_m' => ['post01', 'salle-test'],
            'liste_applications' => [],
            'admin' => 0,
            'os' => 'linux',
            'time' => time(),
            'parcs' => [],
            'uuid' => $this->seededWorkstationUuid,
            'interpreter' => 'bash',
            'speed' => 0,
            'userprofile' => '',
        ]);
        $this->app->instance(ApplicationScriptsGenerator::class, $genMock);

        $loggerMock = Mockery::mock(ApplicationLoggerService::class);
        $loggerMock->shouldReceive('logScripts')->andReturn(true);
        $this->app->instance(ApplicationLoggerService::class, $loggerMock);

        $scannerMock = Mockery::mock(ApplicationTemplatesScanner::class);
        $scannerMock->shouldReceive('scan')->andReturn([]);
        $this->app->instance(ApplicationTemplatesScanner::class, $scannerMock);

        $assemblerMock = Mockery::mock(ApplicationScriptsAssembler::class);
        $assemblerMock->shouldReceive('assemble')->andReturn([
            'bash' => "#!/bin/bash\necho hello\n",
            'cmd' => "@echo off\r\necho hello\r\n",
        ]);
        $this->app->instance(ApplicationScriptsAssembler::class, $assemblerMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function missing_authorization_returns_401_missing(): void
    {
        $this->getJson('/api/v1/workstation-config/applications-scripts')
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_MISSING]);
    }

    #[Test]
    public function expired_jwt_returns_401_expired(): void
    {
        $emitted = $this->issueTestJwt([
            'sub' => $this->seededWorkstationUuid,
            'iat' => Carbon::now()->subDays(2)->getTimestamp(),
            'exp' => Carbon::now()->subDay()->getTimestamp(),
        ]);

        $this->getJson('/api/v1/workstation-config/applications-scripts', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_EXPIRED]);
    }

    #[Test]
    public function wrong_tier_returns_401_wrong_tier(): void
    {
        $emitted = $this->issueTestJwt([
            'sub' => $this->seededWorkstationUuid,
            'tier' => 'controlhub',
        ]);

        $this->getJson('/api/v1/workstation-config/applications-scripts', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_WRONG_TIER]);
    }

    #[Test]
    public function unknown_workstation_returns_404(): void
    {
        $emitted = $this->issueTestJwt(['sub' => '99999999-9999-4999-9999-999999999999']);

        $this->getJson(
            '/api/v1/workstation-config/applications-scripts?machine=post01&action=logon&os=linux',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(404);
    }

    #[Test]
    public function happy_path_linux_returns_200_bash_script(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->getJson(
            '/api/v1/workstation-config/applications-scripts?machine=post01&action=logon&os=linux&user=jdoe',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        $response->assertOk();
        $this->assertStringContainsString(
            'text/plain',
            (string) $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString(
            'utf-8',
            (string) $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString('echo hello', (string) $response->getContent());
    }

    #[Test]
    public function happy_path_windows_returns_200_cp1252_charset(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->getJson(
            '/api/v1/workstation-config/applications-scripts?machine=post01&action=logon&os=windows&user=jdoe',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        $response->assertOk();
        $this->assertStringContainsString(
            'cp1252',
            (string) $response->headers->get('Content-Type'),
        );
    }

    #[Test]
    public function invalid_os_returns_400(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $this->getJson(
            '/api/v1/workstation-config/applications-scripts?machine=post01&os=osx&action=logon',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(400);
    }

    #[Test]
    public function invalid_user_regex_returns_400(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $this->getJson(
            '/api/v1/workstation-config/applications-scripts?machine=post01&os=linux&action=logon&user=' . urlencode('with space!'),
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(400);
    }
}
