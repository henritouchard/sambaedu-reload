<?php

declare(strict_types=1);

namespace Tests\Unit\SystemStatus;

use App\SystemStatus\Distro;
use App\SystemStatus\DistroInventoryService;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unitaires de {@see DistroInventoryService} — inventaire filesystem
 * read-only des distros installables, contre un répertoire temporaire.
 */
class DistroInventoryServiceTest extends TestCase
{
    private string $basePath;

    private Filesystem $fs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fs = new Filesystem();
        $this->basePath = sys_get_temp_dir() . '/se5-distro-inventory-' . uniqid();
        $this->fs->makeDirectory($this->basePath, 0755, true);
        config(['ipxe.iso_management.deployed_os_base_path' => $this->basePath]);
    }

    protected function tearDown(): void
    {
        $this->fs->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    private function touchMarker(string $relative): void
    {
        $path = $this->basePath . '/' . $relative;
        $this->fs->makeDirectory(dirname($path), 0755, true, true);
        $this->fs->put($path, 'x');
    }

    /** @return array<string, mixed> */
    private function itemFor(Distro $distro): array
    {
        $items = app(DistroInventoryService::class)->list();
        foreach ($items as $item) {
            if ($item['distro'] === $distro) {
                return $item;
            }
        }
        self::fail("Distro {$distro->value} absente de l'inventaire.");
    }

    #[Test]
    public function it_lists_all_distros_even_when_root_is_empty(): void
    {
        $items = app(DistroInventoryService::class)->list();

        self::assertCount(count(Distro::cases()), $items);
        foreach ($items as $item) {
            self::assertFalse($item['available']);
            self::assertNotEmpty($item['missing']);
        }
    }

    #[Test]
    public function it_reports_debian_available_when_kernel_and_initrd_present(): void
    {
        $this->touchMarker('debian-installer/amd64/linux');
        $this->touchMarker('debian-installer/amd64/initrd.gz');

        $item = $this->itemFor(Distro::Debian);

        self::assertTrue($item['available']);
        self::assertSame([], $item['missing']);
        self::assertTrue($item['installable']);
    }

    #[Test]
    public function it_reports_partial_win10_as_missing_boot_wim(): void
    {
        $this->touchMarker('Win10/version');

        $item = $this->itemFor(Distro::Win10);

        self::assertFalse($item['available']);
        self::assertSame(['Win10/sources/boot.wim'], $item['missing']);
        // Windows ne s'installe pas via le job script (orchestrateur dédié).
        self::assertFalse($item['installable']);
    }

    #[Test]
    public function it_survives_missing_base_path(): void
    {
        config(['ipxe.iso_management.deployed_os_base_path' => $this->basePath . '/does-not-exist']);

        $items = app(DistroInventoryService::class)->list();

        self::assertCount(count(Distro::cases()), $items);
        foreach ($items as $item) {
            self::assertFalse($item['available']);
        }
    }

    #[Test]
    public function it_only_exposes_whitelisted_install_scripts(): void
    {
        // Garde-fou sécurité : tous les scripts de la whitelist enum vivent
        // sous /usr/share/sambaedu/scripts/ (aucun chemin arbitraire).
        foreach (Distro::cases() as $distro) {
            $script = $distro->installScriptPath();
            if ($script !== null) {
                self::assertStringStartsWith('/usr/share/sambaedu/scripts/install-', $script);
            }
        }
    }
}
