<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Http\Middleware;

use App\Auth\V1\Http\Middleware\EnsureSecureApiHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.10 — Review finding #A.
 */
class EnsureSecureApiHeadersTest extends TestCase
{
    #[Test]
    public function adds_no_store_cache_control(): void
    {
        $req = Request::create('/api/v1/agent/ping', 'GET');
        $res = (new EnsureSecureApiHeaders())->handle($req, fn () => new Response('ok', 200));

        // Symfony normalise l'ordre des directives Cache-Control (tri alpha),
        // on assertit donc l'ensemble plutôt que la string brute.
        $directives = array_map('trim', explode(',', (string) $res->headers->get('Cache-Control')));
        $this->assertEqualsCanonicalizing(
            ['no-store', 'no-cache', 'must-revalidate', 'private'],
            $directives,
        );
        $this->assertSame('no-cache', $res->headers->get('Pragma'));
    }

    #[Test]
    public function adds_hsts_with_one_year_max_age(): void
    {
        $req = Request::create('/api/v1/agent/ping', 'GET');
        $res = (new EnsureSecureApiHeaders())->handle($req, fn () => new Response('ok', 200));

        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $res->headers->get('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function adds_nosniff_and_frame_deny(): void
    {
        $req = Request::create('/api/v1/agent/ping', 'GET');
        $res = (new EnsureSecureApiHeaders())->handle($req, fn () => new Response('ok', 200));

        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $res->headers->get('X-Frame-Options'));
    }

    #[Test]
    public function preserves_response_body_and_status(): void
    {
        $req = Request::create('/api/v1/agent/ping', 'GET');
        $res = (new EnsureSecureApiHeaders())->handle($req, fn () => new Response('hello', 201));

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('hello', $res->getContent());
    }
}
