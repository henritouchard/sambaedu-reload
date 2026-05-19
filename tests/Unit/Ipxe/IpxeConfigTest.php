<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.1 — AC7.1 / T1.7.
 *
 * Tests unitaires du fichier `config/ipxe.php` (D11) — vérifie les valeurs
 * par défaut chargées via le `IpxeServiceProvider::register()`.
 */
class IpxeConfigTest extends TestCase
{
    #[Test]
    public function it_loads_se4fs_name_with_fallback(): void
    {
        $value = config('ipxe.se4fs_name');
        // En testing, se4fs_name fallback config('sambaedu.se4fs_name', 'se4fs').
        self::assertIsString($value);
        self::assertNotEmpty($value);
    }

    #[Test]
    public function it_loads_default_timeout_5000_ms(): void
    {
        self::assertSame(5000, (int) config('ipxe.menu.default_timeout_ms'));
    }

    #[Test]
    public function it_loads_unknown_timeout_10000_ms(): void
    {
        self::assertSame(10000, (int) config('ipxe.menu.unknown_timeout_ms'));
    }

    #[Test]
    public function it_loads_resolution_x_and_y(): void
    {
        self::assertSame(1024, (int) config('ipxe.menu.resolution_x'));
        self::assertSame(768, (int) config('ipxe.menu.resolution_y'));
    }

    #[Test]
    public function it_loads_force_uefi_products_array(): void
    {
        $products = (array) config('ipxe.boot_disk.force_uefi_products');
        self::assertNotEmpty($products);
        self::assertGreaterThanOrEqual(13, count($products), 'Au moins 13 entrées iso-legacy');
        self::assertContains('OptiPlex 3050', $products);
        self::assertContains('HP Z240 Tower Workstation', $products);
    }

    #[Test]
    public function it_loads_log_channel_name(): void
    {
        self::assertSame('ipxe', config('ipxe.log.channel'));
    }
}
