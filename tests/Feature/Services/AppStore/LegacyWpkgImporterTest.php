<?php

declare(strict_types=1);

namespace Tests\Feature\Services\AppStore;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\AppStore\AppStoreService;
use App\Services\AppStore\LegacyWpkgImporter;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesAppStoreSchema;

/**
 * Tests de l'import du catalogue WPKG legacy (étape « Importer les applications
 * WPKG » de /admin/sync-from-ad).
 *
 * Convention (cf. AppStoreInstallFlowTest) : schéma AppStore minimal, config
 * WPKG pointée vers un tmp dir, filesystem réel, AppStoreService mocké pour
 * isoler la régénération catalogue/bundle.
 */
class LegacyWpkgImporterTest extends TestCase
{
    use CreatesAppStoreSchema;

    private string $storageDir;

    private string $legacyRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAppStoreSchema();

        $uniq = uniqid('legacy_wpkg_', true);
        $this->storageDir = sys_get_temp_dir() . '/' . $uniq . '_storage';
        $this->legacyRoot = sys_get_temp_dir() . '/' . $uniq . '_legacy';
        @mkdir($this->storageDir . '/wpkg', 0755, true);
        @mkdir($this->legacyRoot . '/wpkg', 0755, true);

        config([
            'sambaedu.wpkg.storage_path' => $this->storageDir,
            'sambaedu.wpkg.packages_xml_path' => $this->storageDir . '/wpkg/packages.xml',
            'sambaedu.wpkg.legacy_packages_xml_path' => $this->legacyRoot . '/wpkg/packages.xml',
            'sambaedu.wpkg.legacy_install_root' => $this->legacyRoot,
        ]);

        // Isole la régénération catalogue/bundle (testée ailleurs).
        $this->mock(AppStoreService::class, function ($mock): void {
            $mock->shouldReceive('updateLocalPackagesXml')->andReturnNull();
        });

