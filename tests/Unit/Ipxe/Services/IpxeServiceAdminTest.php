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
 * Story 3.2 — AC3.1 / T4.4.
 *
 * Tests unitaires de {@see IpxeService::handleAdmin()}.
 */
class IpxeServiceAdminTest extends TestCase
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
        $request = Request::create('/ipxe/admin', 'POST', $params);
        $request->server->set('REMOTE_ADDR', '192.168.1.42');
        $request->server->set('HTTP_HOST', 'se4fs.lan');

        return $request;
    }

    #[Test]
    public function it_returns_handshake_when_mac_and_uuid_missing(): void
    {
        $response = $this->service->handleAdmin($this->makeRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('chain --replace --autofree admin##params', $body);
    }

    #[Test]
    public function it_returns_admin_menu_for_known_workstation(): void
    {
        Workstation::create([
            'name' => 'PC-ADMIN-1',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $response = $this->service->handleAdmin($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'product' => 'OptiPlex 3050',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('PC-ADMIN-1', $body);
        self::assertStringContainsString('item --key m maintenance', $body);
    }

    #[Test]
    public function it_returns_minimal_admin_menu_for_unknown_workstation(): void
    {
        // Story 3.3 — AC6.6 / T6.8 — la modification de `admin.blade.php`
        // remplace le message neutre 3.2 par l'item enrollment `(n) set-name`.
        // Item maintenance reste absent pour poste inconnu (parité 3.2 D7).
        $response = $this->service->handleAdmin($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:99',
            'uuid' => '99999999-9999-9999-9999-999999999999',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('item --key n set-name', $body);
        self::assertStringContainsString('/ipxe/enrollment/name##params', $body);
        self::assertStringNotContainsString('item --key m maintenance', $body);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_admin(): void
    {
        $uniqueName = 'pc-admin-log-' . substr(bin2hex(random_bytes(4)), 0, 8);
        Workstation::create([
            'name' => $uniqueName,
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);

        $this->service->handleAdmin($this->makeRequest([
            'mac' => 'aa:bb:cc:dd:ee:01',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
        ]));

        $count = MachineBootLog::query()
            ->where('action', 'ipxe_admin')
            ->where('initiated_by', 'ipxe')
            ->where('machine_name', $uniqueName)
            ->count();

        self::assertSame(1, $count);
    }

    #[Test]
    public function it_returns_text_plain_in_all_paths(): void
    {
        $response = $this->service->handleAdmin($this->makeRequest());

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
    public function it_does_not_persist_machine_boot_log_on_handshake(): void
    {
        $countBefore = MachineBootLog::query()->count();

        // Handshake (sans mac/uuid) ne doit pas persister de MachineBootLog.
        $this->service->handleAdmin($this->makeRequest());

        $countAfter = MachineBootLog::query()->count();
        self::assertSame($countBefore, $countAfter);
    }
}
