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

    /* ------------------------------------------------------------------
     * Story 3.2 — AC9.1 / T1.4 — sections admin / maintenance / actions
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_loads_admin_menu_timeout_30000_ms(): void
    {
        self::assertSame(30000, (int) config('ipxe.admin.menu_timeout_ms'));
    }

    #[Test]
    public function it_loads_maintenance_menu_timeout_10000_ms(): void
    {
        self::assertSame(10000, (int) config('ipxe.maintenance.menu_timeout_ms'));
    }

    #[Test]
    public function it_loads_maintenance_background_png_default(): void
    {
        self::assertSame('png/sysrescuecd.png', (string) config('ipxe.maintenance.background_png'));
    }

    #[Test]
    public function it_loads_actions_os_url_default_null(): void
    {
        // Par défaut null (fallback dynamique via Request::getSchemeAndHttpHost).
        self::assertNull(config('ipxe.actions.os_url'));
    }

    #[Test]
    public function it_loads_actions_script_url_default_null(): void
    {
        self::assertNull(config('ipxe.actions.script_url'));
    }

    #[Test]
    public function it_loads_actions_se4install_passwd_config_key(): void
    {
        self::assertSame(
            'sambaedu.se4install_passwd',
            (string) config('ipxe.actions.se4install_passwd_config_key'),
        );
    }

    /* ------------------------------------------------------------------
     * Story 3.3 — AC10.1 / T2.2 — section enrollment
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_loads_enrollment_enabled_true_by_default(): void
    {
        self::assertTrue((bool) config('ipxe.enrollment.enabled'));
    }

    #[Test]
    public function it_loads_enrollment_menu_timeout_10000_ms(): void
    {
        self::assertSame(10000, (int) config('ipxe.enrollment.menu_timeout_ms'));
    }

    #[Test]
    public function it_loads_enrollment_max_rooms_in_menu_50(): void
    {
        self::assertSame(50, (int) config('ipxe.enrollment.max_rooms_in_menu'));
    }

    #[Test]
    public function it_loads_enrollment_max_parcs_in_menu_50(): void
    {
        self::assertSame(50, (int) config('ipxe.enrollment.max_parcs_in_menu'));
    }

    /* ------------------------------------------------------------------
     * Story 3.4 — AC9.4 / T7.7 — section linux
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_loads_linux_enabled_true_by_default(): void
    {
        self::assertTrue((bool) config('ipxe.linux.enabled'));
    }

    #[Test]
    public function it_loads_linux_menu_timeout_10000_ms(): void
    {
        self::assertSame(10000, (int) config('ipxe.linux.menu_timeout_ms'));
    }

    #[Test]
    public function it_loads_linux_default_variant_install_deb_gnome(): void
    {
        self::assertSame('install_deb_gnome', (string) config('ipxe.linux.default_variant'));
    }

    #[Test]
    public function it_loads_linux_menu_items_with_nine_entries(): void
    {
        $items = (array) config('ipxe.linux.menu_items');
        self::assertCount(9, $items);
        $enums = array_map(static fn ($i) => $i['enum'] ?? '', $items);
        self::assertContains('install_deb_gnome', $enums);
        self::assertContains('install_ubuntu64', $enums);
        self::assertContains('install_nird', $enums);
    }

    #[Test]
    public function it_loads_linux_kernel_paths(): void
    {
        $paths = (array) config('ipxe.linux.kernel_paths');
        self::assertSame('/debian-installer/amd64/linux', $paths['debian']);
        self::assertSame('/ubuntu-installer/amd64/linux', $paths['ubuntu']);
    }

    #[Test]
    public function it_loads_linux_allowed_distributions(): void
    {
        $allowed = (array) config('ipxe.linux.allowed_distributions');
        self::assertSame(['debian', 'ubuntu', 'nird'], $allowed);
    }

    #[Test]
    public function it_loads_linux_allowed_variants(): void
    {
        $allowed = (array) config('ipxe.linux.allowed_variants');
        self::assertContains('gnome', $allowed);
        self::assertContains('base', $allowed);
        self::assertContains('cinnamon', $allowed);
    }

    /* ------------------------------------------------------------------
     * Story 3.5 — AC9.1 / AC9.4 — section windows.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_loads_windows_enabled_true_by_default(): void
    {
        self::assertTrue((bool) config('ipxe.windows.enabled'));
    }

    #[Test]
    public function it_loads_windows_menu_timeout_10000(): void
    {
        self::assertSame(10000, (int) config('ipxe.windows.menu_timeout_ms'));
    }

    #[Test]
    public function it_loads_windows_default_variant_install_win11(): void
    {
        self::assertSame('install_win11', config('ipxe.windows.default_variant'));
    }

    #[Test]
    public function it_loads_seven_windows_menu_items(): void
    {
        $items = (array) config('ipxe.windows.menu_items');
        self::assertCount(7, $items);
        $enums = array_column($items, 'enum');
        self::assertContains('install_win10', $enums);
        self::assertContains('install_win11', $enums);
        self::assertContains('install_win11_perso', $enums);
    }

    #[Test]
    public function it_loads_windows_allowed_versions_whitelist(): void
    {
        $allowed = (array) config('ipxe.windows.allowed_versions');
        self::assertSame(['Win10', 'Win11'], $allowed);
    }

    #[Test]
    public function it_loads_windows_unattend_template_path(): void
    {
        $path = (string) config('ipxe.windows.unattend_template_path');
        self::assertNotSame('', $path);
        self::assertFileExists($path, 'Template unattend.xml doit exister à la config path');
    }

    /**
     * Post-review #M8 — défense en profondeur sur `ipxe.linux.kernel_paths.nird`.
     *
     * `IpxeActionResolver::resolveNird()` lit cette clé avec un fallback
     * inline `/nird/casper/vmlinuz`. Si la clé est retirée par mégarde,
     * l'install Nird casse silencieusement (le fallback masque). On gèle
     * la présence + la cohérence (chemin absolu, et clés `debian`/`ubuntu`
     * également définies).
     */
    #[Test]
    public function it_defines_nird_kernel_paths(): void
    {
        $paths = (array) config('ipxe.linux.kernel_paths');
        self::assertNotNull($paths['nird'] ?? null, 'config ipxe.linux.kernel_paths.nird est absente');
        $nird = (string) $paths['nird'];
        self::assertNotSame('', $nird);
        self::assertStringStartsWith('/', $nird, 'kernel_paths.nird doit etre un chemin absolu');

        // Consistency : les paires debian/ubuntu doivent aussi etre presentes.
        self::assertNotNull($paths['debian'] ?? null);
        self::assertNotNull($paths['ubuntu'] ?? null);
    }
}
