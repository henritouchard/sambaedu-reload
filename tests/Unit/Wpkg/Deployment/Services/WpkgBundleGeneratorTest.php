<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Services;

use App\Config\SambaEduConfig;
use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\AppStore\PackagesXmlService;
use App\Wpkg\Deployment\Services\WpkgBundleGenerator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\CreatesAppStoreSchema;

/**
 * Story 27.6 (Bug A / SOURCE UNIQUE) — Tests du sourcing du catalogue du bundle.
 *
 * Couvre :
 *   - le bundle inclut une app présente dans le catalogue MODULE (source unique) ;
 *   - la substitution `SE4FS_NAME` reste appliquée sur le catalogue sourcé du module ;
 *   - la garde structurelle (≠ 1 <packages>) protège le nouveau sourcing
 *     (catalogue module malformé → RuntimeException, non-régression 27.5) ;
 *   - D5 : catalogue module absent → régénéré avant sourcing.
 *
 * Les chemins de prod (`sambaedu.wpkg.packages_xml_path`, `agent.wpkg_bundle_path`,
 * `sambaedu.wpkg.bundle_source_path`) sont surchargés vers des temp dans setUp.
 */
#[Group('wpkg-deploy')]
#[Group('story-27-6')]
class WpkgBundleGeneratorTest extends TestCase
{
    use CreatesAppStoreSchema;

    private string $tmpRoot;
    private string $moduleCatalogPath;
    private string $bundlePath;
    private string $scriptsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAppStoreSchema();

        $this->tmpRoot = sys_get_temp_dir() . '/wpkg-bundle-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);

        // Catalogue module (source unique du catalogue du bundle).
        $moduleDir = $this->tmpRoot . '/module';
        mkdir($moduleDir, 0755, true);
        $this->moduleCatalogPath = $moduleDir . '/packages.xml';

        // Répertoire du bundle (cible Apache statique).
        $this->bundlePath = $this->tmpRoot . '/bundle';

        // Répertoire des scripts versionnés (source VERBATIM).
        $this->scriptsDir = $this->tmpRoot . '/scripts';
        mkdir($this->scriptsDir, 0755, true);
        foreach (['wpkg-se4.js', 'wpkg-client.vbs', 'wpkg.cmd'] as $script) {
            file_put_contents($this->scriptsDir . '/' . $script, "// stub {$script}\n");
        }

        config([
            'sambaedu.wpkg.packages_xml_path' => $this->moduleCatalogPath,
            'sambaedu.wpkg.storage_path' => $moduleDir,
            'agent.wpkg_bundle_path' => $this->bundlePath,
            'sambaedu.wpkg.bundle_source_path' => $this->scriptsDir,
        ]);

