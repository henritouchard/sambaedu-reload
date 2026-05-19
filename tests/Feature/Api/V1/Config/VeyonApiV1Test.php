<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Config;

use App\Auth\V1\Support\JwtErrorCodes;
use App\Gpo\Services\ReadUserManager;
use App\Gpo\Services\VeyonConfigGenerator;
use App\Dto\AppCustomization\AppContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Story 16.13 — AC5.2 (`/api/v1/workstation-config/veyon`).
 */
class VeyonApiV1Test extends TestCase
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

        // Mock pour neutraliser les side effects AD + lecture FS du
        // VeyonConfigGenerator (qui nécessite des clés OpenSSL + LDAP).
        $generatorMock = Mockery::mock(VeyonConfigGenerator::class);
        $generatorMock->shouldReceive('generate')
            ->andReturnUsing(fn (AppContext $ctx, string $pwd) => [
                'LDAP' => ['BindPassword' => $pwd, 'BindDN' => 'cn=read.user,dc=test'],
                'Network' => ['UseRandomPort' => false],
            ]);
        $this->app->instance(VeyonConfigGenerator::class, $generatorMock);

        $readUserMock = Mockery::mock(ReadUserManager::class);
        $readUserMock->shouldReceive('ensurePassword')->andReturn('fake-pwd');
        $this->app->instance(ReadUserManager::class, $readUserMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function missing_authorization_returns_401_missing(): void
    {
        $this->getJson('/api/v1/workstation-config/veyon')
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

        $this->getJson('/api/v1/workstation-config/veyon', ['Authorization' => 'Bearer ' . $emitted['token']])
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

        $this->getJson('/api/v1/workstation-config/veyon', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_WRONG_TIER]);
    }

    #[Test]
    public function unknown_workstation_returns_404(): void
    {
        $emitted = $this->issueTestJwt(['sub' => '99999999-9999-4999-9999-999999999999']);

        $this->getJson('/api/v1/workstation-config/veyon', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(404);
    }

    #[Test]
    public function happy_path_returns_200_json_with_bind_password(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->getJson(
            '/api/v1/workstation-config/veyon?user=jdoe',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        $response->assertOk();
        $contentType = (string) $response->headers->get('Content-Type');
        $this->assertStringContainsString('application/json', $contentType);

        $payload = json_decode($response->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('LDAP', $payload);
        $this->assertSame('fake-pwd', $payload['LDAP']['BindPassword'] ?? null);
    }

    #[Test]
    public function licence_subaction_returns_application_octet_stream(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->getJson(
            '/api/v1/workstation-config/veyon?licence=1',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        $response->assertOk();
        // Fichier licence absent → 200 body vide + Content-Type
        // application/octet-stream (iso `serveLicence` fallback).
        $this->assertSame(
            'application/octet-stream',
            (string) $response->headers->get('Content-Type'),
        );
    }
}
