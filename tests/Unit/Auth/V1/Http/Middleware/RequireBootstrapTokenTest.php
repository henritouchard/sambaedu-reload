<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Http\Middleware;

use App\Auth\V1\Http\Middleware\RequireBootstrapToken;
use App\Auth\V1\Services\LegacyBootstrapTokenValidator;
use App\Auth\V1\Services\MigrationAttemptRecorder;
use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.10 — AC4.2 / T5.6.
 * Story 16.11 — AC3.1 (extraction uuid body + appel validator durci).
 */
class RequireBootstrapTokenTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Recorder no-op pour Unit tests (pas de DB). Story 16.11 Q2.
     */
    private function noopRecorder(): MigrationAttemptRecorder
    {
        // On retourne un mock partial : recordFailure ne fait rien.
        $rec = Mockery::mock(MigrationAttemptRecorder::class);
        $rec->shouldReceive('recordFailure')->andReturnNull();

        return $rec;
    }

    private function makeMiddleware(bool $tokenIsValid): RequireBootstrapToken
    {
        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $validator->shouldReceive('isValid')->andReturn($tokenIsValid);

        return new RequireBootstrapToken($validator, $this->noopRecorder());
    }

    #[Test]
    public function missing_header_returns_401_bootstrap_missing(): void
    {
        // Header absent → le middleware court-circuite AVANT d'appeler le validator.
        // On fige cet invariant avec `shouldNotReceive('isValid')` : si une refacto
        // changeait l'ordre des checks, ce test échouerait au lieu de passer par coïncidence.
        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $validator->shouldNotReceive('isValid');
        $middleware = new RequireBootstrapToken($validator, $this->noopRecorder());

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

    // ====================================================================
    // Story 16.11 — couple token↔UUID (AC3.1)
    // ====================================================================

    #[Test]
    public function valid_token_with_uuid_match_calls_next(): void
    {
        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        // Le middleware passe l'uuid au validator quand fourni dans le body.
        $validator->shouldReceive('isValid')
            ->with(Mockery::type('string'), '11111111-1111-4111-8111-111111111111')
            ->andReturn(true);
        $middleware = new RequireBootstrapToken($validator, $this->noopRecorder());

        $req = Request::create(
            '/api/v1/agent/enroll',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['uuid' => '11111111-1111-4111-8111-111111111111']),
        );
        $req->headers->set('X-Bootstrap-Token', md5('whatever'));
        $res = $middleware->handle($req, fn () => new Response('OK', 200));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('OK', $res->getContent());
    }

    #[Test]
    public function uuid_mismatch_returns_401_uuid_mismatch_code(): void
    {
        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $validator->shouldReceive('isValid')
            ->with(Mockery::type('string'), '11111111-1111-4111-8111-111111111111')
            ->andReturn(false);
        $validator->shouldReceive('checkMismatch')
            ->with(Mockery::type('string'), '11111111-1111-4111-8111-111111111111')
            ->andReturn(true);
        $middleware = new RequireBootstrapToken($validator, $this->noopRecorder());

        $req = Request::create(
            '/api/v1/agent/enroll',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['uuid' => '11111111-1111-4111-8111-111111111111']),
        );
        $req->headers->set('X-Bootstrap-Token', md5('whatever'));
        $res = $middleware->handle($req, fn () => new Response('OK', 200));

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame(
            JwtErrorCodes::BOOTSTRAP_TOKEN_UUID_MISMATCH,
            $res->getData(true)['code'],
        );
    }

    #[Test]
    public function token_invalid_with_uuid_returns_invalid_not_mismatch(): void
    {
        // Token absent en APCu (donc invalid pour les deux raisons) → on
        // attend `invalid` pas `uuid_mismatch` (sémantique correcte).
        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $validator->shouldReceive('isValid')
            ->with(Mockery::type('string'), '11111111-1111-4111-8111-111111111111')
            ->andReturn(false);
        $validator->shouldReceive('checkMismatch')
            ->with(Mockery::type('string'), '11111111-1111-4111-8111-111111111111')
            ->andReturn(false);
        $middleware = new RequireBootstrapToken($validator, $this->noopRecorder());

        $req = Request::create(
            '/api/v1/agent/enroll',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['uuid' => '11111111-1111-4111-8111-111111111111']),
        );
        $req->headers->set('X-Bootstrap-Token', md5('whatever'));
        $res = $middleware->handle($req, fn () => new Response('OK', 200));

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame(JwtErrorCodes::BOOTSTRAP_TOKEN_INVALID, $res->getData(true)['code']);
    }

    #[Test]
    public function malformed_uuid_falls_back_to_legacy_validation(): void
    {
        // UUID format invalide dans le body → comportement legacy 16.10
        // (validation sans uuid). Le validator est appelé sans 2e arg.
        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $validator->shouldReceive('isValid')
            ->with(Mockery::type('string'))
            ->once()
            ->andReturn(true);
        $validator->shouldNotReceive('isValid')->withArgs(function ($t, $u) {
            return is_string($u);
        });
        $middleware = new RequireBootstrapToken($validator, $this->noopRecorder());

        $req = Request::create(
            '/api/v1/agent/enroll',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['uuid' => 'not-a-uuid']),
        );
        $req->headers->set('X-Bootstrap-Token', md5('whatever'));
        $res = $middleware->handle($req, fn () => new Response('OK', 200));

        $this->assertSame(200, $res->getStatusCode());
    }

    // ====================================================================
    // Correction #3 + Opus-C — normalisation strtolower UUID extrait
    // ====================================================================

    // ====================================================================
    // Q2 — MigrationAttemptRecorder est invoqué sur chaque path d'erreur
    // ====================================================================

    #[Test]
    public function missing_header_records_failed_attempt(): void
    {
        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $validator->shouldNotReceive('isValid');

        $recorder = Mockery::mock(MigrationAttemptRecorder::class);
        $recorder->shouldReceive('recordFailure')
            ->once()
            ->with(
                Mockery::type(Request::class),
                JwtErrorCodes::BOOTSTRAP_TOKEN_MISSING,
                null,
                Mockery::any(),
            )
            ->andReturnNull();

        $middleware = new RequireBootstrapToken($validator, $recorder);
        $req = Request::create('/api/v1/agent/enroll', 'POST');
        $middleware->handle($req, fn () => new Response('OK', 200));
    }

    #[Test]
    public function uuid_mismatch_records_failed_attempt_with_uuid(): void
    {
        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $validator->shouldReceive('isValid')->andReturn(false);
        $validator->shouldReceive('checkMismatch')->andReturn(true);

        $recorder = Mockery::mock(MigrationAttemptRecorder::class);
        $recorder->shouldReceive('recordFailure')
            ->once()
            ->with(
                Mockery::type(Request::class),
                JwtErrorCodes::BOOTSTRAP_TOKEN_UUID_MISMATCH,
                '11111111-1111-4111-8111-111111111111',
                Mockery::any(),
            )
            ->andReturnNull();

        $middleware = new RequireBootstrapToken($validator, $recorder);
        $req = Request::create(
            '/api/v1/agent/enroll',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['uuid' => '11111111-1111-4111-8111-111111111111']),
        );
        $req->headers->set('X-Bootstrap-Token', md5('whatever'));
        $middleware->handle($req, fn () => new Response('OK', 200));
    }

    #[Test]
    public function invalid_token_records_failed_attempt(): void
    {
        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $validator->shouldReceive('isValid')->andReturn(false);

        $recorder = Mockery::mock(MigrationAttemptRecorder::class);
        $recorder->shouldReceive('recordFailure')
            ->once()
            ->with(
                Mockery::type(Request::class),
                JwtErrorCodes::BOOTSTRAP_TOKEN_INVALID,
                Mockery::any(),
                Mockery::any(),
            )
            ->andReturnNull();

        $middleware = new RequireBootstrapToken($validator, $recorder);
        $req = Request::create('/api/v1/agent/enroll', 'POST');
        $req->headers->set('X-Bootstrap-Token', md5('whatever'));
        $middleware->handle($req, fn () => new Response('OK', 200));
    }

    #[Test]
    public function valid_token_does_not_record_attempt(): void
    {
        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $validator->shouldReceive('isValid')->andReturn(true);

        $recorder = Mockery::mock(MigrationAttemptRecorder::class);
        $recorder->shouldNotReceive('recordFailure');

        $middleware = new RequireBootstrapToken($validator, $recorder);
        $req = Request::create('/api/v1/agent/enroll', 'POST');
        $req->headers->set('X-Bootstrap-Token', md5('whatever'));
        $middleware->handle($req, fn () => new Response('OK', 200));
    }

    // ====================================================================
    // Correction #3 + Opus-C — normalisation strtolower UUID extrait
    // ====================================================================

    #[Test]
    public function it_normalises_uppercase_uuid_to_lowercase(): void
    {
        // Le middleware doit normaliser l'UUID extrait en lowercase
        // avant de le passer au validator (correction #3).
        $uuidUpper = '11111111-AAAA-4111-8BBB-111111111111';
        $uuidLower = strtolower($uuidUpper);

        $validator = Mockery::mock(LegacyBootstrapTokenValidator::class);
        // Le validator DOIT recevoir la version lowercase.
        $validator->shouldReceive('isValid')
            ->with(Mockery::type('string'), $uuidLower)
            ->once()
            ->andReturn(true);
        $middleware = new RequireBootstrapToken($validator, $this->noopRecorder());

        $req = Request::create(
            '/api/v1/agent/enroll',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['uuid' => $uuidUpper]),
        );
        $req->headers->set('X-Bootstrap-Token', md5('whatever'));
        $res = $middleware->handle($req, fn () => new Response('OK', 200));

        $this->assertSame(200, $res->getStatusCode());
    }
}
