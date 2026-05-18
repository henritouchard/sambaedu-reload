<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Http\Middleware;

use App\Auth\V1\Http\Middleware\EnsureLanIp;
use App\Auth\V1\Services\MigrationAttemptRecorder;
use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.11 — AC2.1.
 *
 * Tests `EnsureLanIp` — IP whitelist LAN avec subnets RFC1918 par défaut.
 */
class EnsureLanIpTest extends TestCase
{
    private function makeRequest(string $remoteAddr): Request
    {
        $req = Request::create('/api/v1/agent/enroll', 'POST');
        // server('REMOTE_ADDR') est lu via $request->server(...), donc on
        // doit l'injecter via $req->server.
        $req->server->set('REMOTE_ADDR', $remoteAddr);

        return $req;
    }

    private function passNext(): \Closure
    {
        return fn () => new Response('OK', 200);
    }

    /**
     * Recorder no-op pour Unit tests (pas de DB). Story 16.11 Q2.
     */
    private function noopRecorder(): MigrationAttemptRecorder
    {
        $rec = Mockery::mock(MigrationAttemptRecorder::class);
        $rec->shouldReceive('recordFailure')->andReturnNull();

        return $rec;
    }

    private function makeMiddleware(): EnsureLanIp
    {
        return new EnsureLanIp($this->noopRecorder());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Forcer la config par défaut RFC1918 + localhost.
        config([
            'auth_v1.bootstrap.allowed_subnets' => '192.168.0.0/16,10.0.0.0/8,172.16.0.0/12,127.0.0.0/8,::1/128',
        ]);
    }

    #[Test]
    public function ip_in_192_168_subnet_passes(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('192.168.10.42'),
            $this->passNext(),
        );

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('OK', $res->getContent());
    }

    #[Test]
    public function ip_in_10_subnet_passes(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('10.42.0.1'),
            $this->passNext(),
        );
        $this->assertSame(200, $res->getStatusCode());
    }

    #[Test]
    public function ip_in_172_16_subnet_passes(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('172.16.5.5'),
            $this->passNext(),
        );
        $this->assertSame(200, $res->getStatusCode());
    }

    #[Test]
    public function localhost_passes(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('127.0.0.1'),
            $this->passNext(),
        );
        $this->assertSame(200, $res->getStatusCode());
    }

    #[Test]
    public function ipv6_localhost_passes(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('::1'),
            $this->passNext(),
        );
        $this->assertSame(200, $res->getStatusCode());
    }

    #[Test]
    public function public_ip_is_blocked_403(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('8.8.8.8'),
            $this->passNext(),
        );

        $this->assertInstanceOf(JsonResponse::class, $res);
        $this->assertSame(403, $res->getStatusCode());
        $data = $res->getData(true);
        $this->assertFalse($data['success']);
        $this->assertSame('forbidden', $data['error']);
        $this->assertSame(JwtErrorCodes::BOOTSTRAP_NOT_LAN, $data['code']);
    }

    #[Test]
    public function malformed_ip_is_blocked_403(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('not-an-ip'),
            $this->passNext(),
        );
        $this->assertSame(403, $res->getStatusCode());
    }

    #[Test]
    public function empty_remote_addr_is_blocked_403(): void
    {
        $res = $this->makeMiddleware()->handle(
            $this->makeRequest(''),
            $this->passNext(),
        );
        $this->assertSame(403, $res->getStatusCode());
    }

    #[Test]
    public function array_config_override_works(): void
    {
        config([
            'auth_v1.bootstrap.allowed_subnets' => ['192.168.42.0/24'],
        ]);

        $resBlocked = $this->makeMiddleware()->handle(
            $this->makeRequest('192.168.43.1'),
            $this->passNext(),
        );
        $this->assertSame(403, $resBlocked->getStatusCode());

        $resAllowed = $this->makeMiddleware()->handle(
            $this->makeRequest('192.168.42.5'),
            $this->passNext(),
        );
        $this->assertSame(200, $resAllowed->getStatusCode());
    }

    #[Test]
    public function csv_config_override_works(): void
    {
        config([
            'auth_v1.bootstrap.allowed_subnets' => '10.20.30.0/24, 192.168.99.0/24',
        ]);

        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('10.20.30.5'),
            $this->passNext(),
        );
        $this->assertSame(200, $res->getStatusCode());
    }

    #[Test]
    public function empty_config_blocks_all(): void
    {
        // Admin a override volontairement avec une liste vide → fail-closed
        // (pas de fallback RFC1918 quand l'admin override).
        config([
            'auth_v1.bootstrap.allowed_subnets' => '',
        ]);

        $res = $this->makeMiddleware()->handle(
            $this->makeRequest('192.168.1.1'),
            $this->passNext(),
        );
        $this->assertSame(403, $res->getStatusCode());
    }

    // ====================================================================
    // Q2 (Opus-D) — MigrationAttemptRecorder invoqué sur rejet LAN
    // ====================================================================

    #[Test]
    public function lan_block_records_failed_attempt_with_not_lan_code(): void
    {
        $recorder = Mockery::mock(MigrationAttemptRecorder::class);
        $recorder->shouldReceive('recordFailure')
            ->once()
            ->with(
                Mockery::type(Request::class),
                JwtErrorCodes::BOOTSTRAP_NOT_LAN,
                null,
                Mockery::pattern('/Bootstrap endpoint is restricted to LAN/'),
            )
            ->andReturnNull();

        $middleware = new EnsureLanIp($recorder);
        $res = $middleware->handle($this->makeRequest('8.8.8.8'), $this->passNext());
        $this->assertSame(403, $res->getStatusCode());
    }

    #[Test]
    public function lan_pass_does_not_record_attempt(): void
    {
        $recorder = Mockery::mock(MigrationAttemptRecorder::class);
        $recorder->shouldNotReceive('recordFailure');

        $middleware = new EnsureLanIp($recorder);
        $res = $middleware->handle($this->makeRequest('192.168.1.42'), $this->passNext());
        $this->assertSame(200, $res->getStatusCode());
    }
}
