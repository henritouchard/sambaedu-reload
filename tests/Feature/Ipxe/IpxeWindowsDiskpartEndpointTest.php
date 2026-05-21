<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.5 — AC5.4 / T6.3.
 *
 * Tests feature de la route native `GET|POST /ipxe/windows/diskpart.txt`.
 */
class IpxeWindowsDiskpartEndpointTest extends TestCase
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
    public function it_serves_iso_legacy_diskpart_body(): void
    {
        Workstation::create([
            'name' => 'pc-diskpart',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);

        $response = $this->get('/ipxe/windows/diskpart.txt?mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertSame(
            "select disk O\r\nselect partition 1\r\nassign letter=U\r\n",
            $body,
        );
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        $response->assertHeader('Cache-Control', 'no-store');
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_win_diskpart(): void
    {
        Workstation::create([
            'name' => 'pc-diskpart-mbl',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:02',
            'status' => 'active',
        ]);

        $this->get('/ipxe/windows/diskpart.txt?mac=aa:bb:cc:dd:ee:02&uuid=12345678-1234-1234-1234-bbbbbbbbbbbb');

        $log = MachineBootLog::where('action', 'ipxe_win_diskpart')->first();
        self::assertNotNull($log);
        self::assertSame('ipxe', $log->initiated_by);
    }

    #[Test]
    public function it_still_serves_body_for_unknown_workstation(): void
    {
        // Body statique, le poste inconnu reçoit quand même le diskpart
        // (parité legacy diskpart.php — pas de check auth strict).
        $response = $this->get('/ipxe/windows/diskpart.txt?mac=aa:bb:cc:dd:ee:99&uuid=99999999-9999-9999-9999-999999999999');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertSame(
            "select disk O\r\nselect partition 1\r\nassign letter=U\r\n",
            $body,
        );
    }
}
