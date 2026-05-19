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
 * Story 3.2 — AC3.2 / T4.5.
 *
 * Tests unitaires de {@see IpxeService::handleMaintenance()}.
 */
class IpxeServiceMaintenanceTest extends TestCase
{
    private IpxeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        $this->service = $this->app->make(IpxeService::class);
    }

    private function makeRequest(array $params = []): Request
    {
        $request = Request::create('/ipxe/maintenance', 'POST', $params);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');
        $request->server->set('HTTP_HOST', 'se4fs.lan');

        return $request;
    }

    #[Test]
    public function it_returns_handshake_when_mac_and_uuid_missing(): void
    {
        $response = $this->service->handleMaintenance($this->makeRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('chain --replace --autofree maintenance##params', $body);
    }

    #[Test]
    public function it_returns_maintenance_menu_for_known_workstation(): void
    {
        Workstation::create([
            'name' => 'PC-MNT-K',
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'mac' => 'aa:bb:cc:dd:ee:02',
            'status' => 'active',
        ]);

        $response = $this->service->handleMaintenance($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:02',
            'uuid' => '22222222-2222-2222-2222-222222222222',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('item --key c rescuecd', $body);
        self::assertStringContainsString('item --key w winpe', $body);
        self::assertStringContainsString('item --key f factory_reset', $body);
    }

    #[Test]
    public function it_returns_maintenance_menu_for_unknown_workstation(): void
    {
        // Parité legacy `maintenance.php:15` — un poste inconnu reçoit le
        // menu complet (factory_reset autorisé sur poste neuf).
        $response = $this->service->handleMaintenance($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:99',
            'uuid' => '99999999-9999-9999-9999-999999999999',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('item --key c rescuecd', $body);
        self::assertStringContainsString('item --key f factory_reset', $body);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_maintenance(): void
    {
        $uniqueName = 'pc-mnt-log-' . substr(bin2hex(random_bytes(4)), 0, 8);
        Workstation::create([
            'name' => $uniqueName,
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'mac' => 'aa:bb:cc:dd:ee:03',
            'status' => 'active',
        ]);

        $this->service->handleMaintenance($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:03',
            'uuid' => '33333333-3333-3333-3333-333333333333',
        ]));

        $count = MachineBootLog::query()
            ->where('action', 'ipxe_maintenance')
            ->where('initiated_by', 'ipxe')
            ->where('machine_name', $uniqueName)
            ->count();

        self::assertSame(1, $count);
    }

    #[Test]
    public function it_returns_text_plain_in_all_paths(): void
    {
        // Fix review #6 — assertions complètes des 3 headers de sécurité
        // (D10) iso `IpxeServiceAdminTest`. L'ancien test ne validait que
        // `Content-Type`, laissant un risque de régression silencieuse si
        // quelqu'un ajoutait un chemin alternatif sans passer par respond().
        $response = $this->service->handleMaintenance($this->makeRequest());

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
}
