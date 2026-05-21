<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Iso\Services;

use App\Ipxe\Iso\Services\WindowsIsoSourcesReader;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.6 — AC3.* — Tests unitaires de WindowsIsoSourcesReader.
 *
 * Pattern : on utilise un répertoire temporaire isolé (`sys_get_temp_dir()`)
 * pour simuler les 4 dossiers `Win{10,11}{,-old}/version`. Pas de mock complexe.
 */
class WindowsIsoSourcesReaderTest extends TestCase
{
    private string $tmpBase;
    private WindowsIsoSourcesReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpBase = sys_get_temp_dir() . '/ipxe-iso-test-' . uniqid();
        mkdir($this->tmpBase, 0755, true);

        config([
            'ipxe.iso_management.deployed_os_base_path' => $this->tmpBase,
            'ipxe.iso_management.version_file_name'     => 'version',
        ]);

        $this->reader = new WindowsIsoSourcesReader(new Filesystem());
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpBase)) {
            $this->rrmdir($this->tmpBase);
        }
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') as $f) {
            is_dir($f) ? $this->rrmdir($f) : unlink($f);
        }
        rmdir($dir);
    }

    private function seedVersion(string $folder, string $content): void
    {
        mkdir($this->tmpBase . '/' . $folder, 0755, true);
        file_put_contents($this->tmpBase . '/' . $folder . '/version', $content);
    }

    #[Test]
    public function it_returns_all_four_versions_when_present(): void
    {
        $this->seedVersion('Win10',     "Win10_22H2.iso\n");
        $this->seedVersion('Win10-old', "Win10_21H2.iso");
        $this->seedVersion('Win11',     "Win11_24H2.iso");
        $this->seedVersion('Win11-old', "Win11_23H2.iso");

        $result = $this->reader->list();

        self::assertSame('Win10_22H2.iso', $result['win10']['current']);
        self::assertSame('Win10_21H2.iso', $result['win10']['old']);
        self::assertSame('Win11_24H2.iso', $result['win11']['current']);
        self::assertSame('Win11_23H2.iso', $result['win11']['old']);
    }

    #[Test]
    public function it_returns_null_for_missing_version_file(): void
    {
        $this->seedVersion('Win10', "Win10_22H2.iso");
        // Pas de Win10-old, Win11, Win11-old.

        $result = $this->reader->list();

        self::assertSame('Win10_22H2.iso', $result['win10']['current']);
        self::assertNull($result['win10']['old']);
        self::assertNull($result['win11']['current']);
        self::assertNull($result['win11']['old']);
    }

    #[Test]
    public function it_returns_null_for_empty_version_file(): void
    {
        // Fichier existe mais vide.
        $this->seedVersion('Win11', '');
        $this->seedVersion('Win10', '   '); // que des espaces.

        $result = $this->reader->list();

        self::assertNull($result['win11']['current']);
        self::assertNull($result['win10']['current']);
    }

    #[Test]
    public function it_trims_whitespace_and_newlines(): void
    {
        $this->seedVersion('Win11', "  Win11_24H2.iso  \n\r");

        $result = $this->reader->list();

        self::assertSame('Win11_24H2.iso', $result['win11']['current']);
    }

    #[Test]
    public function it_returns_all_nulls_and_logs_warning_when_base_path_missing(): void
    {
        $this->rrmdir($this->tmpBase);

        $result = $this->reader->list();

        self::assertSame([
            'win10' => ['current' => null, 'old' => null],
            'win11' => ['current' => null, 'old' => null],
        ], $result);
    }

    #[Test]
    public function it_handles_only_win11_current_present(): void
    {
        $this->seedVersion('Win11', "Win11_24H2.iso");

        $result = $this->reader->list();

        self::assertSame('Win11_24H2.iso', $result['win11']['current']);
        self::assertNull($result['win10']['current']);
        self::assertNull($result['win10']['old']);
        self::assertNull($result['win11']['old']);
    }

    #[Test]
    public function it_returns_array_structure_with_expected_keys(): void
    {
        $result = $this->reader->list();

        self::assertArrayHasKey('win10', $result);
        self::assertArrayHasKey('win11', $result);
        self::assertArrayHasKey('current', $result['win10']);
        self::assertArrayHasKey('old', $result['win10']);
        self::assertArrayHasKey('current', $result['win11']);
        self::assertArrayHasKey('old', $result['win11']);
    }
}
