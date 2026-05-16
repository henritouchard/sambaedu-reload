<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Http\Middleware;

use App\Auth\V1\Http\Middleware\EnsureWorkstationJwt;
use App\Auth\V1\Jwt\WorkstationJwtRevocationChecker;
use App\Auth\V1\Jwt\WorkstationJwtVerifier;
use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.10 — AC4.1 / T5.6.
 */
class EnsureWorkstationJwtTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
    }

    private function makeMiddleware(): EnsureWorkstationJwt
    {
        return new EnsureWorkstationJwt(
            new WorkstationJwtVerifier(new WorkstationJwtRevocationChecker())
        );
    }

    #[Test]
    public function missing_authorization_returns_401_jwt_missing(): void
    {
        $req = Request::create('/api/v1/agent/ping', 'GET');
        $res = $this->makeMiddleware()->handle($req, fn ($r) => new Response('OK', 200));

        $this->assertInstanceOf(JsonResponse::class, $res);
        $this->assertSame(401, $res->getStatusCode());
        $payload = $res->getData(true);
        $this->assertSame(JwtErrorCodes::JWT_MISSING, $payload['code']);
        $this->assertSame('unauthorized', $payload['error']);
    }

    #[Test]
    public function malformed_bearer_returns_401(): void
    {
        $req = Request::create('/api/v1/agent/ping', 'GET');
        $req->headers->set('Authorization', 'Bearer ');
        $res = $this->makeMiddleware()->handle($req, fn ($r) => new Response('OK', 200));

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame(JwtErrorCodes::JWT_MISSING, $res->getData(true)['code']);
    }

    #[Test]
    public function valid_jwt_calls_next_and_injects_attributes(): void
    {
        $emitted = $this->issueTestJwt(['sub' => '11111111-1111-1111-1111-111111111111']);
        $req = Request::create('/api/v1/agent/ping', 'GET');
        $req->headers->set('Authorization', 'Bearer ' . $emitted['token']);

        $next = function (Request $r): Response {
            return new Response((string) $r->attributes->get('auth_v1.workstation_uuid'));
        };

        $res = $this->makeMiddleware()->handle($req, $next);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('11111111-1111-1111-1111-111111111111', $res->getContent());
    }

    #[Test]
    public function expired_jwt_returns_401_expired(): void
    {
        $emitted = $this->issueTestJwt([
            'iat' => Carbon::now()->subDays(2)->getTimestamp(),
            'exp' => Carbon::now()->subDay()->getTimestamp(),
        ]);
        $req = Request::create('/api/v1/agent/ping', 'GET');
        $req->headers->set('Authorization', 'Bearer ' . $emitted['token']);

        $res = $this->makeMiddleware()->handle($req, fn ($r) => new Response('OK', 200));
        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame(JwtErrorCodes::JWT_EXPIRED, $res->getData(true)['code']);
    }

    #[Test]
    public function wrong_tier_returns_401_wrong_tier(): void
    {
        $emitted = $this->issueTestJwt(['tier' => 'controlhub']);
        $req = Request::create('/api/v1/agent/ping', 'GET');
        $req->headers->set('Authorization', 'Bearer ' . $emitted['token']);

        $res = $this->makeMiddleware()->handle($req, fn ($r) => new Response('OK', 200));
        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame(JwtErrorCodes::JWT_WRONG_TIER, $res->getData(true)['code']);
    }
}
