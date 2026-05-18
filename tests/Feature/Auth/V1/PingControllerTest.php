<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\V1;

use App\Auth\V1\Models\WorkstationJwtRevocation;
use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.10 — AC5.3 / AC7.2.
 *
 * Tests Feature `GET /api/v1/agent/ping`.
 *
 *  - JWT valide → 200 + payload attendu
 *  - Sans Authorization → 401 jwt.missing
 *  - JWT expiré → 401 jwt.expired
 *  - JWT révoqué (DB+cache) → 401 jwt.revoked
 *  - JWT tier=controlhub → 401 jwt.wrong_tier
 */
class PingControllerTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
        Cache::store('array')->flush();
    }

    #[Test]
    public function valid_jwt_returns_200_with_payload(): void
    {
        $emitted = $this->issueTestJwt(['sub' => 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa']);

        $this->getJson('/api/v1/agent/ping', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'workstation_uuid',
                'server_time',
                'api_version',
                'se4fs_name',
            ])
            ->assertJson([
                'success' => true,
                'workstation_uuid' => 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
                'api_version' => 'v1',
            ]);
    }

    #[Test]
    public function missing_authorization_returns_401_missing(): void
    {
        $this->getJson('/api/v1/agent/ping')
            ->assertStatus(401)
            ->assertJson([
                'error' => 'unauthorized',
                'code' => JwtErrorCodes::JWT_MISSING,
            ]);
    }

    #[Test]
    public function expired_jwt_returns_401_expired(): void
    {
        $emitted = $this->issueTestJwt([
            'iat' => Carbon::now()->subDays(2)->getTimestamp(),
            'exp' => Carbon::now()->subDay()->getTimestamp(),
        ]);

        $this->getJson('/api/v1/agent/ping', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_EXPIRED]);
    }

    #[Test]
    public function wrong_tier_returns_401_wrong_tier(): void
    {
        $emitted = $this->issueTestJwt(['tier' => 'controlhub']);

        $this->getJson('/api/v1/agent/ping', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_WRONG_TIER]);
    }

    #[Test]
    public function revoked_jwt_returns_401_revoked(): void
    {
        $jti = (string) Str::uuid();
        $emitted = $this->issueTestJwt(['jti' => $jti]);

        WorkstationJwtRevocation::query()->create([
            'id' => (string) Str::uuid(),
            'jti' => $jti,
            'workstation_uuid' => $emitted['sub'],
            'revoked_at' => Carbon::now(),
            'reason' => 'lost_device',
            'expires_at' => Carbon::now()->addDay(),
        ]);

        $this->getJson('/api/v1/agent/ping', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_REVOKED]);
    }

    #[Test]
    public function malformed_jwt_returns_401(): void
    {
        $this->getJson('/api/v1/agent/ping', ['Authorization' => 'Bearer not-a-jwt'])
            ->assertStatus(401);
    }
}
