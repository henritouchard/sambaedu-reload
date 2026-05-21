<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.5 — AC5.5 / T6.3.
 *
 * Tests feature de la route native `GET|POST /ipxe/windows/sysprep.xml`
 * (stub minimal D15 — body vide).
 */
class IpxeWindowsSysprepEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
        ]);
    }

    #[Test]
    public function it_serves_empty_body_with_200(): void
    {
        $response = $this->get('/ipxe/windows/sysprep.xml?name=pc-test');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertSame('', $body);
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        $response->assertHeader('Cache-Control', 'no-store');
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    #[Test]
    public function it_logs_sysprep_stub_served(): void
    {
        Log::spy();

        $this->get('/ipxe/windows/sysprep.xml?name=pc-test');

        Log::shouldHaveReceived('channel')->with('ipxe');
    }

    #[Test]
    public function it_accepts_post_method(): void
    {
        $response = $this->post('/ipxe/windows/sysprep.xml', ['name' => 'pc-post']);
        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());
    }
}
