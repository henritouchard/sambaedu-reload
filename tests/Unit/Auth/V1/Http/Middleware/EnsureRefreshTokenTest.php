<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Http\Middleware;

use App\Auth\V1\Http\Middleware\EnsureRefreshToken;
use App\Auth\V1\Jwt\WorkstationJwtIssuer;
use App\Auth\V1\Jwt\WorkstationJwtRefreshService;
use App\Auth\V1\Models\WorkstationRefreshToken;
use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.10 — AC4.3 / T5.6.
 */
class EnsureRefreshTokenTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
    }

    private function makeMiddleware(): EnsureRefreshToken
    {
        return new EnsureRefreshToken(new WorkstationJwtRefreshService(new WorkstationJwtIssuer()));
    }

    private function makeJsonRequest(array $body): Request
    {
        $req = Request::create('/api/v1/agent/refresh', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body) ?: '');

        return $req;
    }

    #[Test]
    public function missing_refresh_token_returns_400(): void
    {
        $req = $this->makeJsonRequest([]);
        $res = $this->makeMiddleware()->handle($req, fn () => new Response('OK', 200));

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(JwtErrorCodes::REFRESH_MISSING, $res->getData(true)['code']);
    }

    #[Test]
    public function malformed_refresh_token_returns_400(): void
    {
        $req = $this->makeJsonRequest(['refresh_token' => 'not-64-hex']);
        $res = $this->makeMiddleware()->handle($req, fn () => new Response('OK', 200));

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(JwtErrorCodes::REFRESH_MISSING, $res->getData(true)['code']);
    }

    #[Test]
    public function unknown_refresh_returns_401_invalid(): void
    {
        $clear = bin2hex(random_bytes(32));
        $req = $this->makeJsonRequest(['refresh_token' => $clear]);
        $res = $this->makeMiddleware()->handle($req, fn () => new Response('OK', 200));

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame(JwtErrorCodes::REFRESH_INVALID, $res->getData(true)['code']);
    }

    #[Test]
    public function expired_refresh_returns_401_expired(): void
    {
        $clear = bin2hex(random_bytes(32));
        $hash = hash('sha256', $clear);
        WorkstationRefreshToken::factory()->withHash($hash)->expired()->create();

        $req = $this->makeJsonRequest(['refresh_token' => $clear]);
        $res = $this->makeMiddleware()->handle($req, fn () => new Response('OK', 200));

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame(JwtErrorCodes::REFRESH_EXPIRED, $res->getData(true)['code']);
    }

    #[Test]
    public function replay_detected_returns_401_replay_and_cascade_revokes(): void
    {
        $workstationUuid = (string) Str::uuid();
        $clear = bin2hex(random_bytes(32));
        $hash = hash('sha256', $clear);
        // Le token replay : déjà revoked (rotation antérieure)
        WorkstationRefreshToken::factory()
            ->forWorkstation($workstationUuid)
            ->withHash($hash)
            ->revoked('refresh_rotation')
            ->create();
        // 2 autres actifs sur le même workstation
        WorkstationRefreshToken::factory()->count(2)->forWorkstation($workstationUuid)->create();

        $req = $this->makeJsonRequest(['refresh_token' => $clear]);
        $res = $this->makeMiddleware()->handle($req, fn () => new Response('OK', 200));

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame(JwtErrorCodes::REFRESH_REPLAY_DETECTED, $res->getData(true)['code']);

        // Cascade : tous les actifs sont maintenant revoked
        $stillActive = WorkstationRefreshToken::query()
            ->where('workstation_uuid', $workstationUuid)
            ->whereNull('revoked_at')
            ->count();
        $this->assertSame(0, $stillActive);
    }

    #[Test]
    public function valid_refresh_injects_attribute_and_calls_next(): void
    {
        $clear = bin2hex(random_bytes(32));
        $hash = hash('sha256', $clear);
        $record = WorkstationRefreshToken::factory()->withHash($hash)->create();

        $req = $this->makeJsonRequest(['refresh_token' => $clear]);
        $next = function (Request $r): Response {
            $injected = $r->attributes->get('auth_v1.refresh_token_record');

            return new Response($injected instanceof WorkstationRefreshToken ? $injected->id : 'NIL');
        };

        $res = $this->makeMiddleware()->handle($req, $next);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($record->id, $res->getContent());
    }
}
