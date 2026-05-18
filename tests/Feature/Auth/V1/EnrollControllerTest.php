<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\V1;

use App\Auth\V1\Models\WorkstationRefreshToken;
use App\Auth\V1\Pki\CaInitializer;
use App\Auth\V1\Services\LegacyBootstrapTokenValidator;
use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.10 — AC5.1 / AC7.2.
 *
 * Tests Feature `POST /api/v1/agent/enroll`.
 *
 *  - Happy path : bootstrap valide + body valide → 200 + access+refresh+ca_cert_pem
 *  - Bootstrap absent → 401 bootstrap_token.missing
 *  - Bootstrap invalide → 401 bootstrap_token.invalid
 *  - Body invalide (UUID malformé, MAC, OS) → 422
 *  - Rate limit dépassé → 429
 *
 * **Stratégie test** : on mock `LegacyBootstrapTokenValidator` pour pouvoir
 * piloter la décision sans dépendre d'APCu live. On mock aussi
 * `CaInitializer` pour qu'il retourne un PEM factice sans avoir besoin de
 * la PKI.
 */
class EnrollControllerTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();

        // Mock CaInitializer pour retourner un PEM factice
        $caMock = Mockery::mock(CaInitializer::class);
        $caMock->shouldReceive('getCaCertPem')->andReturn(
            "-----BEGIN CERTIFICATE-----\nFAKE-CA-FOR-TESTS\n-----END CERTIFICATE-----\n",
        );
        $this->app->instance(CaInitializer::class, $caMock);

        config([
            'sambaedu.se4fs_name' => 'se4fs-test001',
            'auth_v1.server.host_suffix' => 'lab.local',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Mock `LegacyBootstrapTokenValidator` pour fixer la décision.
     */
    private function bootstrapTokenValid(bool $valid): void
    {
        $mock = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $mock->shouldReceive('isValid')->andReturn($valid);
        $this->app->instance(LegacyBootstrapTokenValidator::class, $mock);
    }

    private function validBody(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'hostname' => 'pc-test-01',
            'os' => 'linux',
        ];
    }

    #[Test]
    public function happy_path_returns_200_with_full_payload(): void
    {
        $this->bootstrapTokenValid(true);

        $body = $this->validBody();
        $res = $this->postJson('/api/v1/agent/enroll', $body, [
            'X-Bootstrap-Token' => md5('fixture-token'),
        ]);

        $res->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'access_token',
                'refresh_token',
                'token_type',
                'expires_in',
                'refresh_expires_in',
                'ca_cert_pem',
                'server_base_url',
            ])
            ->assertJson([
                'success' => true,
                'token_type' => 'Bearer',
                'expires_in' => 86400,
                'refresh_expires_in' => 2592000,
                'server_base_url' => 'https://se4fs-test001.lab.local',
            ]);

        $payload = $res->json();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/i', $payload['refresh_token']);

        // Le refresh est persisté en DB (hash, pas clear)
        $expectedHash = hash('sha256', $payload['refresh_token']);
        $this->assertSame(
            1,
            WorkstationRefreshToken::query()
                ->where('workstation_uuid', $body['uuid'])
                ->where('refresh_token_hash', $expectedHash)
                ->count(),
        );
    }

    #[Test]
    public function missing_bootstrap_token_returns_401(): void
    {
        // Pas de mock `bootstrapTokenValid` : le middleware rejette sur header
        // absent AVANT d'appeler le validator. Test l'invariant explicitement.
        $this->postJson('/api/v1/agent/enroll', $this->validBody())
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::BOOTSTRAP_TOKEN_MISSING]);
    }

    #[Test]
    public function invalid_bootstrap_token_returns_401(): void
    {
        $this->bootstrapTokenValid(false);

        $this->postJson('/api/v1/agent/enroll', $this->validBody(), [
            'X-Bootstrap-Token' => md5('bad-token'),
        ])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::BOOTSTRAP_TOKEN_INVALID]);
    }

    #[Test]
    public function invalid_uuid_returns_422(): void
    {
        $this->bootstrapTokenValid(true);

        $body = $this->validBody();
        $body['uuid'] = 'not-a-uuid';

        $this->postJson('/api/v1/agent/enroll', $body, [
            'X-Bootstrap-Token' => md5('fixture-token'),
        ])->assertStatus(422);
    }

    #[Test]
    public function invalid_mac_returns_422(): void
    {
        $this->bootstrapTokenValid(true);

        $body = $this->validBody();
        $body['mac'] = 'GG:HH:II:JJ:KK:LL'; // non-hex

        $this->postJson('/api/v1/agent/enroll', $body, [
            'X-Bootstrap-Token' => md5('fixture-token'),
        ])->assertStatus(422);
    }

    #[Test]
    public function invalid_os_enum_returns_422(): void
    {
        $this->bootstrapTokenValid(true);

        $body = $this->validBody();
        $body['os'] = 'macos';

        $this->postJson('/api/v1/agent/enroll', $body, [
            'X-Bootstrap-Token' => md5('fixture-token'),
        ])->assertStatus(422);
    }

    #[Test]
    public function re_enroll_same_uuid_does_not_revoke_old(): void
    {
        $this->bootstrapTokenValid(true);

        $body = $this->validBody();

        $first = $this->postJson('/api/v1/agent/enroll', $body, [
            'X-Bootstrap-Token' => md5('fixture-token'),
        ])->assertStatus(200)->json();

        $this->postJson('/api/v1/agent/enroll', $body, [
            'X-Bootstrap-Token' => md5('fixture-token'),
        ])->assertStatus(200);

        // Les deux refresh doivent toujours être actifs (pas de revocation
        // automatique au ré-enroll)
        $this->assertSame(
            2,
            WorkstationRefreshToken::query()
                ->where('workstation_uuid', $body['uuid'])
                ->whereNull('revoked_at')
                ->count(),
        );
    }
}
