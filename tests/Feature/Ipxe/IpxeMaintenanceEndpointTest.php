<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.2 — AC3.2 / AC8.2 / T6.4.
 *
 * Tests feature de la route native `GET|POST /ipxe/maintenance`.
 */
class IpxeMaintenanceEndpointTest extends TestCase
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
        $response = $this->get('/ipxe/maintenance');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('chain --replace --autofree maintenance##params', $body);
        // Fix review #6 — assertions headers sécurité complètes au niveau Feature.
        $response->assertHeader('Cache-Control', 'no-store');
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    #[Test]
    public function it_returns_menu_items_with_rescuecd_winpe_factory_reset(): void
    {
        Workstation::create([
            'name' => 'PC-MNT-FEAT',
            'uuid' => '22222222-2222-2222-2222-feaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:f3',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/maintenance', [
            'mac' => 'aa:bb:cc:dd:ee:f3',
            'uuid' => '22222222-2222-2222-2222-feaaaaaaaaaa',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('item --key c rescuecd', $body);
        self::assertStringContainsString('item --key w winpe', $body);
        self::assertStringContainsString('item --key f factory_reset', $body);
    }

    #[Test]
    public function it_retour_chains_back_to_admin(): void
    {
        $response = $this->post('/ipxe/maintenance', [
            'mac' => 'aa:bb:cc:dd:ee:f4',
            'uuid' => '99999999-9999-9999-9999-999999999999',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertMatchesRegularExpression('#chain --replace --autofree https?://[^/]+/ipxe/admin\#\#params#', $body);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_maintenance(): void
    {
        $uniqueName = 'pc-mnt-feat-' . substr(bin2hex(random_bytes(4)), 0, 8);
        Workstation::create([
            'name' => $uniqueName,
            'uuid' => '22222222-2222-2222-2222-fbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:f5',
            'status' => 'active',
        ]);

        $this->post('/ipxe/maintenance', [
            'mac' => 'aa:bb:cc:dd:ee:f5',
            'uuid' => '22222222-2222-2222-2222-fbbbbbbbbbbb',
        ]);

        $count = MachineBootLog::query()
            ->where('action', 'ipxe_maintenance')
            ->where('machine_name', $uniqueName)
            ->count();

        self::assertSame(1, $count);
    }
}
