<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\IpxeService;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;
use Tests\Support\IpxeAuthTestHelper;

/**
 * Story 3.2 — AC3.3 / T4.6.
 *
 * Tests unitaires de {@see IpxeService::handleAction()}.
 */
class IpxeServiceActionTest extends TestCase
{
    use IpxeAuthTestHelper;

    private IpxeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bypassIpxeAuth();
        IpxeSchemaBootstrapper::bootstrap();
        $this->service = $this->app->make(IpxeService::class);
    }

    private function makeRequest(array $params = []): Request
    {
        $request = Request::create('/ipxe/action/rescuecd', 'POST', $params);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');
        $request->server->set('HTTP_HOST', 'se4fs.lan');

        return $request;
    }

    #[Test]
    public function it_aborts_404_when_action_unknown(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->service->handleAction($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:01',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]), 'install_macos');
    }

    #[Test]
    public function it_returns_handshake_when_action_known_but_params_missing(): void
    {
        $response = $this->service->handleAction($this->makeRequest(), 'rescuecd');

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        // Story 4.10 — chain absolu (chemin complet) pour eviter le doublement
        // `/ipxe/action/action/...` d'un relatif sur une route 2 niveaux.
        self::assertStringContainsString('/ipxe/action/rescuecd##params', $body);
    }

    #[Test]
    public function it_returns_rescuecd_script_when_action_rescuecd_and_params_posted(): void
    {
        $response = $this->service->handleAction($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:01',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]), 'rescuecd');

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('sysresccd/boot/x86_64/vmlinuz', $body);
        self::assertStringEndsWith("boot\n", $body);
    }

    #[Test]
    public function it_returns_winpe_script_when_action_winpe(): void
    {
        $response = $this->service->handleAction($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:02',
            'uuid' => '22222222-2222-2222-2222-222222222222',
        ]), 'winpe');

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        // URL absolue (fix 2026-06-04) — un `kernel Win10/wimboot` relatif se
        // résolvait contre `/ipxe/action/` → 410 → abort iPXE.
        self::assertMatchesRegularExpression('#^kernel https?://[^/]+/ipxe/Win10/wimboot$#m', $body);
        self::assertStringContainsString('initrd --name winpeshl.ini', $body);
    }

    #[Test]
    public function it_returns_factory_reset_script_when_action_factory_reset(): void
    {
        $response = $this->service->handleAction($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:03',
            'uuid' => '33333333-3333-3333-3333-333333333333',
        ]), 'factory_reset');

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('clonezilla/vmlinuz', $body);
        self::assertStringContainsString('restoreparts savesda1 sda1', $body);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_action_and_initiated_by_ipxe_action_value(): void
    {
        $uniqueName = 'pc-act-' . substr(bin2hex(random_bytes(4)), 0, 8);
        Workstation::create([
            'name' => $uniqueName,
            'uuid' => '44444444-4444-4444-4444-444444444444',
            'mac' => 'aa:bb:cc:dd:ee:04',
            'status' => 'active',
        ]);

        $this->service->handleAction($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:04',
            'uuid' => '44444444-4444-4444-4444-444444444444',
        ]), 'factory_reset');

        $count = MachineBootLog::query()
            ->where('action', 'ipxe_action')
            ->where('initiated_by', 'ipxe:factory_reset')
            ->where('machine_name', $uniqueName)
            ->count();

        self::assertSame(1, $count);
    }

    /* ------------------------------------------------------------------
     * Story 3.2 — Correctif review #6 (assertions headers complètes)
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_returns_secure_headers_in_all_paths(): void
    {
        // Fix review #6 — assertions complètes des 3 headers de sécurité
        // (D10) dans le rendu d'action, iso `IpxeServiceAdminTest`.
        $response = $this->service->handleAction($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:01',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
        ]), 'rescuecd');

        self::assertStringContainsString(
            'text/plain',
            (string) $response->headers->get('Content-Type'),
        );
        self::assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    #[Test]
    public function it_dispatches_action_for_unknown_workstation(): void
    {
        // Parité legacy `action.php:28` — un poste inconnu peut déclencher
        // une action (factory_reset autorisé sur poste neuf).
        $response = $this->service->handleAction($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:99',
            'uuid' => '99999999-9999-9999-9999-999999999999',
        ]), 'rescuecd');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'sysresccd',
            (string) $response->getContent(),
        );
    }
}
