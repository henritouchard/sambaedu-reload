<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\V1;

use App\Auth\V1\Models\WorkstationMigrationAttempt;
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
            // Story 16.11 — Loopback 127.0.0.1 doit passer le LAN whitelist
            // pour les tests Feature.
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Mock `LegacyBootstrapTokenValidator` pour fixer la décision.
     *
     * Le mock accepte 1 ou 2 arguments (story 16.10 et 16.11) — par défaut
     * `isValid` retourne `$valid` pour toute combinaison d'arguments.
     */
    private function bootstrapTokenValid(bool $valid): void
    {
        $mock = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $mock->shouldReceive('isValid')->andReturn($valid);
        // checkMismatch ne sera pas appelé si isValid retourne true ; sinon
        // il retourne false par défaut (= comportement legacy 16.10).
        $mock->shouldReceive('checkMismatch')->andReturn(false);
        $this->app->instance(LegacyBootstrapTokenValidator::class, $mock);
    }

    /**
     * Mock du validator avec discrimination explicite mismatch vs invalid.
     *
     * @param array{is_valid: bool, mismatch?: bool} $opts
     */
    private function bootstrapTokenWithMismatch(array $opts): void
    {
        $mock = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $mock->shouldReceive('isValid')->andReturn($opts['is_valid']);
        $mock->shouldReceive('checkMismatch')->andReturn($opts['mismatch'] ?? false);
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

    // ====================================================================
    // Story 16.11 — couple token↔UUID + LAN whitelist (AC3.1 / AC3.2)
    // ====================================================================

    #[Test]
    public function it_rejects_enroll_when_uuid_does_not_match_bootstrap_context(): void
    {
        // Token valide en APCu mais uuid déclaré ≠ uuid du contexte.
        $this->bootstrapTokenWithMismatch(['is_valid' => false, 'mismatch' => true]);

        $body = $this->validBody();
        $res = $this->postJson('/api/v1/agent/enroll', $body, [
            'X-Bootstrap-Token' => md5('mismatch-test-token'),
        ]);

        $res->assertStatus(401)
            ->assertJson(['code' => \App\Auth\V1\Support\JwtErrorCodes::BOOTSTRAP_TOKEN_UUID_MISMATCH]);

        // Aucun refresh ne doit avoir été créé.
        $this->assertSame(
            0,
            WorkstationRefreshToken::query()->where('workstation_uuid', $body['uuid'])->count(),
        );
    }

    #[Test]
    public function it_rejects_enroll_from_non_lan_ip(): void
    {
        // Restreint à 192.168.99.0/24 → testserver 127.0.0.1 hors LAN.
        config([
            'auth_v1.bootstrap.allowed_subnets' => '192.168.99.0/24',
        ]);

        $res = $this->postJson('/api/v1/agent/enroll', $this->validBody(), [
            'X-Bootstrap-Token' => md5('valid-but-non-lan'),
        ]);

        $res->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'forbidden',
                'code' => 'bootstrap.not_lan',
            ]);
    }

    #[Test]
    public function refresh_route_is_not_lan_restricted(): void
    {
        // Restreint enroll à un subnet impossible → enroll devrait être 403,
        // mais /refresh ne doit PAS être impacté (D1 — pas de lan-only).
        // On vérifie ici juste que /refresh retourne une réponse non-403-lan
        // (le 401 refresh.missing est attendu sans body refresh).
        config([
            'auth_v1.bootstrap.allowed_subnets' => '192.168.99.0/24',
        ]);

        $res = $this->postJson('/api/v1/agent/refresh', []);

        $this->assertNotSame(403, $res->getStatusCode());
    }

    // ====================================================================
    // Q2 (Opus-B + Opus-D) — `failed` attempts insérés sur rejets
    // ====================================================================

    #[Test]
    public function uuid_mismatch_inserts_a_failed_attempt(): void
    {
        $this->bootstrapTokenWithMismatch(['is_valid' => false, 'mismatch' => true]);

        $body = $this->validBody();
        $this->postJson('/api/v1/agent/enroll', $body, [
            'X-Bootstrap-Token' => md5('mismatch-test'),
        ])->assertStatus(401);

        $attempt = WorkstationMigrationAttempt::query()
            ->where('error_code', JwtErrorCodes::BOOTSTRAP_TOKEN_UUID_MISMATCH)
            ->first();
        $this->assertNotNull($attempt, 'A failed attempt must be inserted on uuid_mismatch');
        $this->assertSame(WorkstationMigrationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame($body['uuid'], $attempt->workstation_uuid);
    }

    #[Test]
    public function missing_bootstrap_token_inserts_a_failed_attempt(): void
    {
        $body = $this->validBody();
        $this->postJson('/api/v1/agent/enroll', $body, [])->assertStatus(401);

        $attempt = WorkstationMigrationAttempt::query()
            ->where('error_code', JwtErrorCodes::BOOTSTRAP_TOKEN_MISSING)
            ->first();
        $this->assertNotNull($attempt, 'A failed attempt must be inserted on missing token');
        $this->assertSame(WorkstationMigrationAttempt::STATUS_FAILED, $attempt->status);
    }

    #[Test]
    public function non_lan_request_inserts_a_failed_attempt(): void
    {
        config([
            'auth_v1.bootstrap.allowed_subnets' => '192.168.99.0/24',
        ]);

        $this->postJson('/api/v1/agent/enroll', $this->validBody(), [
            'X-Bootstrap-Token' => md5('whatever'),
        ])->assertStatus(403);

        $attempt = WorkstationMigrationAttempt::query()
            ->where('error_code', JwtErrorCodes::BOOTSTRAP_NOT_LAN)
            ->first();
        $this->assertNotNull($attempt, 'A failed attempt must be inserted on LAN block');
        $this->assertSame(WorkstationMigrationAttempt::STATUS_FAILED, $attempt->status);
    }
}
