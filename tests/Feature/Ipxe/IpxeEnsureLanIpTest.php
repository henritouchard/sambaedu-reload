<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Auth\V1\Support\JwtErrorCodes;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.1 — AC8.2 / T6.5.
 *
 * Tests feature de la sécurité LAN-only sur `/ipxe/boot` :
 *
 *  - IP publique → 403 + JSON `code=bootstrap.not_lan` (reuse 16.11
 *    `JwtErrorCodes::BOOTSTRAP_NOT_LAN`).
 *  - IP RFC1918 (192.168.x.y) → 200.
 *  - IP loopback (127.x.x.x) → 200 (config standard).
 */
class IpxeEnsureLanIpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
    }

    #[Test]
    public function it_rejects_403_when_request_from_public_ip(): void
    {
        // Subnet restrictif : seul 192.168.99.0/24 autorisé → 127.0.0.1
        // (loopback du test server) sera rejeté.
        config([
            'auth_v1.bootstrap.allowed_subnets' => '192.168.99.0/24',
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->get('/ipxe/boot');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'forbidden',
                'code' => JwtErrorCodes::BOOTSTRAP_NOT_LAN,
            ]);
    }

    #[Test]
    public function it_accepts_when_request_from_lan_192_168_x_y(): void
    {
        config([
            'auth_v1.bootstrap.allowed_subnets' => '192.168.0.0/16,127.0.0.0/8',
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.42'])
            ->get('/ipxe/boot');

        $response->assertStatus(200);
        self::assertStringStartsWith('#!ipxe', (string) $response->getContent());
    }
}
