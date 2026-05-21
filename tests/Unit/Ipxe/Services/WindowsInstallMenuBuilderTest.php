<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\WindowsInstallMenuBuilder;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.5 — AC3.2.
 *
 * Tests unitaires de {@see WindowsInstallMenuBuilder} — payload variables
 * Blade rendu par {@see \App\Ipxe\Services\IpxeMenuRenderer}.
 */
class WindowsInstallMenuBuilderTest extends TestCase
{
    private WindowsInstallMenuBuilder $service;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        $this->service = new WindowsInstallMenuBuilder();
    }

    private function makeWorkstation(string $name = 'PC-101'): Workstation
    {
        return Workstation::create([
            'name' => $name,
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_builds_payload_for_known_workstation(): void
    {
        $ws = $this->makeWorkstation('PC-101');
        $payload = $this->service->build($ws, 'http://192.168.122.50', '192.168.1.5');

        self::assertTrue($payload['isKnown']);
        self::assertSame('pc-101', $payload['workstationName']);
        self::assertSame('aa:bb:cc:dd:ee:01', $payload['mac']);
        self::assertSame('12345678-1234-1234-1234-aaaaaaaaaaaa', $payload['uuid']);
        self::assertSame('http://192.168.122.50', $payload['serverBaseUrl']);
    }

    #[Test]
    public function it_builds_payload_for_unknown_workstation(): void
    {
        $payload = $this->service->build(null, 'http://192.168.122.50', '192.168.1.5');

        self::assertFalse($payload['isKnown']);
        self::assertSame('unknown', $payload['workstationName']);
        self::assertSame('', $payload['mac']);
        self::assertSame('', $payload['uuid']);
    }

    #[Test]
    public function it_loads_7_items_from_config(): void
    {
        // Configuration par défaut (config/ipxe.php section windows).
        $payload = $this->service->build(
            $this->makeWorkstation(),
            'http://192.168.122.50',
            '192.168.1.5',
        );

        self::assertIsArray($payload['installWindowsItems']);
        self::assertCount(7, $payload['installWindowsItems']);
        $enums = array_column($payload['installWindowsItems'], 'enum');
        self::assertContains('install_win10', $enums);
        self::assertContains('install_win11', $enums);
        self::assertContains('install_win10_debug', $enums);
        self::assertContains('install_win10_disk', $enums);
        self::assertContains('install_win10_perso', $enums);
        self::assertContains('install_win11_disk', $enums);
        self::assertContains('install_win11_perso', $enums);
    }

    #[Test]
    public function it_uses_install_win11_as_default(): void
    {
        $payload = $this->service->build(
            $this->makeWorkstation(),
            'http://192.168.122.50',
            '192.168.1.5',
        );
        self::assertSame('install_win11', $payload['menuDefault']);
    }

    #[Test]
    public function it_sanitizes_workstation_name_against_non_ascii(): void
    {
        // Hostname AD pollué → sanitize via IpxeHostnameSanitizer.
        $ws = $this->makeWorkstation('PC-éàç-01');
        $payload = $this->service->build($ws, 'http://192.168.122.50', '192.168.1.5');

        // Caractères non-ASCII remplacés par `?`.
        self::assertStringNotContainsString('é', $payload['workstationName']);
        self::assertStringNotContainsString('à', $payload['workstationName']);
    }

    #[Test]
    public function it_trims_trailing_slash_in_server_url(): void
    {
        $payload = $this->service->build(
            $this->makeWorkstation(),
            'http://192.168.122.50/',
            '192.168.1.5',
        );
        self::assertSame('http://192.168.122.50', $payload['serverBaseUrl']);
    }

    #[Test]
    public function it_filters_invalid_menu_items_from_config(): void
    {
        config(['ipxe.windows.menu_items' => [
            ['enum' => 'install_win11', 'label' => 'OK'],
            ['enum' => '', 'label' => 'no-enum'],
            ['enum' => 'install_other', 'label' => ''],
            'string-only',
        ]]);

        $payload = $this->service->build(
            $this->makeWorkstation(),
            'http://192.168.122.50',
            '192.168.1.5',
        );

        self::assertCount(1, $payload['installWindowsItems']);
        self::assertSame('install_win11', $payload['installWindowsItems'][0]['enum']);
    }
}
