<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.4 — AC5.3 / T6.3.
 *
 * Tests feature de la route native `GET|POST /ipxe/linux/action` (hook fin
 * d'install Linux émis par debian-installer).
 */
class IpxeLinuxActionEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
        ]);
    }

    private function seedWorkstation(): Workstation
    {
        return Workstation::create([
            'name' => 'PC-ACTION',
            'uuid' => '12345678-1234-1234-1234-ffffffffffff',
            'mac' => 'aa:bb:cc:dd:ee:f1',
            'status' => 'active',
            'os' => null,
        ]);
    }

    #[Test]
    public function it_updates_workstation_os_on_ret_zero(): void
    {
        $ws = $this->seedWorkstation();

        $response = $this->post('/ipxe/linux/action', [
            'uuid' => '12345678-1234-1234-1234-ffffffffffff',
            'name' => 'PC-ACTION',
            'ret' => 0,
        ]);

        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());

        $ws->refresh();
        self::assertSame('linux', $ws->os);
        self::assertStringContainsString('terminee', (string) $ws->status);
    }

    #[Test]
    public function it_updates_workstation_status_on_failure(): void
    {
        $ws = $this->seedWorkstation();

        $this->post('/ipxe/linux/action', [
            'uuid' => '12345678-1234-1234-1234-ffffffffffff',
            'name' => 'PC-ACTION',
            'ret' => 99,
        ]);

        $ws->refresh();
        self::assertStringContainsString('echouee (ret=99)', (string) $ws->status);
    }

    #[Test]
    public function it_returns_empty_200_for_unknown_workstation(): void
    {
        $response = $this->post('/ipxe/linux/action', [
            'uuid' => '99999999-9999-9999-9999-999999999999',
            'name' => 'pc-unknown',
            'ret' => 0,
        ]);

        $response->assertStatus(200);
        self::assertSame('', (string) $response->getContent());
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_linux_report(): void
    {
        $ws = $this->seedWorkstation();

        $this->post('/ipxe/linux/action', [
            'uuid' => '12345678-1234-1234-1234-ffffffffffff',
            'name' => 'PC-ACTION',
            'ret' => 0,
        ]);

        $count = MachineBootLog::query()
            ->where('action', 'ipxe_linux_report')
            ->where('workstation_id', $ws->id)
            ->count();

        self::assertSame(1, $count);
    }

    /* ------------------------------------------------------------------
     * Post-review #M5 — Contrat UUID-only de `WorkstationLocator::locate()`.
     *
     * Le hook `late_command` posté par debian-installer ne contient pas la
     * MAC (parité legacy `preseed.cfg:83`). Le controller appelle donc
     * `locate('', $uuid, '')`. Ce test gèle le contrat — si une évolution
     * de `WorkstationLocator` rejette les calls `mac=''`, ce test casse
     * AVANT la mise en prod.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_resolves_workstation_by_uuid_only_when_mac_is_empty(): void
    {
        $ws = $this->seedWorkstation();

        $response = $this->post('/ipxe/linux/action', [
            // Pas de MAC dans le payload — `late_command` legacy n'en pose pas.
            'uuid' => '12345678-1234-1234-1234-ffffffffffff',
            'name' => 'PC-ACTION',
            'ret' => 0,
        ]);

        $response->assertStatus(200);
        $ws->refresh();
        // Workstation effectivement résolue par UUID seul → update appliqué.
        self::assertSame('linux', $ws->os);

        // Row MachineBootLog créée → preuve que la résolution UUID-only a
        // bien atteint le tracker.
        $count = MachineBootLog::query()
            ->where('action', 'ipxe_linux_report')
            ->where('workstation_id', $ws->id)
            ->count();
        self::assertSame(1, $count);
    }
}
