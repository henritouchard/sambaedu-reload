<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.5 — AC5.3 / T6.3.
 *
 * Tests feature de la route native `GET|POST /ipxe/windows/install.bat`.
 */
class IpxeWindowsInstallBatEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
            'sambaedu.se4fs_ip' => '192.168.122.50',
            'sambaedu.se4fs_name' => 'se4fs.lan',
            'sambaedu.se4install_name' => 'se4install',
            'sambaedu.se4install_passwd' => 'install-secret',
            'sambaedu.domain' => 'example.org',
        ]);
    }

    private function seedWorkstation(string $mac, string $uuid, string $name = 'PC-WIN'): void
    {
        Workstation::create([
            'name' => $name,
            'uuid' => $uuid,
            'mac' => $mac,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_serves_install_bat_for_win11_uefi(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:01', '12345678-1234-1234-1234-aaaaaaaaaaaa', 'pc-w11');

        $response = $this->get('/ipxe/windows/install.bat?mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&version=Win11&bios=uefi');

        $response->assertStatus(200);
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringStartsWith("::cmd\r\n", $body);
        self::assertStringContainsString('z:\\os\\Win11\\sources\\setup.exe', $body);
        // 2026-06-04 : plus de lignes post-setup (bcdboot, callback winpe) —
        // code mort retiré, setup.exe ne rend jamais la main depuis WinPE.
        self::assertStringNotContainsString('bcdboot', $body);
        self::assertStringNotContainsString('/ipxe/windows/action', $body);
        self::assertStringNotContainsString('action.php', $body);
    }

    #[Test]
    public function it_serves_install_bat_for_win10_legacy(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:02', '12345678-1234-1234-1234-bbbbbbbbbbbb', 'pc-w10');

        $response = $this->get('/ipxe/windows/install.bat?mac=aa:bb:cc:dd:ee:02&uuid=12345678-1234-1234-1234-bbbbbbbbbbbb&version=Win10&bios=legacy');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('z:\\os\\Win10\\sources\\setup.exe', $body);
        self::assertStringNotContainsString('bcdboot', $body);
    }

    #[Test]
    public function it_returns_empty_body_for_unknown_workstation(): void
    {
        // Parité legacy install.bat.php:32 — 200 + body vide + log warning.
        $response = $this->get('/ipxe/windows/install.bat?mac=aa:bb:cc:dd:ee:99&uuid=99999999-9999-9999-9999-999999999999&version=Win11&bios=uefi');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertSame('', $body);
    }

    #[Test]
    public function it_rejects_invalid_version_with_422(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:03', '12345678-1234-1234-1234-cccccccccccc');

        $response = $this->get('/ipxe/windows/install.bat?mac=aa:bb:cc:dd:ee:03&uuid=12345678-1234-1234-1234-cccccccccccc&version=Win99&bios=uefi');

        $response->assertStatus(422);
    }

    #[Test]
    public function it_rejects_injection_version_via_form_request_whitelist(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:04', '12345678-1234-1234-1234-dddddddddddd');

        // Injection version `Win11\nkernel http://evil` doit être bloquée par
        // la FormRequest (Rule::in whitelist).
        $response = $this->get("/ipxe/windows/install.bat?mac=aa:bb:cc:dd:ee:04&uuid=12345678-1234-1234-1234-dddddddddddd&version=Win11%0Akernel&bios=uefi");

        // FormRequest doit 422 sur la valeur hors whitelist.
        $response->assertStatus(422);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_win_install(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:05', '12345678-1234-1234-1234-eeeeeeeeeeee', 'pc-mbl-install');

        $this->get('/ipxe/windows/install.bat?mac=aa:bb:cc:dd:ee:05&uuid=12345678-1234-1234-1234-eeeeeeeeeeee&version=Win11&bios=uefi');

        $log = MachineBootLog::where('action', 'ipxe_win_install')->first();
        self::assertNotNull($log);
        self::assertSame('ipxe', $log->initiated_by);
    }

    #[Test]
    public function it_contains_crlf_line_endings(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:06', '12345678-1234-1234-1234-ffffffffffff');

        $response = $this->get('/ipxe/windows/install.bat?mac=aa:bb:cc:dd:ee:06&uuid=12345678-1234-1234-1234-ffffffffffff&version=Win11&bios=uefi');

        $body = (string) $response->getContent();
        // CRLF strict — au moins 10 occurrences.
        self::assertGreaterThanOrEqual(10, substr_count($body, "\r\n"));
        // Pas de LF orphelin.
        self::assertSame(
            substr_count($body, "\n"),
            substr_count($body, "\r\n"),
        );
    }
}
