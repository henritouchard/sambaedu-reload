<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\AppStore\PackagesXmlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PackagesXmlServiceTest extends TestCase
{
    use DatabaseTransactions;

    private PackagesXmlService $service;
    private string $testStoragePath;

    private string $testPackagesXmlPath;

    protected function setUp(): void
    {
        parent::setUp();

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