        $this->setSe4fsName('mon-serveur-fs');
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tmpRoot);
        $this->setRawConfig(null);
        $this->dropAppStoreSchema();
        parent::tearDown();
    }

    private function generator(): WpkgBundleGenerator
    {
        return new WpkgBundleGenerator(new SambaEduConfig(), new PackagesXmlService());
    }

    /**
     * AC2/AC5 — une app présente dans le catalogue MODULE apparaît dans le
     * packages.xml du bundle après génération.
     */
    #[Test]
    public function bundle_includes_an_app_from_the_module_catalog(): void
    {
        file_put_contents(
            $this->moduleCatalogPath,
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<packages>'
            . '<package id="ganttproject" name="GanttProject" revision="3.0"/>'
            . '<package id="notepadpp" name="Notepad++" revision="8.0"/>'
            . '</packages>',
        );

        $this->generator()->generate();

        $bundleCatalog = $this->bundlePath . '/packages.xml';
        $this->assertFileExists($bundleCatalog);

        $dom = new \DOMDocument();
        $dom->load($bundleCatalog);

        $ids = [];
        foreach ($dom->getElementsByTagName('package') as $pkg) {
            $ids[] = $pkg->getAttribute('id');
        }
        $this->assertContains('ganttproject', $ids);
        $this->assertContains('notepadpp', $ids);
        // À plat : 1 racine <packages>.
        $this->assertEquals(1, $dom->getElementsByTagName('packages')->length);
    }

    /**
     * AC2/AC5 — la substitution SE4FS_NAME continue de s'appliquer sur le
     * catalogue sourcé du module (non-régression 27.5).
     */
    #[Test]
    public function se4fs_name_substitution_is_applied_on_module_sourced_catalog(): void
    {
        file_put_contents(
            $this->moduleCatalogPath,
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<packages>'
            . '<variable name="SE4FS_NAME" value="se4fs_name" source="sambaedu"/>'
            . '<package id="ganttproject" name="GanttProject" revision="3.0"/>'
            . '</packages>',
        );

        $this->generator()->generate();

        $content = file_get_contents($this->bundlePath . '/packages.xml');
        $this->assertStringContainsString('value="mon-serveur-fs"', $content);
        $this->assertStringNotContainsString('value="se4fs_name"', $content);
    }

    /**
     * AC2/AC5 (M1 — angle mort relevé au second avis) — un catalogue module RÉEL
     * ne porte plus de `<variable source="sambaedu">` à la RACINE (le catalogue
     * hand-curated supprimé en 27.6/D2 la portait là) : les recipes la portent
     * PAR <package>. Ce test prouve que la substitution SE4FS_NAME s'applique bien
     * à une variable IMBRIQUÉE dans un <package> (chemin réel), pas seulement au
     * niveau racine — `getElementsByTagName('variable')` étant récursif, le strip
     * de substitution la trouve où qu'elle soit.
     */
    #[Test]
    public function se4fs_name_substitution_is_applied_on_per_package_variable(): void
    {
        file_put_contents(
            $this->moduleCatalogPath,
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<packages>'
            . '<package id="firefox-esr" name="Firefox ESR" revision="1.0">'
            . '<variable name="SE4FS_NAME" value="se4fs_name" source="sambaedu"/>'
            . '</package>'
            . '</packages>',
        );

        $this->generator()->generate();

        $content = file_get_contents($this->bundlePath . '/packages.xml');
        $this->assertStringContainsString('value="mon-serveur-fs"', $content);
        $this->assertStringNotContainsString('value="se4fs_name"', $content);

        // La variable reste IMBRIQUÉE dans le <package> (jamais remontée à la racine).
        $dom = new \DOMDocument();
        $dom->load($this->bundlePath . '/packages.xml');
        $pkg = $dom->getElementsByTagName('package')->item(0);
        $this->assertNotNull($pkg);
        $this->assertEquals(1, $pkg->getElementsByTagName('variable')->length);
    }

    /**
     * AC2/AC5 — non-régression de la garde structurelle : un catalogue module
     * MALFORMÉ (double <packages> imbriqué) fait échouer fort la génération du
     * bundle (jamais de faux succès / catalogue inexploitable servi en silence).
     */
    #[Test]
    public function malformed_module_catalog_makes_bundle_generation_fail_hard(): void
    {
        file_put_contents(
            $this->moduleCatalogPath,
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<packages>'
            . '<packages><package id="ganttproject"/></packages>'
            . '<packages><package id="notepadpp"/></packages>'
            . '</packages>',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mal structuré');

        $this->generator()->generate();
    }

    /**
     * AC2/AC5 (D5) — catalogue module absent → régénéré via PackagesXmlService
     * avant sourcing. Une app installée en DB se retrouve donc dans le bundle.
     */
    #[Test]
    public function absent_module_catalog_is_regenerated_before_sourcing(): void
    {
        // Pas de fichier catalogue module sur disque.
        $this->assertFileDoesNotExist($this->moduleCatalogPath);

        Application::where('status', ApplicationStatus::Installed)->delete();
        Application::create([
            'app_id' => 'ganttproject',
            'name' => 'GanttProject',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages><package id="ganttproject" name="GanttProject" revision="3.0"/></packages>',
        ]);

        $this->generator()->generate();

        // Le catalogue module a été régénéré (D5)...
        $this->assertFileExists($this->moduleCatalogPath);

        // ...et le bundle contient l'app installée.
        $dom = new \DOMDocument();
        $dom->load($this->bundlePath . '/packages.xml');
        $ids = [];
        foreach ($dom->getElementsByTagName('package') as $pkg) {
            $ids[] = $pkg->getAttribute('id');
        }
        $this->assertContains('ganttproject', $ids);
        $this->assertEquals(1, $dom->getElementsByTagName('packages')->length);
    }

    /**
     * Story 27.19 (AC3/AC4/AC5) — INTÉGRATION end-to-end : une app %SOFTWARE%
     * installée en DB, catalogue module absent → régénéré (transformation HTTP) →
     * le bundle servi contient le <download> réécrit en HTTP (url SE5 + target
     * %TEMP%, sha retirés) et l'<install> %SOFTWARE%→%TEMP%. Prouve que la livraison
     * full-HTTP traverse bien le sourcing du bundle (PackagesXmlService→bundle).
     */
    #[Test]
    public function bundle_carries_http_rewritten_payload_for_software_recipe(): void
    {
        config(['sambaedu.se4fs_name' => 'se4fs.lan']);
        $this->assertFileDoesNotExist($this->moduleCatalogPath);

        Application::where('status', ApplicationStatus::Installed)->delete();
        Application::create([
            'app_id' => 'id_7zip',
            'name' => '7-Zip',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="id_7zip" name="7-Zip" revision="1.0">'
                . '<check type="file" condition="exists" path="%WinDir%\7za.exe"/>'
                . '<download url="http://deb.sambaedu.org/7za.exe" saveto="packages/7-zip/7za.exe" sha256sum="dead"/>'
                . '<install cmd="xcopy /Y %SOFTWARE%\7-zip\7za.exe %WinDir%\"/>'
                . '</package>',
        ]);

        $this->generator()->generate();

        $dom = new \DOMDocument();
        $dom->load($this->bundlePath . '/packages.xml');

        $download = $dom->getElementsByTagName('download')->item(0);
        $this->assertNotNull($download);
        $this->assertSame('http://se4fs.lan/wpkg/files/7-zip/7za.exe', $download->getAttribute('url'));
        $this->assertSame('7-zip\7za.exe', $download->getAttribute('target'));
        $this->assertFalse($download->hasAttribute('saveto'));
        $this->assertFalse($download->hasAttribute('sha256sum'));

        $install = $dom->getElementsByTagName('install')->item(0);
        $this->assertStringNotContainsString('%SOFTWARE%', $install->getAttribute('cmd'));
        $this->assertStringContainsString('%TEMP%', $install->getAttribute('cmd'));

        // <check> intact (idempotence).
        $check = $dom->getElementsByTagName('check')->item(0);
        $this->assertSame('%WinDir%\7za.exe', $check->getAttribute('path'));
    }

    private function setSe4fsName(string $value): void
    {
        $this->setRawConfig(['se4fs_name' => $value]);
    }

    /**
     * @param array<string, mixed>|null $config
     */
    private function setRawConfig(?array $config): void
    {
        $rc = new ReflectionClass(SambaEduConfig::class);
        $prop = $rc->getProperty('rawConfig');
        $prop->setAccessible(true);
        $prop->setValue(null, $config);
    }

    private function cleanDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->cleanDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
