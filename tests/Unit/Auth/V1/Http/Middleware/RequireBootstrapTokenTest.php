<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Http\Middleware;

use App\Auth\V1\Http\Middleware\RequireBootstrapToken;
use App\Auth\V1\Services\LegacyBootstrapTokenValidator;
use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.10 — AC4.2 / T5.6.
 */
class RequireBootstrapTokenTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeMiddleware(bool $tokenIsValid): RequireBootstrapToken
    {
        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $validator->shouldReceive('isValid')->andReturn($tokenIsValid);

        return new RequireBootstrapToken($validator);
    }

    #[Test]
    public function missing_header_returns_401_bootstrap_missing(): void
    {
        // Header absent → le middleware court-circuite AVANT d'appeler le validator.
        // On fige cet invariant avec `shouldNotReceive('isValid')` : si une refacto
        // changeait l'ordre des checks, ce test échouerait au lieu de passer par coïncidence.
        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $validator->shouldNotReceive('isValid');
        $middleware = new RequireBootstrapToken($validator);

        $req = Request::create('/api/v1/agent/enroll', 'POST');
        $res = $middleware->handle($req, fn () => new Response('OK', 200));

        $this->assertInstanceOf(JsonResponse::class, $res);
        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame(JwtErrorCodes::BOOTSTRAP_TOKEN_MISSING, $res->getData(true)['code']);
    }

    #[Test]
    public function invalid_token_returns_401_bootstrap_invalid(): void
    {
        $req = Request::create('/api/v1/agent/enroll', 'POST');
        $req->headers->set('X-Bootstrap-Token', md5('whatever'));
        $res = $this->makeMiddleware(false)->handle($req, fn () => new Response('OK', 200));

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame(JwtErrorCodes::BOOTSTRAP_TOKEN_INVALID, $res->getData(true)['code']);
    }

    #[Test]
    public function valid_token_calls_next(): void
    {
        $req = Request::create('/api/v1/agent/enroll', 'POST');
        $req->headers->set('X-Bootstrap-Token', md5('whatever'));
        $res = $this->makeMiddleware(true)->handle($req, fn () => new Response('OK', 200));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('OK', $res->getContent());
    }
}
