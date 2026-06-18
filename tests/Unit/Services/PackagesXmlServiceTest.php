<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\AppStore\PackagesXmlService;
use Tests\TestCase;
use Tests\Traits\CreatesAppStoreSchema;
use PHPUnit\Framework\Attributes\Test;

class PackagesXmlServiceTest extends TestCase
{
    use CreatesAppStoreSchema;

    private PackagesXmlService $service;
    private string $testStoragePath;

    private string $testPackagesXmlPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAppStoreSchema();

        $this->testStoragePath = sys_get_temp_dir() . '/test-wpkg-' . uniqid();
        mkdir($this->testStoragePath, 0755, true);

        $this->testPackagesXmlPath = $this->testStoragePath . '/packages.xml';

        config(['sambaedu.wpkg.storage_path' => $this->testStoragePath]);
        config(['sambaedu.wpkg.packages_xml_path' => $this->testPackagesXmlPath]);

        $this->service = new PackagesXmlService();
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (file_exists($this->testPackagesXmlPath)) {
            unlink($this->testPackagesXmlPath);
        }
        // Also clean any subdirectory-based paths
        $this->cleanDir($this->testStoragePath);

        $this->dropAppStoreSchema();
        parent::tearDown();
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->cleanDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    #[Test]
    public function it_is_instantiable(): void
    {
        $this->assertInstanceOf(PackagesXmlService::class, $this->service);
    }

