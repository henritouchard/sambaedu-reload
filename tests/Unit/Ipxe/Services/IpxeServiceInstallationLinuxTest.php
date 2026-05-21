<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\IpxeService;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.4 — AC4.1 / T5.4.
 *
 * Tests unitaires de {@see IpxeService::handleInstallationLinuxMenu()}.
 */
class IpxeServiceInstallationLinuxTest extends TestCase
{
    private IpxeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        $this->service = app(IpxeService::class);
    }

    #[Test]
    public function it_returns_handshake_when_mac_or_uuid_empty(): void
    {
        $request = Request::create('/ipxe/installation-linux', 'POST');
        $response = $this->service->handleInstallationLinuxMenu($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('chain --replace --autofree installation-linux##params', $body);
    }

    #[Test]
    public function it_returns_menu_for_known_workstation(): void
    {
        Workstation::create([
            'name' => 'PC-MENU',
            'uuid' => '12345678-1234-1234-1234-eeeeeeeeeeee',
            'mac' => 'aa:bb:cc:dd:ee:e1',
            'status' => 'active',
        ]);

        $request = Request::create('/ipxe/installation-linux', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:e1',
            'uuid' => '12345678-1234-1234-1234-eeeeeeeeeeee',
        ]);
        $response = $this->service->handleInstallationLinuxMenu($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('install_deb_gnome', $body);
        self::assertStringContainsString('PC-MENU', $body);
    }

    #[Test]
    public function it_returns_error_menu_for_unknown_workstation(): void
    {
        $request = Request::create('/ipxe/installation-linux', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:99',
            'uuid' => '99999999-9999-9999-9999-999999999999',
        ]);
        $response = $this->service->handleInstallationLinuxMenu($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('Erreur - poste non encore enregistre', $body);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_install_linux(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-MBL',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaabb',
            'mac' => 'aa:bb:cc:dd:ee:bb',
            'status' => 'active',
        ]);

        $request = Request::create('/ipxe/installation-linux', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:bb',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaabb',
        ]);
        $this->service->handleInstallationLinuxMenu($request);

        $count = MachineBootLog::query()
            ->where('action', 'ipxe_install_linux')
            ->where('workstation_id', $ws->id)
            ->count();

        self::assertSame(1, $count);
    }

    #[Test]
    public function it_returns_no_store_headers(): void
    {
        $request = Request::create('/ipxe/installation-linux', 'POST');
        $response = $this->service->handleInstallationLinuxMenu($request);

        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    #[Test]
    public function it_handles_action_dispatch_for_install_deb_gnome(): void
    {
        // Ce test vérifie que le dispatcher 3.2 fonctionne pour les nouveaux
        // cases install_*. Pattern iso 3.2.
        $ws = Workstation::create([
            'name' => 'PC-INSTALL',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:cc',
            'status' => 'active',
        ]);

        $request = Request::create('/ipxe/action/install_deb_gnome', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:cc',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
        ]);

        $response = $this->service->handleAction($request, 'install_deb_gnome');

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        // Le template install_deb_gnome doit produire un kernel cmdline.
        self::assertStringContainsString('kernel', $body);
        self::assertStringContainsString('debian-installer/amd64/linux', $body);
        // L'URL preseed doit être construite.
        self::assertStringContainsString('/ipxe/linux/preseed', $body);
        // Le hostname interpolé doit apparaître.
        self::assertStringContainsString('PC-INSTALL', $body);
    }
}
