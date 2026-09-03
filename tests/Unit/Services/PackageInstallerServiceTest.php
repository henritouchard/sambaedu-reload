<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DepotApplication;
use App\Models\InstallationLog;
use App\Services\AppStore\PackageInstallerService;
use App\Services\FileManagerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

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

    #[Test]
    public function it_is_instantiable(): void
    {
        $this->assertInstanceOf(PackageInstallerService::class, $this->service);
    }

    #[Test]
    public function it_has_install_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'install'));
    }

    // ========================================
    // downloadXmlRecipe()
    // ========================================

    #[Test]
    public function download_xml_recipe_downloads_to_tmp2_with_correct_naming(): void
    {
        $xmlContent = '<packages><package id="firefox" name="Firefox"/></packages>';

        Http::fake([
            'example.com/firefox.xml' => Http::response($xmlContent, 200),
        ]);

        $depotApp = new DepotApplication();
        $depotApp->app_id = 'firefox';
        $depotApp->xml_url = 'http://example.com/firefox.xml';

        $path = $this->service->downloadXmlRecipe($depotApp->app_id, $depotApp->xml_url);

        $this->assertStringContainsString('/wpkg/tmp2/', $path);
        $this->assertStringContainsString('firefox_', $path);
        $this->assertStringEndsWith('.xml', $path);
        $this->assertFileExists($path);
    }

    #[Test]
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

        $this->service->downloadXmlRecipe($depotApp->app_id, $depotApp->xml_url);

        $this->assertDirectoryExists($this->tmpDir . '/wpkg/tmp2');
    }

    #[Test]
    public function download_xml_recipe_throws_when_url_is_empty(): void
    {
        $depotApp = new DepotApplication();
        $depotApp->app_id = 'nourl';
        $depotApp->xml_url = null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('URL du XML non definie');

        $this->service->downloadXmlRecipe($depotApp->app_id, $depotApp->xml_url);
    }

    // ========================================
    // verifyXmlHash()
    // ========================================

    #[Test]
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

    #[Test]
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

    #[Test]
    public function verify_xml_hash_throws_on_mismatch(): void
    {
        $filePath = $this->tmpDir . '/test.xml';
        file_put_contents($filePath, 'test xml content');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hash SHA-512 XML invalide');

        $this->service->verifyXmlHash($filePath, 'badhash');
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function parse_directives_throws_on_invalid_xml(): void
    {
        $filePath = $this->tmpDir . '/invalid.xml';
        file_put_contents($filePath, 'not xml at all < > &');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('XML recipe invalide');

        $this->service->parseDirectives($filePath);
    }

    // ========================================
    // downloadFiles()
    // ========================================

    private function createMockInstallationLog(): InstallationLog
    {
        $log = $this->createMock(InstallationLog::class);
        $log->method('update')->willReturn(true);

        return $log;
    }

    private function createSpyInstallationLog(): InstallationLog&\PHPUnit\Framework\MockObject\MockObject
    {
        $log = $this->createMock(InstallationLog::class);

        return $log;
    }

    #[Test]
    public function download_files_downloads_n_files_to_correct_paths(): void
    {
        $fileContent1 = 'installer content file 1';
        $fileContent2 = 'installer content file 2';

        Http::fake([
            'example.com/setup.exe' => Http::response($fileContent1, 200),
            'example.com/lang.msi' => Http::response($fileContent2, 200),
        ]);

        $downloads = [
            ['url' => 'http://example.com/setup.exe', 'saveto' => 'wpkg/packages/firefox/setup.exe', 'md5sum' => null, 'sha256sum' => null],
            ['url' => 'http://example.com/lang.msi', 'saveto' => 'wpkg/packages/firefox/lang.msi', 'md5sum' => null, 'sha256sum' => null],
        ];

        $log = $this->createMockInstallationLog();
        $this->service->downloadFiles($downloads, $log);

        $this->assertFileExists($this->tmpDir . '/wpkg/packages/firefox/setup.exe');
        $this->assertFileExists($this->tmpDir . '/wpkg/packages/firefox/lang.msi');
        $this->assertEquals($fileContent1, file_get_contents($this->tmpDir . '/wpkg/packages/firefox/setup.exe'));
        $this->assertEquals($fileContent2, file_get_contents($this->tmpDir . '/wpkg/packages/firefox/lang.msi'));
    }

    #[Test]
    public function download_files_uses_sha256_when_available(): void
    {
        $fileContent = 'test sha256 content';
        $sha256 = hash('sha256', $fileContent);

        Http::fake([
            'example.com/setup.exe' => Http::response($fileContent, 200),
        ]);

        $downloads = [
            ['url' => 'http://example.com/setup.exe', 'saveto' => 'wpkg/packages/app/setup.exe', 'md5sum' => 'ignored_md5', 'sha256sum' => $sha256],
        ];

        $log = $this->createMockInstallationLog();

        // Ne doit pas lever d'exception — le hash SHA-256 est correct
        $this->service->downloadFiles($downloads, $log);

        $this->assertFileExists($this->tmpDir . '/wpkg/packages/app/setup.exe');
    }

    #[Test]
    public function download_files_falls_back_to_md5_when_no_sha256(): void
    {
        $fileContent = 'test md5 content';
        $md5 = hash('md5', $fileContent);

        Http::fake([
            'example.com/setup.exe' => Http::response($fileContent, 200),
        ]);

        $downloads = [
            ['url' => 'http://example.com/setup.exe', 'saveto' => 'wpkg/packages/app/setup.exe', 'md5sum' => $md5, 'sha256sum' => null],
        ];

        $log = $this->createMockInstallationLog();

        // Ne doit pas lever d'exception — le hash MD5 est correct
        $this->service->downloadFiles($downloads, $log);

        $this->assertFileExists($this->tmpDir . '/wpkg/packages/app/setup.exe');
    }

    #[Test]
    public function download_files_skips_existing_file_with_correct_hash(): void
    {
        $fileContent = 'existing file content';
        $sha256 = hash('sha256', $fileContent);

        // Creer le fichier existant au chemin final
        $finalDir = $this->tmpDir . '/wpkg/packages/app';
        mkdir($finalDir, 0755, true);
        file_put_contents($finalDir . '/setup.exe', $fileContent);

        // Http::fake ne doit PAS etre appele
        Http::fake([
            'example.com/setup.exe' => Http::response('should not be downloaded', 200),
        ]);

        $downloads = [
            ['url' => 'http://example.com/setup.exe', 'saveto' => 'wpkg/packages/app/setup.exe', 'md5sum' => null, 'sha256sum' => $sha256],
        ];

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'Skip fichier existant');
            });

        $updatedData = null;
        $log = $this->createSpyInstallationLog();
        $log->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($data) use (&$updatedData) {
                $updatedData = $data;
                return true;
            });

        $this->service->downloadFiles($downloads, $log);

        // Le fichier doit etre inchange
        $this->assertEquals($fileContent, file_get_contents($finalDir . '/setup.exe'));

        // Aucun appel HTTP ne doit avoir ete fait
        Http::assertNothingSent();

        // Le log doit contenir progress, downloaded_bytes et message avec "(skip)"
        $this->assertEquals(70, $updatedData['progress']);
        $this->assertEquals(strlen($fileContent), $updatedData['downloaded_bytes']);
        $this->assertStringContainsString('(skip)', $updatedData['message']);
    }

    #[Test]
    public function download_files_redownloads_existing_file_with_wrong_hash(): void
    {
        $oldContent = 'old file content';
        $newContent = 'new file content';
        $newSha256 = hash('sha256', $newContent);

        // Creer le fichier existant avec un contenu different
        $finalDir = $this->tmpDir . '/wpkg/packages/app';
        mkdir($finalDir, 0755, true);
        file_put_contents($finalDir . '/setup.exe', $oldContent);

        Http::fake([
            'example.com/setup.exe' => Http::response($newContent, 200),
        ]);

        $downloads = [
            ['url' => 'http://example.com/setup.exe', 'saveto' => 'wpkg/packages/app/setup.exe', 'md5sum' => null, 'sha256sum' => $newSha256],
        ];

        $log = $this->createMockInstallationLog();
        $this->service->downloadFiles($downloads, $log);

        // Le fichier doit avoir ete remplace
        $this->assertEquals($newContent, file_get_contents($finalDir . '/setup.exe'));
    }

    #[Test]
    public function download_files_throws_on_hash_mismatch_atomicity(): void
    {
        $fileContent1 = 'file 1 ok';
        $fileContent2 = 'file 2 content';

        Http::fake([
            'example.com/file1.exe' => Http::response($fileContent1, 200),
            'example.com/file2.exe' => Http::response($fileContent2, 200),
        ]);

        $downloads = [
            ['url' => 'http://example.com/file1.exe', 'saveto' => 'wpkg/packages/app/file1.exe', 'md5sum' => null, 'sha256sum' => null],
            ['url' => 'http://example.com/file2.exe', 'saveto' => 'wpkg/packages/app/file2.exe', 'md5sum' => null, 'sha256sum' => 'badhash_will_not_match'],
        ];

        $log = $this->createMockInstallationLog();

        try {
            $this->service->downloadFiles($downloads, $log);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            // file1.exe (pas de hash, deja deplace vers final) doit toujours exister
            $this->assertFileExists($this->tmpDir . '/wpkg/packages/app/file1.exe');
            $this->assertEquals($fileContent1, file_get_contents($this->tmpDir . '/wpkg/packages/app/file1.exe'));

            // file2.exe ne doit PAS exister au chemin final (echec hash)
            $this->assertFileDoesNotExist($this->tmpDir . '/wpkg/packages/app/file2.exe');
        }
    }

    #[Test]
    public function download_files_updates_progress_between_20_and_70(): void
    {
        $fileContent1 = 'content 1';
        $fileContent2 = 'content 2';
        $fileContent3 = 'content 3';

        Http::fake([
            'example.com/f1.exe' => Http::response($fileContent1, 200),
            'example.com/f2.exe' => Http::response($fileContent2, 200),
            'example.com/f3.exe' => Http::response($fileContent3, 200),
        ]);

        $downloads = [
            ['url' => 'http://example.com/f1.exe', 'saveto' => 'wpkg/packages/app/f1.exe', 'md5sum' => null, 'sha256sum' => null],
            ['url' => 'http://example.com/f2.exe', 'saveto' => 'wpkg/packages/app/f2.exe', 'md5sum' => null, 'sha256sum' => null],
            ['url' => 'http://example.com/f3.exe', 'saveto' => 'wpkg/packages/app/f3.exe', 'md5sum' => null, 'sha256sum' => null],
        ];

        $progressValues = [];
        $downloadedBytesValues = [];
        $messageValues = [];

        $log = $this->createSpyInstallationLog();
        $log->expects($this->exactly(3))
            ->method('update')
            ->willReturnCallback(function ($data) use (&$progressValues, &$downloadedBytesValues, &$messageValues) {
                $progressValues[] = $data['progress'];
                $downloadedBytesValues[] = $data['downloaded_bytes'];
                $messageValues[] = $data['message'];
                return true;
            });

        $this->service->downloadFiles($downloads, $log);

        // Progress doit etre entre 20 et 70
        foreach ($progressValues as $progress) {
            $this->assertGreaterThanOrEqual(20, $progress);
            $this->assertLessThanOrEqual(70, $progress);
        }

        // Le dernier progress doit etre 70 (20 + 50 * 3/3)
        $this->assertEquals(70, end($progressValues));

        // downloaded_bytes doit etre cumulatif et croissant
        for ($i = 1; $i < count($downloadedBytesValues); $i++) {
            $this->assertGreaterThan($downloadedBytesValues[$i - 1], $downloadedBytesValues[$i]);
        }

        // Messages doivent contenir le compteur N/M
        $this->assertStringContainsString('1/3', $messageValues[0]);
        $this->assertStringContainsString('2/3', $messageValues[1]);
        $this->assertStringContainsString('3/3', $messageValues[2]);
    }

    #[Test]
    public function download_files_creates_intermediate_directories(): void
    {
        $fileContent = 'nested file content';

        Http::fake([
            'example.com/setup.exe' => Http::response($fileContent, 200),
        ]);

        $downloads = [
            ['url' => 'http://example.com/setup.exe', 'saveto' => 'wpkg/packages/deep/nested/dir/setup.exe', 'md5sum' => null, 'sha256sum' => null],
        ];

        $log = $this->createMockInstallationLog();
        $this->service->downloadFiles($downloads, $log);

        $this->assertFileExists($this->tmpDir . '/wpkg/packages/deep/nested/dir/setup.exe');
    }

    #[Test]
    public function download_files_with_empty_array_does_nothing(): void
    {
        $log = $this->createSpyInstallationLog();
        $log->expects($this->never())->method('update');

        $this->service->downloadFiles([], $log);
    }

    #[Test]
    public function download_files_throws_on_move_failure(): void
    {
        $fileContent = 'content to move';

        $mockFileManager = $this->createMock(FileManagerService::class);
        $mockFileManager->method('downloadWithHash')->willReturn('ignored');
        $mockFileManager->method('move')->willReturn(false);

        $this->app->instance(FileManagerService::class, $mockFileManager);
        $service = app(PackageInstallerService::class);

        $downloads = [
            ['url' => 'http://example.com/setup.exe', 'saveto' => 'wpkg/packages/app/setup.exe', 'md5sum' => null, 'sha256sum' => null],
        ];

        $log = $this->createMockInstallationLog();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Echec deplacement');

        $service->downloadFiles($downloads, $log);
    }

    #[Test]
    public function download_files_handles_empty_string_hashes_as_no_hash(): void
    {
        $fileContent = 'content with empty hash strings';

        Http::fake([
            'example.com/setup.exe' => Http::response($fileContent, 200),
        ]);

        // empty string hashes — doivent etre traites comme "pas de hash"
        $downloads = [
            ['url' => 'http://example.com/setup.exe', 'saveto' => 'wpkg/packages/app/setup.exe', 'md5sum' => '', 'sha256sum' => ''],
        ];

        $log = $this->createMockInstallationLog();

        // Ne doit pas lever d'exception (pas de verification de hash)
        $this->service->downloadFiles($downloads, $log);

        $this->assertFileExists($this->tmpDir . '/wpkg/packages/app/setup.exe');
    }

    // ========================================
    // processDeletes()
    // ========================================

    #[Test]
    public function process_deletes_deletes_existing_file(): void
    {
        $filePath = $this->tmpDir . '/to_delete.exe';
        file_put_contents($filePath, 'content');

        $this->service->processDeletes([
            ['file' => 'to_delete.exe'],
        ]);

        $this->assertFileDoesNotExist($filePath);
    }

    #[Test]
    public function process_deletes_handles_missing_file_silently(): void
    {
        $this->expectNotToPerformAssertions();

        $this->service->processDeletes([
            ['file' => 'nonexistent_file.exe'],
        ]);
    }

    // ========================================
    // processUntars()
    // ========================================

    #[Test]
    public function process_untars_calls_extract_tar_gz_with_correct_paths(): void
    {
        $mockFileManager = $this->createMock(FileManagerService::class);
        $mockFileManager->expects($this->once())
            ->method('extractTarGz')
            ->with(
                $this->tmpDir . '/wpkg/packages/app/archive.tar.gz',
                $this->tmpDir . '/wpkg/packages/app/out',
            );

        $this->app->instance(FileManagerService::class, $mockFileManager);
        $service = app(PackageInstallerService::class);

        $service->processUntars([
            ['tarfile' => 'wpkg/packages/app/archive.tar.gz', 'target' => 'wpkg/packages/app/out'],
        ]);
    }

    // ========================================
    // processUnzips()
    // ========================================

    #[Test]
    public function process_unzips_calls_extract_zip_with_correct_paths(): void
    {
        $mockFileManager = $this->createMock(FileManagerService::class);
        $mockFileManager->expects($this->once())
            ->method('extractZip')
            ->with(
                $this->tmpDir . '/wpkg/packages/app/archive.zip',
                $this->tmpDir . '/wpkg/packages/app/out',
            );

        $this->app->instance(FileManagerService::class, $mockFileManager);
        $service = app(PackageInstallerService::class);

        $service->processUnzips([
            ['zipfile' => 'wpkg/packages/app/archive.zip', 'target' => 'wpkg/packages/app/out'],
        ]);
    }

    // ========================================
    // resolveAndValidatePath() (via process*)
    // ========================================

    #[Test]
    public function process_deletes_rejects_path_traversal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Path traversal detecte');

        $this->service->processDeletes([
            ['file' => '../../etc/passwd'],
        ]);
    }

    #[Test]
    public function process_untars_rejects_path_traversal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Path traversal detecte');

        $this->service->processUntars([
            ['tarfile' => '../../etc/archive.tar.gz', 'target' => 'wpkg/packages/app/'],
        ]);
    }
}