    #[Test]
    public function it_has_regenerate_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'regenerate'));
    }

    #[Test]
    public function regenerate_creates_packages_xml_with_installed_apps(): void
    {
        Application::create([
            'app_id' => 'test-app',
            'name' => 'Test App',
            'version' => '1.0',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="test-app" name="Test App" revision="1.0"/>',
        ]);

        $this->service->regenerate();

        $xmlPath = $this->testPackagesXmlPath;
        $this->assertFileExists($xmlPath);

        $content = file_get_contents($xmlPath);
        $this->assertStringContainsString('test-app', $content);
        $this->assertStringContainsString('Test App', $content);
    }

    #[Test]
    public function regenerate_creates_empty_xml_when_no_installed_apps(): void
    {
        // Ensure no installed apps
        Application::where('status', ApplicationStatus::Installed)->delete();

        $this->service->regenerate();

        $xmlPath = $this->testPackagesXmlPath;
        $this->assertFileExists($xmlPath);

        $dom = new \DOMDocument();
        $dom->load($xmlPath);
        $root = $dom->documentElement;
        $this->assertEquals('packages', $root->tagName);
        $this->assertEquals(0, $root->childNodes->length);
    }

    #[Test]
    public function regenerate_sorts_packages_alphabetically_by_app_id(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        Application::create([
            'app_id' => 'zfirefox',
            'name' => 'Firefox',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="zfirefox" name="Firefox" revision="1.0"/>',
        ]);
        Application::create([
            'app_id' => 'alibre',
            'name' => 'LibreOffice',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="alibre" name="LibreOffice" revision="1.0"/>',
        ]);
        Application::create([
            'app_id' => 'mchrome',
            'name' => 'Chrome',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="mchrome" name="Chrome" revision="1.0"/>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);
        $packages = $dom->getElementsByTagName('package');

        $this->assertEquals(3, $packages->length);
        $this->assertEquals('alibre', $packages->item(0)->getAttribute('id'));
        $this->assertEquals('mchrome', $packages->item(1)->getAttribute('id'));
        $this->assertEquals('zfirefox', $packages->item(2)->getAttribute('id'));
    }

    #[Test]
    public function regenerate_strips_sambaedu_nodes_from_output(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        Application::create([
            'app_id' => 'test-strip',
            'name' => 'Test Strip',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="test-strip" name="Test Strip" revision="1.0">
                <check type="file" condition="exists" path="%ProgramFiles%\test\test.exe"/>
                <install cmd="msiexec /i test.msi"/>
                <download url="http://example.com/test.msi" target="%TEMP%"/>
                <delete path="%TEMP%\test.msi"/>
                <untar file="%TEMP%\test.tar.gz" target="%ProgramFiles%\test"/>
                <unzip file="%TEMP%\test.zip" target="%ProgramFiles%\test"/>
            </package>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        $this->assertEquals(0, $dom->getElementsByTagName('download')->length);
        $this->assertEquals(0, $dom->getElementsByTagName('delete')->length);
        $this->assertEquals(0, $dom->getElementsByTagName('untar')->length);
        $this->assertEquals(0, $dom->getElementsByTagName('unzip')->length);
        // Standard WPKG nodes should remain
        $this->assertEquals(1, $dom->getElementsByTagName('check')->length);
        $this->assertEquals(1, $dom->getElementsByTagName('install')->length);
    }

    #[Test]
    public function regenerate_preserves_xml_recipe_in_database(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        $originalXml = '<package id="test-preserve" name="Test Preserve" revision="1.0">
            <download url="http://example.com/test.msi" target="%TEMP%"/>
            <delete path="%TEMP%\test.msi"/>
            <untar file="%TEMP%\test.tar.gz" target="%ProgramFiles%\test"/>
            <unzip file="%TEMP%\test.zip" target="%ProgramFiles%\test"/>
            <install cmd="msiexec /i test.msi"/>
        </package>';

        $app = Application::create([
            'app_id' => 'test-preserve',
            'name' => 'Test Preserve',
            'status' => ApplicationStatus::Installed,
            'xml' => $originalXml,
        ]);

        $this->service->regenerate();

        // Re-fetch from DB
        $app->refresh();
        $this->assertStringContainsString('<download', $app->xml);
        $this->assertStringContainsString('<delete', $app->xml);
        $this->assertStringContainsString('<untar', $app->xml);
        $this->assertStringContainsString('<unzip', $app->xml);
    }

    /**
     * Story 27.6 (Bug B / AC1, AC5) — recipes à racine <packages> WRAPPER × N →
     * le catalogue régénéré DOIT être à plat : UNE seule racine <packages> et N
     * <package> ENFANTS DIRECTS de la racine. Sur le code buggé (import du wrapper
     * <packages>), ce test échouerait : la racine contiendrait N <packages>
     * imbriqués et 0 <package> enfant direct (l'engine wpkg-se4.js verrait 0 package).
     */
    #[Test]
    public function regenerate_flattens_wrapper_recipes_into_single_packages_root(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        // Recipes à racine <packages> wrapper (cas courant des dépôts SE4).
        Application::create([
            'app_id' => 'aapp',
            'name' => 'A App',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages><package id="aapp" name="A App" revision="1.0"/></packages>',
        ]);
        Application::create([
            'app_id' => 'bapp',
            'name' => 'B App',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages><package id="bapp" name="B App" revision="2.0"/></packages>',
        ]);
        Application::create([
            'app_id' => 'capp',
            'name' => 'C App',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages><package id="capp" name="C App" revision="3.0"/></packages>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        // UNE seule racine <packages> (pas N <packages> imbriqués).
        $this->assertEquals(1, $dom->getElementsByTagName('packages')->length);

        // N <package> ENFANTS DIRECTS de la racine (à plat).
        $root = $dom->documentElement;
        $this->assertEquals('packages', $root->localName);
        $directPackages = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'package') {
                $directPackages[] = $child->getAttribute('id');
            }
        }
        $this->assertCount(3, $directPackages);
        $this->assertSame(['aapp', 'bapp', 'capp'], $directPackages);
    }

    /**
     * Story 27.6 (AC1) — un recipe à racine <package> DIRECTE est aussi importé à
     * plat (cas (b) de la discrimination de racine).
     */
    #[Test]
    public function regenerate_handles_direct_package_root_recipe(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        Application::create([
            'app_id' => 'directapp',
            'name' => 'Direct App',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="directapp" name="Direct App" revision="1.0"/>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        $this->assertEquals(1, $dom->getElementsByTagName('packages')->length);
        $root = $dom->documentElement;
        $this->assertEquals(1, $root->getElementsByTagName('package')->length);
        $directPackages = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'package') {
                $directPackages[] = $child->getAttribute('id');
            }
        }
        $this->assertSame(['directapp'], $directPackages);
    }

    /**
     * Story 27.6 (AC1) — le strip des nœuds SambaEdu reste appliqué PAR <package>,
     * y compris quand le recipe est un wrapper <packages> (le strip opère sur le
     * <package> importé, pas sur le wrapper).
     */
    #[Test]
    public function regenerate_strips_sambaedu_nodes_from_wrapper_recipe_per_package(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        Application::create([
            'app_id' => 'wrapped-strip',
            'name' => 'Wrapped Strip',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages><package id="wrapped-strip" name="Wrapped Strip" revision="1.0">
                <check type="file" condition="exists" path="%ProgramFiles%\test\test.exe"/>
                <install cmd="msiexec /i test.msi"/>
                <download url="http://example.com/test.msi" target="%TEMP%"/>
                <delete path="%TEMP%\test.msi"/>
                <untar file="%TEMP%\test.tar.gz" target="%ProgramFiles%\test"/>
                <unzip file="%TEMP%\test.zip" target="%ProgramFiles%\test"/>
            </package></packages>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        // Strip effectif.
        $this->assertEquals(0, $dom->getElementsByTagName('download')->length);
        $this->assertEquals(0, $dom->getElementsByTagName('delete')->length);
        $this->assertEquals(0, $dom->getElementsByTagName('untar')->length);
        $this->assertEquals(0, $dom->getElementsByTagName('unzip')->length);
        // Nœuds WPKG standard conservés, à plat.
        $this->assertEquals(1, $dom->getElementsByTagName('check')->length);
        $this->assertEquals(1, $dom->getElementsByTagName('install')->length);
        // Toujours à plat : 1 racine <packages>, 1 <package> enfant direct.
        $this->assertEquals(1, $dom->getElementsByTagName('packages')->length);
        $this->assertEquals(1, $dom->documentElement->getElementsByTagName('package')->length);
    }

    /**
     * Story 27.6 (AC1, AC5 — lacune relevée en review #4) — un SEUL recipe à
     * wrapper <packages> contenant PLUSIEURS <package> → tous remontés à plat sous
     * l'unique racine. Exerce la collecte des <package> ENFANTS DIRECTS du wrapper
     * (et non `getElementsByTagName('package')` récursif, qui ramasserait des
     * <package> d'autres sous-arbres).
     */
    #[Test]
    public function regenerate_flattens_multiple_packages_within_a_single_wrapper(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        Application::create([
            'app_id' => 'firefox-suite',
            'name' => 'Firefox Suite',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages>'
                . '<package id="firefox-esr" name="Firefox ESR" revision="1.0"/>'
                . '<package id="firefox-lang-fr" name="Firefox FR" revision="1.0"/>'
                . '</packages>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        // Toujours UNE seule racine <packages>.
        $this->assertEquals(1, $dom->getElementsByTagName('packages')->length);

        // Les 2 <package> du wrapper sont à plat, enfants DIRECTS de la racine.
        $root = $dom->documentElement;
        $directPackages = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'package') {
                $directPackages[] = $child->getAttribute('id');
            }
        }
        $this->assertSame(['firefox-esr', 'firefox-lang-fr'], $directPackages);
    }

    /**
     * Story 27.6 (AC1) — un recipe valide mais SANS <package> (ex. wrapper ne
     * contenant que des <check>) est skippé+loggé sans casser la génération des
     * autres apps.
     */
    #[Test]
    public function regenerate_skips_recipe_without_package_and_keeps_others(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        Application::create([
            'app_id' => 'no-package',
            'name' => 'No Package',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages><check type="file" condition="exists" path="%ProgramFiles%\test"/></packages>',
        ]);
        Application::create([
            'app_id' => 'real-app',
            'name' => 'Real App',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="real-app" name="Real App" revision="1.0"/>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        $root = $dom->documentElement;
        $directPackages = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'package') {
                $directPackages[] = $child->getAttribute('id');
            }
        }
        // Le recipe sans <package> est skippé ; l'app valide reste générée.
        $this->assertSame(['real-app'], $directPackages);
        $this->assertEquals(1, $dom->getElementsByTagName('packages')->length);
    }

    #[Test]
    public function regenerate_uses_config_packages_xml_path(): void
    {
        $customPath = sys_get_temp_dir() . '/test-custom-wpkg-' . uniqid() . '/custom/packages.xml';

        config(['sambaedu.wpkg.packages_xml_path' => $customPath]);
        $service = new PackagesXmlService();

        Application::create([
            'app_id' => 'test-path',
            'name' => 'Test Path',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="test-path" name="Test Path" revision="1.0"/>',
        ]);

        $service->regenerate();

        $this->assertFileExists($customPath);

        // Cleanup
        unlink($customPath);
        rmdir(dirname($customPath));
        rmdir(dirname(dirname($customPath)));
    }
}
