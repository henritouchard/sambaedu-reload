<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.2 — AC2.1 / AC3.1 / AC8.2 / T6.3.
 *
 * Tests feature de la route native `GET|POST /ipxe/admin`.
 */
class IpxeAdminEndpointTest extends TestCase
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
    public function it_returns_handshake_when_no_params(): void
    {
        $response = $this->get('/ipxe/admin');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('chain --replace --autofree admin##params', $body);
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        // Fix review #6 — assertions headers sécurité complètes au niveau Feature.
        $response->assertHeader('Cache-Control', 'no-store');
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    #[Test]
    public function it_returns_admin_menu_for_known_workstation(): void
    {
        Workstation::create([
            'name' => 'PC-ADMIN-FEAT',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:f1',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/admin', [
            'mac' => 'aa:bb:cc:dd:ee:f1',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('PC-ADMIN-FEAT', $body);
        self::assertStringContainsString('item --key m maintenance', $body);
    }

    #[Test]
    public function it_returns_minimal_menu_for_unknown_workstation(): void
    {
        $response = $this->post('/ipxe/admin', [
            'mac' => 'aa:bb:cc:dd:ee:99',
            'uuid' => '99999999-9999-9999-9999-999999999999',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('Poste non enregistre', $body);
        self::assertStringNotContainsString('item --key m maintenance', $body);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_admin(): void
    {
        $uniqueName = 'pc-admin-feat-' . substr(bin2hex(random_bytes(4)), 0, 8);
        Workstation::create([
            'name' => $uniqueName,
            'uuid' => '12345678-1234-1234-1234-feaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:f2',
            'status' => 'active',
        ]);

        $this->post('/ipxe/admin', [
            'mac' => 'aa:bb:cc:dd:ee:f2',
            'uuid' => '12345678-1234-1234-1234-feaaaaaaaaaa',
        ]);

        $count = MachineBootLog::query()
            ->where('action', 'ipxe_admin')
            ->where('machine_name', $uniqueName)
            ->count();

        self::assertSame(1, $count);
    }

    #[Test]
    public function it_rejects_oversize_mac_with_422(): void
    {
        $response = $this->postJson('/ipxe/admin', [
            'mac' => str_repeat('a', 65),
            'uuid' => '11111111-1111-1111-1111-111111111111',
        ]);

        $response->assertStatus(422);
    }
}
