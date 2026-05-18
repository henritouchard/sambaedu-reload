<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Models;

use App\Auth\V1\Models\WorkstationRefreshToken;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.10 — AC3.1 / AC7.1.
 *
 * Tests modèle `WorkstationRefreshToken` — scopes + casts.
 */
class WorkstationRefreshTokenTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
    }

    #[Test]
    public function scope_active_returns_non_revoked_non_expired(): void
    {
        WorkstationRefreshToken::factory()->create();              // actif
        WorkstationRefreshToken::factory()->revoked()->create();    // revoqué
        WorkstationRefreshToken::factory()->expired()->create();    // expiré

        $this->assertSame(1, WorkstationRefreshToken::query()->active()->count());
    }

    #[Test]
    public function scope_expired_returns_tokens_past_expires_at(): void
    {
        WorkstationRefreshToken::factory()->create();
        WorkstationRefreshToken::factory()->expired()->create();
        WorkstationRefreshToken::factory()->expired()->create();

        $this->assertSame(2, WorkstationRefreshToken::query()->expired()->count());
    }

    #[Test]
    public function scope_revoked_returns_tokens_with_revoked_at_not_null(): void
    {
        WorkstationRefreshToken::factory()->create();
        WorkstationRefreshToken::factory()->revoked()->create();

        $this->assertSame(1, WorkstationRefreshToken::query()->revoked()->count());
    }

    #[Test]
    public function client_meta_is_cast_to_array(): void
    {
        $token = WorkstationRefreshToken::factory()->create([
            'client_meta' => ['mac' => 'AA:BB:CC:DD:EE:FF', 'hostname' => 'pc-1', 'os' => 'linux'],
        ]);
        $reloaded = WorkstationRefreshToken::query()->find($token->id);
        $this->assertIsArray($reloaded->client_meta);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $reloaded->client_meta['mac']);
    }

    #[Test]
    public function is_active_returns_true_for_fresh_token(): void
    {
        $token = WorkstationRefreshToken::factory()->make();
        $this->assertTrue($token->isActive());
    }

    #[Test]
    public function is_active_returns_false_for_expired_token(): void
    {
        $token = WorkstationRefreshToken::factory()->make([
            'expires_at' => Carbon::now()->subDay(),
        ]);
        $this->assertFalse($token->isActive());
    }

    #[Test]
    public function is_active_returns_false_for_revoked_token(): void
    {
        $token = WorkstationRefreshToken::factory()->make([
            'revoked_at' => Carbon::now(),
        ]);
        $this->assertFalse($token->isActive());
    }
}