        Log::spy();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->storageDir);
        $this->rrmdir($this->legacyRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function writeLegacyCatalog(string $xml): void
    {
        file_put_contents($this->legacyRoot . '/wpkg/packages.xml', $xml);
    }

    private function sampleCatalog(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<packages>
  <package id="firefox" name="Mozilla Firefox" revision="115.0" reboot="false" priority="10" category2="Internet" compatibilite="7">
    <download url="http://example.test/ff.exe" saveto="softwares/firefox/firefox.exe" />
    <install cmd='"%SOFTWARE%\firefox\firefox.exe" /S' />
  </package>
  <package id="7zip" name="7-Zip" revision="23.01" category2="Utilitaires" compatibilite="7">
    <download url="http://example.test/7z.exe" saveto="softwares/7zip/7z.exe" />
    <install cmd='"%SOFTWARE%\7zip\7z.exe" /S' />
  </package>
</packages>
XML;
    }

    private function importer(): LegacyWpkgImporter
    {
        return app(LegacyWpkgImporter::class);
    }

    #[Test]
    public function it_imports_applications_and_copies_binaries(): void
    {
        $this->writeLegacyCatalog($this->sampleCatalog());
        // Binaires présents côté legacy uniquement → doivent être copiés vers SE5.
        @mkdir($this->legacyRoot . '/softwares/firefox', 0755, true);
        @mkdir($this->legacyRoot . '/softwares/7zip', 0755, true);
        file_put_contents($this->legacyRoot . '/softwares/firefox/firefox.exe', 'FFBIN');
        file_put_contents($this->legacyRoot . '/softwares/7zip/7z.exe', '7ZBIN');

        $stats = $this->importer()->importFromLegacy();

        $this->assertSame(2, $stats['created']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(2, $stats['files_copied']);
        $this->assertSame(0, $stats['files_missing']);
        $this->assertTrue($stats['catalog_regenerated']);

        $firefox = Application::where('app_id', 'firefox')->first();
        $this->assertNotNull($firefox);
        $this->assertSame('Mozilla Firefox', $firefox->name);
        $this->assertSame('115.0', $firefox->version);
        $this->assertSame('Internet', $firefox->category);
        $this->assertSame('7', $firefox->compatibility);
        $this->assertSame(ApplicationStatus::Installed, $firefox->status);
        $this->assertStringContainsString('<package', (string) $firefox->xml);
        $this->assertStringContainsString('firefox.exe', (string) $firefox->xml);

        // Binaires effectivement placés au chemin attendu par SE5.
        $this->assertFileExists($this->storageDir . '/softwares/firefox/firefox.exe');
        $this->assertFileExists($this->storageDir . '/softwares/7zip/7z.exe');
    }

    #[Test]
    public function it_verifies_files_already_in_place_when_roots_match(): void
    {
        // Migration in-place : racine legacy == stockage SE5.
        config(['sambaedu.wpkg.legacy_install_root' => $this->storageDir]);

        $this->writeLegacyCatalog($this->sampleCatalog());
        @mkdir($this->storageDir . '/softwares/firefox', 0755, true);
        @mkdir($this->storageDir . '/softwares/7zip', 0755, true);
        file_put_contents($this->storageDir . '/softwares/firefox/firefox.exe', 'FFBIN');
        file_put_contents($this->storageDir . '/softwares/7zip/7z.exe', '7ZBIN');

        $stats = $this->importer()->importFromLegacy();

        $this->assertSame(2, $stats['files_present']);
        $this->assertSame(0, $stats['files_copied']);
        $this->assertSame(0, $stats['files_missing']);
    }

    #[Test]
    public function it_counts_missing_binaries(): void
    {
        $this->writeLegacyCatalog($this->sampleCatalog());
        // Aucun binaire ni côté legacy ni côté SE5.

        $stats = $this->importer()->importFromLegacy();

        $this->assertSame(2, $stats['created']);
        $this->assertSame(2, $stats['files_missing']);
        $this->assertSame(0, $stats['files_copied']);
    }

    #[Test]
    public function it_is_idempotent_and_preserves_existing_recipe(): void
    {
        $this->writeLegacyCatalog($this->sampleCatalog());
        $this->importer()->importFromLegacy();

        // Recette retouchée manuellement côté SE5 entre deux runs.
        Application::where('app_id', 'firefox')->update(['xml' => 'SENTINEL_RECIPE']);

        $stats = $this->importer()->importFromLegacy();

        $this->assertSame(0, $stats['created']);
        $this->assertSame(2, $stats['updated']);
        $this->assertSame(2, Application::count());
        // La recette existante n'est PAS réécrite.
        $this->assertSame('SENTINEL_RECIPE', Application::where('app_id', 'firefox')->value('xml'));
    }

    #[Test]
    public function it_still_verifies_files_on_rerun_after_source_catalog_stripped(): void
    {
        // Migration in-place : binaires présents au chemin SE5.
        config(['sambaedu.wpkg.legacy_install_root' => $this->storageDir]);
        $this->writeLegacyCatalog($this->sampleCatalog());
        @mkdir($this->storageDir . '/softwares/firefox', 0755, true);
        @mkdir($this->storageDir . '/softwares/7zip', 0755, true);
        file_put_contents($this->storageDir . '/softwares/firefox/firefox.exe', 'FFBIN');
        file_put_contents($this->storageDir . '/softwares/7zip/7z.exe', '7ZBIN');

        $first = $this->importer()->importFromLegacy();
        $this->assertSame(2, $first['files_present']);

        // SE5 a entre-temps régénéré le packages.xml source en strippant les
        // directives <download> (cas réel : updateLocalPackagesXml écrit ce
        // même chemin). La recette en DB, elle, est préservée.
        $this->writeLegacyCatalog("<?xml version=\"1.0\"?>\n<packages>\n"
            . "<package id=\"firefox\" name=\"Mozilla Firefox\" revision=\"115.0\" />\n"
            . "<package id=\"7zip\" name=\"7-Zip\" revision=\"23.01\" />\n"
            . "</packages>\n");

        // Re-run : placement lu depuis Application.xml (recette persistée, avec
        // downloads) → fichiers toujours vérifiés (F1).
        $second = $this->importer()->importFromLegacy();
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['files_present']);
    }

    #[Test]
    public function it_handles_missing_catalog_gracefully(): void
    {
        config(['sambaedu.wpkg.legacy_packages_xml_path' => $this->legacyRoot . '/wpkg/does-not-exist.xml']);

        $stats = $this->importer()->importFromLegacy();

        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, $stats['updated']);
        $this->assertFalse($stats['catalog_regenerated']);
        $this->assertSame(0, Application::count());
    }
}
