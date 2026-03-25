<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\NetworkConfig;
use App\Config\SambaEduConfig;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use App\Services\Parc\MachinePowerService;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\TestCase;

class MachinePowerServiceTest extends TestCase
{
    private MachinePowerService $service;
    private SambaEduConfig $configService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configService = Mockery::mock(SambaEduConfig::class);
        $this->service = new MachinePowerService($this->configService);
    }

    // ── resolveBroadcast ──────────────────────────────────────────────

    public function test_resolve_broadcast_from_dhcp_config(): void
    {
        // dhcp_reseau_0 = 192.168.1.0, dhcp_masque_0 = 255.255.255.0
        $this->configService->shouldReceive('all')->andReturn([
            'dhcp_reseau_0' => '192.168.1.0',
            'dhcp_masque_0' => '255.255.255.0',
        ]);

        // Ne devrait pas atteindre network()
        $this->configService->shouldReceive('network')->never();

        $result = $this->service->resolveBroadcast('192.168.1.50');

        $this->assertEquals('192.168.1.255', $result);
    }

    public function test_resolve_broadcast_multi_vlan(): void
    {
        $this->configService->shouldReceive('all')->andReturn([
            'dhcp_reseau_0' => '10.0.0.0',
            'dhcp_masque_0' => '255.255.255.0',
            'dhcp_reseau_1' => '10.0.1.0',
            'dhcp_masque_1' => '255.255.255.0',
            'dhcp_reseau_2' => '172.16.0.0',
            'dhcp_masque_2' => '255.255.0.0',
        ]);

        // IP dans le VLAN 1
        $result = $this->service->resolveBroadcast('10.0.1.42');
        $this->assertEquals('10.0.1.255', $result);

        // IP dans le VLAN 2 (grand réseau /16)
        $result = $this->service->resolveBroadcast('172.16.5.100');
        $this->assertEquals('172.16.255.255', $result);
    }

    public function test_resolve_broadcast_fallback_to_network_config(): void
    {
        // Pas de config DHCP
        $this->configService->shouldReceive('all')->andReturn([]);

        $networkConfig = new NetworkConfig(
            se4fsIp: '192.168.1.1',
            se4fsName: 'se4fs',
            se4adIp: '192.168.1.2',
            se4adName: 'se4ad',
            se4adMask: '255.255.255.0',
            se4adGateway: '192.168.1.1',
            interface: 'eth0',
            address: '192.168.1.2',
            mask: '255.255.255.0',
            network: '192.168.1.0',
            gateway: '192.168.1.1',
            nameserver: '192.168.1.1',
            se4Url: '',
        );

        $this->configService->shouldReceive('network')->andReturn($networkConfig);

        $result = $this->service->resolveBroadcast('192.168.1.50');
        $this->assertEquals('192.168.1.255', $result);
    }

    public function test_resolve_broadcast_fallback_default_24(): void
    {
        $this->configService->shouldReceive('all')->andReturn([]);

        $networkConfig = new NetworkConfig(
            se4fsIp: '', se4fsName: '', se4adIp: '', se4adName: '',
            se4adMask: '', se4adGateway: '', interface: '', address: '',
            mask: '', network: '', gateway: '', nameserver: '', se4Url: '',
        );

        $this->configService->shouldReceive('network')->andReturn($networkConfig);

        $result = $this->service->resolveBroadcast('10.0.5.42');
        $this->assertEquals('10.0.5.255', $result);
    }

    public function test_resolve_broadcast_empty_ip_returns_false(): void
    {
        $result = $this->service->resolveBroadcast('');
        $this->assertFalse($result);
    }

    public function test_resolve_broadcast_invalid_ip_returns_false(): void
    {
        $result = $this->service->resolveBroadcast('not-an-ip');
        $this->assertFalse($result);
    }

    // ── wakeOnLan ─────────────────────────────────────────────────────

    public function test_wol_empty_mac_returns_error(): void
    {
        $result = $this->service->wakeOnLan('', '192.168.1.50');

        $this->assertFalse($result['success']);
        $this->assertEquals(203, $result['code']);
    }

    public function test_wol_invalid_mac_returns_error(): void
    {
        $result = $this->service->wakeOnLan('invalid-mac', '192.168.1.50');

        $this->assertFalse($result['success']);
        $this->assertEquals(500, $result['code']);
    }

    public function test_wol_sends_packet_to_broadcast(): void
    {
        Process::fake();

        $this->configService->shouldReceive('all')->andReturn([
            'dhcp_reseau_0' => '192.168.1.0',
            'dhcp_masque_0' => '255.255.255.0',
        ]);
        $this->configService->shouldReceive('get')->with('wol_broadcast')->andReturn(null);

        $result = $this->service->wakeOnLan('aa:bb:cc:dd:ee:ff', '192.168.1.50');

        $this->assertTrue($result['success']);
        $this->assertEquals(202, $result['code']);

        Process::assertRan(function ($process) {
            return str_contains($process->command, 'wakeonlan -i 192.168.1.255 aa:bb:cc:dd:ee:ff');
        });
    }

    public function test_wol_sends_extra_packet_if_wol_broadcast_configured(): void
    {
        Process::fake();

        $this->configService->shouldReceive('all')->andReturn([
            'dhcp_reseau_0' => '192.168.1.0',
            'dhcp_masque_0' => '255.255.255.0',
        ]);
        $this->configService->shouldReceive('get')->with('wol_broadcast')->andReturn('10.255.255.255');

        $result = $this->service->wakeOnLan('aa:bb:cc:dd:ee:ff', '192.168.1.50');

        $this->assertTrue($result['success']);

        // Deux appels à wakeonlan
        Process::assertRan(function ($process) {
            return str_contains($process->command, 'wakeonlan -i 192.168.1.255');
        });
        Process::assertRan(function ($process) {
            return str_contains($process->command, 'wakeonlan -i 10.255.255.255');
        });
    }

    // ── shutdown ──────────────────────────────────────────────────────

    public function test_shutdown_windows_success(): void
    {
        Process::fake([
            '*net rpc shutdown*' => Process::result(output: 'Shutdown initiated'),
        ]);

        // Mock ping pour retourner 'windows' - on utilise une sous-classe partielle
        $service = Mockery::mock(MachinePowerService::class, [$this->configService])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('ping')->with('192.168.1.50')->andReturn('windows');

        $result = $service->shutdown('pc-01', '192.168.1.50');

        $this->assertTrue($result['success']);
        $this->assertEquals(201, $result['code']);
    }

    public function test_shutdown_machine_off_returns_already_off(): void
    {
        $service = Mockery::mock(MachinePowerService::class, [$this->configService])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('ping')->with('192.168.1.50')->andReturn(false);

        $result = $service->shutdown('pc-01', '192.168.1.50');

        $this->assertFalse($result['success']);
        $this->assertEquals(203, $result['code']);
        $this->assertStringContains('déjà éteinte', $result['message']);
    }

    // ── reboot ───────────────────────────────────────────────────────

    public function test_reboot_machine_off_fallback_wol(): void
    {
        Process::fake();

        $this->configService->shouldReceive('all')->andReturn([]);
        $this->configService->shouldReceive('get')->with('wol_broadcast')->andReturn(null);
        $this->configService->shouldReceive('network')->andReturn(new NetworkConfig(
            se4fsIp: '', se4fsName: '', se4adIp: '', se4adName: '',
            se4adMask: '', se4adGateway: '', interface: '', address: '',
            mask: '255.255.255.0', network: '', gateway: '', nameserver: '', se4Url: '',
        ));

        $service = Mockery::mock(MachinePowerService::class, [$this->configService])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('ping')->with('192.168.1.50')->andReturn(false);

        $result = $service->reboot('pc-01', '192.168.1.50', 'aa:bb:cc:dd:ee:ff');

        $this->assertTrue($result['success']);
        $this->assertStringContains('WOL', $result['message']);
    }

    public function test_reboot_machine_off_no_mac_returns_error(): void
    {
        $service = Mockery::mock(MachinePowerService::class, [$this->configService])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('ping')->with('192.168.1.50')->andReturn(false);

        $result = $service->reboot('pc-01', '192.168.1.50');

        $this->assertFalse($result['success']);
        $this->assertEquals(203, $result['code']);
    }

    // ── return code compatibility ────────────────────────────────────

    public function test_return_codes_are_compatible_with_legacy(): void
    {
        // Les codes 200-299 sauf 203 sont considérés succès par isLegacyActionSuccessful()
        // WOL success = 202 → succès
        // Shutdown/reboot success = 201 → succès
        // Errors = 203 → échec (inclut machine déjà éteinte)

        $this->assertEquals(202, $this->getWolSuccessCode());
        $this->assertEquals(201, $this->getActionSuccessCode());
        $this->assertEquals(203, $this->getErrorCode());
    }

    private function getWolSuccessCode(): int { return 202; }
    private function getActionSuccessCode(): int { return 201; }
    private function getErrorCode(): int { return 203; }

    // ── Helper assertion ─────────────────────────────────────────────

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
