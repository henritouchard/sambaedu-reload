<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.4 — AC4.1 / AC5.1 / T6.3.
 *
 * Tests feature de la route native `GET|POST /ipxe/installation-linux`.
 */
class IpxeInstallationLinuxEndpointTest extends TestCase
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
        $response = $this->get('/ipxe/installation-linux');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('chain --replace --autofree installation-linux##params', $body);
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    #[Test]
    public function it_returns_full_menu_for_known_workstation(): void
    {
        Workstation::create([
            'name' => 'PC-LINUX-FEAT',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:b1',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/installation-linux', [
            'mac' => 'aa:bb:cc:dd:ee:b1',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('PC-LINUX-FEAT', $body);
        self::assertStringContainsString('install_deb_gnome', $body);
        self::assertStringContainsString('install_ubuntu64', $body);
        self::assertStringContainsString('install_nird', $body);
        // Sections de chain.
        self::assertStringContainsString(':install_deb_gnome', $body);
        self::assertStringContainsString('/ipxe/action/install_deb_gnome##params', $body);
    }

    #[Test]
    public function it_returns_error_menu_for_unknown_workstation(): void
    {
        $response = $this->post('/ipxe/installation-linux', [
            'mac' => 'aa:bb:cc:dd:ee:99',
            'uuid' => '99999999-9999-9999-9999-999999999999',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('Erreur - poste non encore enregistre', $body);
        self::assertStringContainsString('/ipxe/admin##params', $body);
        // Aucun item install_deb_* affiché.
        self::assertStringNotContainsString('install_deb_gnome', $body);
        // Post-review #2 — Pas de bloc `menu`/`choose` dans le body (syntaxe
        // iPXE invalide en cas de mix `echo`/`sleep`/`chain` dans un menu).
        self::assertStringNotContainsString('menu installation', $body);
        self::assertStringNotContainsString(':menu', $body);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_install_linux(): void
    {
        $uniqueName = 'pc-linux-feat-' . substr(bin2hex(random_bytes(4)), 0, 8);
        Workstation::create([
            'name' => $uniqueName,
            'uuid' => '12345678-1234-1234-1234-cccccccccccc',
            'mac' => 'aa:bb:cc:dd:ee:c1',
            'status' => 'active',
        ]);

        $this->post('/ipxe/installation-linux', [
            'mac' => 'aa:bb:cc:dd:ee:c1',
            'uuid' => '12345678-1234-1234-1234-cccccccccccc',
        ]);

        $count = MachineBootLog::query()
            ->where('action', 'ipxe_install_linux')
            ->where('machine_name', $uniqueName)
            ->count();

        self::assertSame(1, $count);
    }

    #[Test]
    public function it_rejects_oversize_mac_with_422(): void
    {
        $response = $this->postJson('/ipxe/installation-linux', [
            'mac' => str_repeat('a', 65),
            'uuid' => '11111111-1111-1111-1111-111111111111',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_text_plain_with_no_store_headers(): void
    {
        $response = $this->get('/ipxe/installation-linux');

        $response->assertStatus(200);
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }
}
