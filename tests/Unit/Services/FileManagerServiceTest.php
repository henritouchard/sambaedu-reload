<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\FileManagerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FileManagerServiceTest extends TestCase
{
    private FileManagerService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FileManagerService::class);
        $this->tmpDir = sys_get_temp_dir() . '/filemanager_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
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
    // hashFile()
    // ========================================

    #[Test]
    public function hash_file_returns_correct_sha256(): void
    {
        $filePath = $this->tmpDir . '/test.txt';
        file_put_contents($filePath, 'hello world');

        $hash = $this->service->hashFile($filePath, 'sha256');

        $this->assertEquals(hash('sha256', 'hello world'), $hash);
    }

    #[Test]
    public function hash_file_returns_correct_sha512(): void
    {
        $filePath = $this->tmpDir . '/test.txt';
        file_put_contents($filePath, 'hello world');

        $hash = $this->service->hashFile($filePath, 'sha512');

        $this->assertEquals(hash('sha512', 'hello world'), $hash);
    }

    #[Test]
    public function hash_file_returns_correct_md5(): void
    {
        $filePath = $this->tmpDir . '/test.txt';
        file_put_contents($filePath, 'hello world');

        $hash = $this->service->hashFile($filePath, 'md5');

        $this->assertEquals(md5('hello world'), $hash);
    }

    #[Test]
    public function hash_file_throws_exception_for_missing_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fichier introuvable');

        $this->service->hashFile('/nonexistent/file.txt');
    }

    // ========================================
    // downloadWithHash()
    // ========================================

    #[Test]
    public function download_with_hash_succeeds_with_valid_hash(): void
    {
        $content = 'test xml content';
        $sha256 = hash('sha256', $content);
        $targetPath = $this->tmpDir . '/download.xml';

        Http::fake([
            'example.com/package.xml' => Http::response($content, 200),
        ]);

        $result = $this->service->downloadWithHash(
            url: 'http://example.com/package.xml',
            targetPath: $targetPath,
            sha256: $sha256,
        );

        $this->assertEquals($targetPath, $result);
        $this->assertFileExists($targetPath);
    }

    #[Test]
    public function download_with_hash_throws_on_hash_mismatch(): void
    {
        $content = 'test xml content';
        $targetPath = $this->tmpDir . '/download.xml';

        Http::fake([
            'example.com/package.xml' => Http::response($content, 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hash sha256 invalide');

        $this->service->downloadWithHash(
            url: 'http://example.com/package.xml',
            targetPath: $targetPath,
            sha256: 'invalid_hash',
        );
    }

    #[Test]
    public function download_with_hash_deletes_file_on_mismatch(): void
    {
        $content = 'test xml content';
        $targetPath = $this->tmpDir . '/download.xml';

        Http::fake([
            'example.com/package.xml' => Http::response($content, 200),
        ]);

        try {
            $this->service->downloadWithHash(
                url: 'http://example.com/package.xml',
                targetPath: $targetPath,
                sha256: 'invalid_hash',
            );
        } catch (\RuntimeException $e) {
            // Attendu
        }

        $this->assertFileDoesNotExist($targetPath);
    }

    #[Test]
    public function download_with_hash_succeeds_without_hash_verification(): void
    {
        $content = 'test xml content';
        $targetPath = $this->tmpDir . '/download.xml';

        Http::fake([
            'example.com/package.xml' => Http::response($content, 200),
        ]);

        $result = $this->service->downloadWithHash(
            url: 'http://example.com/package.xml',
            targetPath: $targetPath,
        );

        $this->assertEquals($targetPath, $result);
        $this->assertFileExists($targetPath);
    }

    #[Test]
    public function download_with_hash_creates_parent_directory(): void
    {
        $content = 'test xml content';
        $targetPath = $this->tmpDir . '/subdir/nested/download.xml';

        Http::fake([
            'example.com/package.xml' => Http::response($content, 200),
        ]);

        $this->service->downloadWithHash(
            url: 'http://example.com/package.xml',
            targetPath: $targetPath,
        );

        $this->assertFileExists($targetPath);
    }

    #[Test]
    public function download_with_hash_throws_on_http_error(): void
    {
        $targetPath = $this->tmpDir . '/download.xml';

        Http::fake([
            'example.com/package.xml' => Http::response('Not Found', 404),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Echec telechargement HTTP 404');

        $this->service->downloadWithHash(
            url: 'http://example.com/package.xml',
            targetPath: $targetPath,
        );
    }

    #[Test]
    public function download_with_hash_deletes_file_on_http_error(): void
    {
        $targetPath = $this->tmpDir . '/download.xml';

        Http::fake([
            'example.com/package.xml' => Http::response('Server Error', 500),
        ]);

        try {
            $this->service->downloadWithHash(
                url: 'http://example.com/package.xml',
                targetPath: $targetPath,
            );
        } catch (\RuntimeException $e) {
            // Attendu
        }

        $this->assertFileDoesNotExist($targetPath);
    }

    // ========================================
    // extractTarGz()
    // ========================================

    private function createTarGz(string $dir, string $filename, string $content): string
    {
        $archivePath = $dir . '/' . $filename;
        $tarPath = preg_replace('/\.gz$/', '', $archivePath);
        $innerFile = $dir . '/inner.txt';

        file_put_contents($innerFile, $content);

        $tar = new \PharData($tarPath);
        $tar->addFile($innerFile, 'inner.txt');
        $tar->compress(\Phar::GZ);
        unset($tar);

        \Phar::unlinkArchive($tarPath);
        unlink($innerFile);

        return $archivePath;
    }

    #[Test]
    public function extract_tar_gz_extracts_archive_correctly(): void
    {
        $archivePath = $this->createTarGz($this->tmpDir, 'archive.tar.gz', 'hello tar content');
        $targetDir = $this->tmpDir . '/extracted';

        $this->service->extractTarGz($archivePath, $targetDir);

        $this->assertFileExists($targetDir . '/inner.txt');
        $this->assertEquals('hello tar content', file_get_contents($targetDir . '/inner.txt'));
    }

    #[Test]
    public function extract_tar_gz_throws_on_missing_archive(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Archive introuvable');

        $this->service->extractTarGz($this->tmpDir . '/nonexistent.tar.gz', $this->tmpDir . '/out');
    }

    #[Test]
    public function extract_tar_gz_creates_target_directory(): void
    {
        $archivePath = $this->createTarGz($this->tmpDir, 'archive.tar.gz', 'content');
        $targetDir = $this->tmpDir . '/new_dir/nested';

        $this->assertDirectoryDoesNotExist($targetDir);

        $this->service->extractTarGz($archivePath, $targetDir);

        $this->assertDirectoryExists($targetDir);
    }

    // ========================================
    // extractZip()
    // ========================================

    private function createZip(string $dir, string $filename, string $content): string
    {
        $zipPath = $dir . '/' . $filename;
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('inner.txt', $content);
        $zip->close();

        return $zipPath;
    }

    #[Test]
    public function extract_zip_extracts_archive_correctly(): void
    {
        $zipPath = $this->createZip($this->tmpDir, 'archive.zip', 'hello zip content');
        $targetDir = $this->tmpDir . '/extracted';

        $this->service->extractZip($zipPath, $targetDir);

        $this->assertFileExists($targetDir . '/inner.txt');
        $this->assertEquals('hello zip content', file_get_contents($targetDir . '/inner.txt'));
    }

    #[Test]
    public function extract_zip_creates_target_directory(): void
    {
        $zipPath = $this->createZip($this->tmpDir, 'archive.zip', 'content');
        $targetDir = $this->tmpDir . '/new_zip_dir/nested';

        $this->assertDirectoryDoesNotExist($targetDir);

        $this->service->extractZip($zipPath, $targetDir);

        $this->assertDirectoryExists($targetDir);
    }

    #[Test]
    public function extract_zip_throws_on_missing_archive(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Archive introuvable');

        $this->service->extractZip($this->tmpDir . '/nonexistent.zip', $this->tmpDir . '/out');
    }

    #[Test]
    public function extract_zip_throws_on_corrupted_archive(): void
    {
        $corruptPath = $this->tmpDir . '/corrupt.zip';
        file_put_contents($corruptPath, 'not a zip file');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Archive ZIP corrompue ou invalide');

        $this->service->extractZip($corruptPath, $this->tmpDir . '/out');
    }
}
