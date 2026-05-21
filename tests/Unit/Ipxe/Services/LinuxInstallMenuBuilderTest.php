<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\LinuxInstallMenuBuilder;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.4 — AC3.1 / T3.1.
 *
 * Tests unitaires de {@see LinuxInstallMenuBuilder}.
 */
class LinuxInstallMenuBuilderTest extends TestCase
{
    private LinuxInstallMenuBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        $this->builder = new LinuxInstallMenuBuilder();
    }

    #[Test]
    public function it_returns_payload_with_required_keys_for_known_workstation(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-101',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);

        $payload = $this->builder->build($ws, 'http://192.168.1.1', '192.168.1.42');

        self::assertTrue($payload['isKnown']);
        self::assertSame('PC-101', $payload['workstationName']);
        self::assertSame('192.168.1.42', $payload['ip']);
        self::assertSame('http://192.168.1.1', $payload['serverBaseUrl']);
        self::assertIsArray($payload['installLinuxItems']);
        self::assertGreaterThan(0, count($payload['installLinuxItems']));
    }

    #[Test]
    public function it_returns_unknown_payload_when_workstation_is_null(): void
    {
        $payload = $this->builder->build(null, 'http://192.168.1.1', '192.168.1.42');

        self::assertFalse($payload['isKnown']);
        self::assertSame('unknown', $payload['workstationName']);
        self::assertSame('', $payload['mac']);
        self::assertSame('', $payload['uuid']);
    }

    #[Test]
    public function it_returns_nine_default_menu_items(): void
    {
        $payload = $this->builder->build(null, 'http://x', '0.0.0.0');
        self::assertCount(9, $payload['installLinuxItems']);

        $enums = array_map(static fn ($e) => $e['enum'], $payload['installLinuxItems']);
        self::assertContains('install_deb_gnome', $enums);
        self::assertContains('install_ubuntu64', $enums);
        self::assertContains('install_nird', $enums);
    }

    #[Test]
    public function it_sanitizes_workstation_name_with_non_ascii(): void
    {
        $ws = Workstation::create([
            'name' => "PC-é\n101",
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:02',
            'status' => 'active',
        ]);

        $payload = $this->builder->build($ws, 'http://x', '0.0.0.0');
        // L'accent est strippé + le newline aussi.
        self::assertStringNotContainsString("\n", $payload['workstationName']);
        self::assertStringNotContainsString('é', $payload['workstationName']);
    }
}
