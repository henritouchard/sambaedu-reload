<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.2 — AC3.3 / AC8.2 / T6.5.
 *
 * Tests feature de la route native `GET|POST /ipxe/action/{action}`.
 */
class IpxeActionEndpointTest extends TestCase
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
        $response = $this->get('/ipxe/action/rescuecd');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('chain --replace --autofree action/rescuecd##params', $body);
        // Fix review #6 — assertions headers sécurité complètes au niveau Feature.
        $response->assertHeader('Cache-Control', 'no-store');
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    #[Test]
    public function it_dispatches_rescuecd_action(): void
    {
        $response = $this->post('/ipxe/action/rescuecd', [
            'mac' => 'aa:bb:cc:dd:ee:e1',
            'uuid' => 'eeeeeeee-1111-1111-1111-111111111111',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('sysresccd/boot/x86_64/vmlinuz', $body);
        self::assertStringEndsWith("boot\n", $body);
    }

    #[Test]
    public function it_dispatches_winpe_action(): void
    {
        $response = $this->post('/ipxe/action/winpe', [
            'mac' => 'aa:bb:cc:dd:ee:e2',
            'uuid' => 'eeeeeeee-2222-2222-2222-222222222222',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('kernel Win10/wimboot', $body);
    }

    #[Test]
    public function it_dispatches_factory_reset_action(): void
    {
        $response = $this->post('/ipxe/action/factory_reset', [
            'mac' => 'aa:bb:cc:dd:ee:e3',
            'uuid' => 'eeeeeeee-3333-3333-3333-333333333333',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('clonezilla/vmlinuz', $body);
        self::assertStringContainsString('restoreparts savesda1 sda1', $body);
    }

    #[Test]
    public function it_returns_404_for_unknown_action(): void
    {
        $response = $this->post('/ipxe/action/install_macos', [
            'mac' => 'aa:bb:cc:dd:ee:e4',
            'uuid' => 'eeeeeeee-4444-4444-4444-444444444444',
        ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_for_action_with_invalid_format(): void
    {
        // La regex de route `->where('action', '[a-z_]+')` rejette les
        // caractères majuscules / chiffres / espaces → 404 Laravel.
        $response = $this->post('/ipxe/action/RESCUECD', [
            'mac' => 'aa:bb:cc:dd:ee:e5',
            'uuid' => 'eeeeeeee-5555-5555-5555-555555555555',
        ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_initiated_by_ipxe_action_value(): void
    {
        $uniqueName = 'pc-act-feat-' . substr(bin2hex(random_bytes(4)), 0, 8);
        Workstation::create([
            'name' => $uniqueName,
            'uuid' => 'eeeeeeee-6666-6666-6666-666666666666',
            'mac' => 'aa:bb:cc:dd:ee:e6',
            'status' => 'active',
        ]);

        $this->post('/ipxe/action/factory_reset', [
            'mac' => 'aa:bb:cc:dd:ee:e6',
            'uuid' => 'eeeeeeee-6666-6666-6666-666666666666',
        ]);

        $row = MachineBootLog::query()
            ->where('machine_name', $uniqueName)
            ->where('action', 'ipxe_action')
            ->first();

        self::assertNotNull($row);
        self::assertSame('ipxe:factory_reset', $row->initiated_by);
    }
}
