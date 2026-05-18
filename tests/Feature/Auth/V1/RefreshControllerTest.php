<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\V1;

use App\Auth\V1\Models\WorkstationRefreshToken;
use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.10 — AC5.2 / AC7.2.
 *
 * Tests Feature `POST /api/v1/agent/refresh`.
 *
 *  - Happy path : refresh valide → 200 + nouvelle paire + ancien révoqué DB
 *  - Inconnu → 401 refresh.invalid
 *  - Expiré → 401 refresh.expired
 *  - Replay → 401 refresh.replay_detected + cascade revocation tous les
 *    refresh actifs du workstation_uuid
 */
class RefreshControllerTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
    }

    #[Test]
    public function happy_path_rotates_refresh_and_revokes_old(): void
    {
        $clear = bin2hex(random_bytes(32));
        $hash = hash('sha256', $clear);
        $workstation = (string) Str::uuid();
        $old = WorkstationRefreshToken::factory()
            ->forWorkstation($workstation)
            ->withHash($hash)
            ->create();

        $res = $this->postJson('/api/v1/agent/refresh', ['refresh_token' => $clear]);
        $res->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'access_token',
                'refresh_token',
                'token_type',
                'expires_in',
                'refresh_expires_in',
            ])
            ->assertJson([
                'success' => true,
                'token_type' => 'Bearer',
            ]);

        $payload = $res->json();
        $this->assertNotSame($clear, $payload['refresh_token']);

        $old->refresh();
        $this->assertNotNull($old->revoked_at);
        $this->assertSame('refresh_rotation', $old->revocation_reason);

        // 1 nouveau actif sur workstation
        $this->assertSame(
            1,
            WorkstationRefreshToken::query()
                ->where('workstation_uuid', $workstation)
                ->whereNull('revoked_at')
                ->count(),
        );
    }

    #[Test]
    public function unknown_refresh_returns_401_invalid(): void
    {
        $clear = bin2hex(random_bytes(32));
        $this->postJson('/api/v1/agent/refresh', ['refresh_token' => $clear])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::REFRESH_INVALID]);
    }

    #[Test]
    public function expired_refresh_returns_401_expired(): void
    {
        $clear = bin2hex(random_bytes(32));
        $hash = hash('sha256', $clear);
        WorkstationRefreshToken::factory()->withHash($hash)->expired()->create();

        $this->postJson('/api/v1/agent/refresh', ['refresh_token' => $clear])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::REFRESH_EXPIRED]);
    }

    #[Test]
    public function replay_returns_401_and_cascade_revokes_all_actives(): void
    {
        $workstation = (string) Str::uuid();
        $clear = bin2hex(random_bytes(32));
        $hash = hash('sha256', $clear);

        // 1 revoked (le "replay")
        WorkstationRefreshToken::factory()
            ->forWorkstation($workstation)
            ->withHash($hash)
            ->revoked('refresh_rotation')
            ->create();

        // 2 actifs sur le même workstation
        WorkstationRefreshToken::factory()->count(2)->forWorkstation($workstation)->create();

        $this->postJson('/api/v1/agent/refresh', ['refresh_token' => $clear])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::REFRESH_REPLAY_DETECTED]);

        $stillActive = WorkstationRefreshToken::query()
            ->where('workstation_uuid', $workstation)
            ->whereNull('revoked_at')
            ->count();
        $this->assertSame(0, $stillActive);
    }

    #[Test]
    public function missing_body_returns_400_refresh_missing(): void
    {
        $this->postJson('/api/v1/agent/refresh', [])
            ->assertStatus(400)
            ->assertJson(['code' => JwtErrorCodes::REFRESH_MISSING]);
    }

    #[Test]
    public function malformed_refresh_returns_400_refresh_missing(): void
    {
        $this->postJson('/api/v1/agent/refresh', ['refresh_token' => 'short'])
            ->assertStatus(400)
            ->assertJson(['code' => JwtErrorCodes::REFRESH_MISSING]);
    }
}
