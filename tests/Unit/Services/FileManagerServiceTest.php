<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\FileManagerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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

    /** @test */
    public function hash_file_returns_correct_sha256(): void
    {
        $filePath = $this->tmpDir . '/test.txt';
        file_put_contents($filePath, 'hello world');

        $hash = $this->service->hashFile($filePath, 'sha256');

        $this->assertEquals(hash('sha256', 'hello world'), $hash);
    }

    /** @test */
    public function hash_file_returns_correct_sha512(): void
    {
        $filePath = $this->tmpDir . '/test.txt';
        file_put_contents($filePath, 'hello world');

        $hash = $this->service->hashFile($filePath, 'sha512');

        $this->assertEquals(hash('sha512', 'hello world'), $hash);
    }

    /** @test */
    public function hash_file_returns_correct_md5(): void
    {
        $filePath = $this->tmpDir . '/test.txt';
        file_put_contents($filePath, 'hello world');

        $hash = $this->service->hashFile($filePath, 'md5');

        $this->assertEquals(md5('hello world'), $hash);
    }

    /** @test */
    public function hash_file_throws_exception_for_missing_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fichier introuvable');

        $this->service->hashFile('/nonexistent/file.txt');
    }

    // ========================================
    // downloadWithHash()
    // ========================================

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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
}
