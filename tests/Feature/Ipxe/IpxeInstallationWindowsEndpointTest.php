<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.5 — AC4.1 / AC5.1 / T6.3.
 *
 * Tests feature de la route native `GET|POST /ipxe/installation-windows`.
 */
class IpxeInstallationWindowsEndpointTest extends TestCase
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
        $response = $this->get('/ipxe/installation-windows');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('chain --replace --autofree installation-windows##params', $body);
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    #[Test]
    public function it_returns_full_menu_for_known_workstation(): void
    {
        Workstation::create([
            'name' => 'PC-WIN-FEAT',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:b1',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/installation-windows', [
            'mac' => 'aa:bb:cc:dd:ee:b1',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('pc-win-feat', $body);
        // Les 7 items doivent apparaître.
        self::assertStringContainsString('install_win10', $body);
        self::assertStringContainsString('install_win10_debug', $body);
        self::assertStringContainsString('install_win10_disk', $body);
        self::assertStringContainsString('install_win10_perso', $body);
        self::assertStringContainsString('install_win11', $body);
        self::assertStringContainsString('install_win11_disk', $body);
        self::assertStringContainsString('install_win11_perso', $body);
        // Sections de chain.
        self::assertStringContainsString(':install_win11', $body);
        self::assertStringContainsString('/ipxe/action/install_win11##params', $body);
        // Default = install_win11.
        self::assertStringContainsString('set menu-default install_win11', $body);
    }

    #[Test]
    public function it_returns_error_menu_for_unknown_workstation(): void
    {
        $response = $this->post('/ipxe/installation-windows', [
            'mac' => 'aa:bb:cc:dd:ee:99',
            'uuid' => '99999999-9999-9999-9999-999999999999',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('Erreur - poste non encore enregistre', $body);
        self::assertStringContainsString('/ipxe/admin##params', $body);
        // Aucun item install_win* affiché.
        self::assertStringNotContainsString('install_win10', $body);
        self::assertStringNotContainsString('install_win11', $body);
        self::assertStringNotContainsString(':menu', $body);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_install_win(): void
    {
        Workstation::create([
            'name' => 'pc-win-mbl',
            'uuid' => '12345678-1234-1234-1234-cccccccccccc',
            'mac' => 'aa:bb:cc:dd:ee:c1',
            'status' => 'active',
        ]);

        $this->post('/ipxe/installation-windows', [
            'mac' => 'aa:bb:cc:dd:ee:c1',
            'uuid' => '12345678-1234-1234-1234-cccccccccccc',
        ]);

        $log = MachineBootLog::where('action', 'ipxe_install_win')->first();
        self::assertNotNull($log);
        self::assertSame('ipxe', $log->initiated_by);
    }

    #[Test]
    public function it_returns_text_plain_with_secure_headers(): void
    {
        Workstation::create([
            'name' => 'pc-head',
            'uuid' => '12345678-1234-1234-1234-dddddddddddd',
            'mac' => 'aa:bb:cc:dd:ee:d1',
            'status' => 'active',
        ]);
        $response = $this->post('/ipxe/installation-windows', [
            'mac' => 'aa:bb:cc:dd:ee:d1',
            'uuid' => '12345678-1234-1234-1234-dddddddddddd',
        ]);

        $response->assertStatus(200);
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        $response->assertHeader('Cache-Control', 'no-store');
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    #[Test]
    public function it_accepts_get_method(): void
    {
        Workstation::create([
            'name' => 'pc-get',
            'uuid' => '12345678-1234-1234-1234-eeeeeeeeeeee',
            'mac' => 'aa:bb:cc:dd:ee:e1',
            'status' => 'active',
        ]);

        $response = $this->get('/ipxe/installation-windows?mac=aa:bb:cc:dd:ee:e1&uuid=12345678-1234-1234-1234-eeeeeeeeeeee');
        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('install_win11', $body);
    }
}
