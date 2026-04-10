<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\AppStore\PackagesXmlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PackagesXmlServiceTest extends TestCase
{
    use DatabaseTransactions;

    private PackagesXmlService $service;
    private string $testStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testStoragePath = sys_get_temp_dir() . '/test-wpkg-' . uniqid();
        mkdir($this->testStoragePath, 0755, true);

        config(['sambaedu.wpkg.storage_path' => $this->testStoragePath]);

        $this->service = new PackagesXmlService();
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $xmlFile = $this->testStoragePath . '/packages.xml';
        if (file_exists($xmlFile)) {
            unlink($xmlFile);
        }
        if (is_dir($this->testStoragePath)) {
            rmdir($this->testStoragePath);
        }

        parent::tearDown();
    }

    /** @test */
    public function it_is_instantiable(): void
    {
        $this->assertInstanceOf(PackagesXmlService::class, $this->service);
    }

    /** @test */
    public function it_has_regenerate_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'regenerate'));
    }

    /** @test */
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

        $xmlPath = $this->testStoragePath . '/packages.xml';
        $this->assertFileExists($xmlPath);

        $content = file_get_contents($xmlPath);
        $this->assertStringContainsString('test-app', $content);
        $this->assertStringContainsString('Test App', $content);
    }

    /** @test */
    public function regenerate_creates_empty_xml_when_no_installed_apps(): void
    {
        // Ensure no installed apps
        Application::where('status', ApplicationStatus::Installed)->delete();

        $this->service->regenerate();

        $xmlPath = $this->testStoragePath . '/packages.xml';
        $this->assertFileExists($xmlPath);

        $dom = new \DOMDocument();
        $dom->load($xmlPath);
        $root = $dom->documentElement;
        $this->assertEquals('packages', $root->tagName);
        $this->assertEquals(0, $root->childNodes->length);
    }
}
