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
use Tests\Support\IpxeAuthTestHelper;

/**
 * Story 3.5 — AC4.1 / T5.4.
 *
 * Tests unitaires de {@see IpxeService::handleInstallationWindowsMenu()}.
 */
class IpxeServiceInstallationWindowsTest extends TestCase
{
    use IpxeAuthTestHelper;

    private IpxeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bypassIpxeAuth();
        IpxeSchemaBootstrapper::bootstrap();
        $this->service = app(IpxeService::class);
    }

    #[Test]
    public function it_returns_handshake_when_mac_or_uuid_empty(): void
    {
        $request = Request::create('/ipxe/installation-windows', 'POST');
        $response = $this->service->handleInstallationWindowsMenu($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('chain --replace --autofree installation-windows##params', $body);
    }

    #[Test]
    public function it_returns_menu_for_known_workstation(): void
    {
        Workstation::create([
            'name' => 'PC-WIN-01',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:b1',
            'status' => 'active',
        ]);

        $request = Request::create('/ipxe/installation-windows', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:b1',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
        ]);
        $response = $this->service->handleInstallationWindowsMenu($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('install_win10', $body);
        self::assertStringContainsString('install_win11', $body);
        self::assertStringContainsString('pc-win-01', $body);
    }

    #[Test]
    public function it_returns_error_menu_for_unknown_workstation(): void
    {
        $request = Request::create('/ipxe/installation-windows', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:99',
            'uuid' => '99999999-9999-9999-9999-999999999999',
        ]);
        $response = $this->service->handleInstallationWindowsMenu($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('Erreur - poste non encore enregistre', $body);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_install_win(): void
    {
        Workstation::create([
            'name' => 'PC-LOG-WIN',
            'uuid' => '12345678-1234-1234-1234-cccccccccccc',
            'mac' => 'aa:bb:cc:dd:ee:c1',
            'status' => 'active',
        ]);

        $request = Request::create('/ipxe/installation-windows', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:c1',
            'uuid' => '12345678-1234-1234-1234-cccccccccccc',
        ]);
        $this->service->handleInstallationWindowsMenu($request);

        $log = MachineBootLog::where('action', 'ipxe_install_win')->first();
        self::assertNotNull($log, 'MachineBootLog row attendu avec action=ipxe_install_win.');
        self::assertSame('ipxe', $log->initiated_by);
    }

    #[Test]
    public function it_sets_content_type_text_plain_with_no_store_headers(): void
    {
        Workstation::create([
            'name' => 'PC-HEAD',
            'uuid' => '12345678-1234-1234-1234-dddddddddddd',
            'mac' => 'aa:bb:cc:dd:ee:d1',
            'status' => 'active',
        ]);

        $request = Request::create('/ipxe/installation-windows', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:d1',
            'uuid' => '12345678-1234-1234-1234-dddddddddddd',
        ]);
        $response = $this->service->handleInstallationWindowsMenu($request);

        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        // Tolérant : Laravel middleware peut ajouter `, private` au Cache-Control.
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    #[Test]
    public function it_does_not_throw_when_template_is_missing(): void
    {
        // safeRender wrap : si le template est manquant, on retourne un
        // fallback iPXE minimal (pas d'exception bubble-up).
        config(['view.paths' => ['/nonexistent/views']]);

        // Récrée le service pour appliquer la nouvelle config view (le
        // ViewFactory est singleton, mais on flush les caches).
        $this->refreshApplication();
        IpxeSchemaBootstrapper::bootstrap();
        $service = app(IpxeService::class);

        $request = Request::create('/ipxe/installation-windows', 'POST', [
            'mac' => 'aa:bb:cc:dd:ee:00',
            'uuid' => '12345678-1234-1234-1234-eeeeeeeeeeee',
        ]);

        // Doit retourner une Response (pas throw).
        $response = $service->handleInstallationWindowsMenu($request);
        self::assertSame(200, $response->getStatusCode());
    }
}
