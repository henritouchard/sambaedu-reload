<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DepotApplication;
use App\Services\AppStore\PackageInstallerService;
use App\Services\FileManagerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PackageInstallerServiceTest extends TestCase
{
    private PackageInstallerService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/pkg_installer_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        // Configurer le storage_path vers notre tmpDir
        config(['sambaedu.wpkg.storage_path' => $this->tmpDir]);
        config(['sambaedu.wpkg.sync_timeout' => 5]);

        $this->service = app(PackageInstallerService::class);
    }

    protected function tearDown(): void
    {
        // Nettoyer recursivement
        $this->cleanDir($this->tmpDir);
        parent::tearDown();
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->cleanDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ========================================
    // Smoke tests (existants)
    // ========================================

    /** @test */
    public function it_is_instantiable(): void
    {
        $this->assertInstanceOf(PackageInstallerService::class, $this->service);
    }

    /** @test */
    public function it_has_install_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'install'));
    }

    // ========================================
    // downloadXmlRecipe()
    // ========================================

    /** @test */
    public function download_xml_recipe_downloads_to_tmp2_with_correct_naming(): void
    {
        $xmlContent = '<packages><package id="firefox" name="Firefox"/></packages>';

        Http::fake([
            'example.com/firefox.xml' => Http::response($xmlContent, 200),
        ]);

        $depotApp = new DepotApplication();
        $depotApp->app_id = 'firefox';
        $depotApp->xml_url = 'http://example.com/firefox.xml';

        $path = $this->service->downloadXmlRecipe($depotApp);

        $this->assertStringContainsString('/wpkg/tmp2/', $path);
        $this->assertStringContainsString('firefox_', $path);
        $this->assertStringEndsWith('.xml', $path);
        $this->assertFileExists($path);
    }

    /** @test */
    public function download_xml_recipe_creates_tmp2_directory(): void
    {
        $xmlContent = '<packages><package id="test" name="Test"/></packages>';

        Http::fake([
            'example.com/test.xml' => Http::response($xmlContent, 200),
        ]);

        $depotApp = new DepotApplication();
        $depotApp->app_id = 'test';
        $depotApp->xml_url = 'http://example.com/test.xml';

        // Verifier que tmp2 n'existe pas avant
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/wpkg/tmp2');

        $this->service->downloadXmlRecipe($depotApp);

        $this->assertDirectoryExists($this->tmpDir . '/wpkg/tmp2');
    }

    /** @test */
    public function download_xml_recipe_throws_when_url_is_empty(): void
    {
        $depotApp = new DepotApplication();
        $depotApp->app_id = 'nourl';
        $depotApp->xml_url = null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('URL du XML non definie');

        $this->service->downloadXmlRecipe($depotApp);
    }

    // ========================================
    // verifyXmlHash()
    // ========================================

    /** @test */
    public function verify_xml_hash_passes_with_valid_sha512(): void
    {
        $content = 'test xml content';
        $filePath = $this->tmpDir . '/test.xml';
        file_put_contents($filePath, $content);
        $expectedHash = hash_file('sha512', $filePath);

        // Ne doit pas lever d'exception
        $this->service->verifyXmlHash($filePath, $expectedHash);
        $this->assertFileExists($filePath);
    }

    /** @test */
    public function verify_xml_hash_is_case_insensitive(): void
    {
        $content = 'test xml content';
        $filePath = $this->tmpDir . '/test.xml';
        file_put_contents($filePath, $content);
        $expectedHash = strtoupper(hash_file('sha512', $filePath));

        // Ne doit pas lever d'exception
        $this->service->verifyXmlHash($filePath, $expectedHash);
        $this->assertFileExists($filePath);
    }

    /** @test */
    public function verify_xml_hash_throws_on_mismatch(): void
    {
        $filePath = $this->tmpDir . '/test.xml';
        file_put_contents($filePath, 'test xml content');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hash SHA-512 XML invalide');

        $this->service->verifyXmlHash($filePath, 'badhash');
    }

    /** @test */
    public function verify_xml_hash_deletes_file_on_mismatch(): void
    {
        $filePath = $this->tmpDir . '/test.xml';
        file_put_contents($filePath, 'test xml content');

        try {
            $this->service->verifyXmlHash($filePath, 'badhash');
        } catch (\RuntimeException $e) {
            // Attendu
        }

        $this->assertFileDoesNotExist($filePath);
    }

    /** @test */
    public function verify_xml_hash_skips_when_hash_is_null(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('[AppStore] Hash XML absent, verification sautee');

        $filePath = $this->tmpDir . '/test.xml';
        file_put_contents($filePath, 'test xml content');

        $this->service->verifyXmlHash($filePath, null);

        $this->assertFileExists($filePath);
    }

    /** @test */
    public function verify_xml_hash_skips_when_hash_is_empty_string(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('[AppStore] Hash XML absent, verification sautee');

        $filePath = $this->tmpDir . '/test.xml';
        file_put_contents($filePath, 'test xml content');

        $this->service->verifyXmlHash($filePath, '');

        $this->assertFileExists($filePath);
    }

    // ========================================
    // parseDirectives()
    // ========================================

    /** @test */
    public function parse_directives_extracts_all_node_types(): void
    {
        $xml = <<<'XML'
<packages>
  <package id="firefox" name="Mozilla Firefox" revision="125.0"
           compatibilite="6" category2="Internet" priority="5" reboot="false">
    <download url="http://example.com/setup.exe" saveto="wpkg/packages/firefox/setup.exe"
              md5sum="abc123" sha256sum="def456"/>
    <download url="http://example.com/lang.msi" saveto="wpkg/packages/firefox/lang.msi"
              sha256sum="ghi789"/>
    <delete file="wpkg/packages/firefox/old-setup.exe"/>
    <untar tarfile="wpkg/packages/firefox/archive.tar.gz" target="wpkg/packages/firefox/"/>
    <unzip zipfile="wpkg/packages/firefox/archive.zip" target="wpkg/packages/firefox/"/>
  </package>
</packages>
XML;
        $filePath = $this->tmpDir . '/recipe.xml';
        file_put_contents($filePath, $xml);

        $directives = $this->service->parseDirectives($filePath);

        // Packages
        $this->assertCount(1, $directives['packages']);
        $this->assertEquals('firefox', $directives['packages'][0]['id']);
        $this->assertEquals('Mozilla Firefox', $directives['packages'][0]['name']);
        $this->assertEquals('125.0', $directives['packages'][0]['revision']);
        $this->assertEquals('6', $directives['packages'][0]['compatibilite']);
        $this->assertEquals('Internet', $directives['packages'][0]['category2']);
        $this->assertEquals('5', $directives['packages'][0]['priority']);
        $this->assertEquals('false', $directives['packages'][0]['reboot']);

        // Downloads
        $this->assertCount(2, $directives['downloads']);
        $this->assertEquals('http://example.com/setup.exe', $directives['downloads'][0]['url']);
        $this->assertEquals('wpkg/packages/firefox/setup.exe', $directives['downloads'][0]['saveto']);
        $this->assertEquals('abc123', $directives['downloads'][0]['md5sum']);
        $this->assertEquals('def456', $directives['downloads'][0]['sha256sum']);
        $this->assertNull($directives['downloads'][1]['md5sum']);
        $this->assertEquals('ghi789', $directives['downloads'][1]['sha256sum']);

        // Deletes
        $this->assertCount(1, $directives['deletes']);
        $this->assertEquals('wpkg/packages/firefox/old-setup.exe', $directives['deletes'][0]['file']);

        // Untars
        $this->assertCount(1, $directives['untars']);
        $this->assertEquals('wpkg/packages/firefox/archive.tar.gz', $directives['untars'][0]['tarfile']);
        $this->assertEquals('wpkg/packages/firefox/', $directives['untars'][0]['target']);

        // Unzips
        $this->assertCount(1, $directives['unzips']);
        $this->assertEquals('wpkg/packages/firefox/archive.zip', $directives['unzips'][0]['zipfile']);
        $this->assertEquals('wpkg/packages/firefox/', $directives['unzips'][0]['target']);
    }

    /** @test */
    public function parse_directives_returns_empty_arrays_for_xml_without_directives(): void
    {
        $xml = '<packages><package id="empty" name="Empty App" revision="1.0" compatibilite="" category2="" priority="" reboot=""/></packages>';
        $filePath = $this->tmpDir . '/empty.xml';
        file_put_contents($filePath, $xml);

        $directives = $this->service->parseDirectives($filePath);

        $this->assertCount(1, $directives['packages']);
        $this->assertEmpty($directives['downloads']);
        $this->assertEmpty($directives['deletes']);
        $this->assertEmpty($directives['untars']);
        $this->assertEmpty($directives['unzips']);
    }

    /** @test */
    public function parse_directives_handles_multi_packages(): void
    {
        $xml = <<<'XML'
<packages>
  <package id="app1" name="App One" revision="1.0" compatibilite="" category2="" priority="" reboot="">
    <download url="http://example.com/app1.exe" saveto="wpkg/packages/app1/setup.exe" md5sum="" sha256sum=""/>
  </package>
  <package id="app2" name="App Two" revision="2.0" compatibilite="" category2="" priority="" reboot="">
    <download url="http://example.com/app2.exe" saveto="wpkg/packages/app2/setup.exe" md5sum="" sha256sum=""/>
    <delete file="wpkg/packages/app2/old.exe"/>
  </package>
</packages>
XML;
        $filePath = $this->tmpDir . '/multi.xml';
        file_put_contents($filePath, $xml);

        $directives = $this->service->parseDirectives($filePath);

        $this->assertCount(2, $directives['packages']);
        $this->assertEquals('app1', $directives['packages'][0]['id']);
        $this->assertEquals('app2', $directives['packages'][1]['id']);
        $this->assertCount(2, $directives['downloads']);
        $this->assertCount(1, $directives['deletes']);
    }

    /** @test */
    public function parse_directives_throws_on_invalid_xml(): void
    {
        $filePath = $this->tmpDir . '/invalid.xml';
        file_put_contents($filePath, 'not xml at all < > &');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('XML recipe invalide');

        $this->service->parseDirectives($filePath);
    }
}
