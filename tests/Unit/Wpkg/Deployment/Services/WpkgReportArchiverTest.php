<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Services;

use App\Wpkg\Deployment\Services\WpkgReportArchiver;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.5 / AC1.3 — Tests unit `WpkgReportArchiver`.
 */
final class WpkgReportArchiverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/wpkg-archiver-test-' . uniqid();
        @mkdir($this->tmpDir, 0755, true);

        Config::set('sambaedu.wpkg.reports_archive', $this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->tmpDir);

        parent::tearDown();
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }

    #[Test]
    public function writes_archive_with_y_m_d_path_and_returns_path(): void
    {
        $archiver = new WpkgReportArchiver();
        $sha = hash('sha256', 'content-1');

        $path = $archiver->archive('PC-AR-01', 'content-1', $sha);

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertStringContainsString(date('Y/m/d'), $path);
        $this->assertStringContainsString('PC-AR-01_', $path);
        $this->assertStringContainsString(substr($sha, 0, 8), $path);
        $this->assertSame('content-1', file_get_contents($path));
    }

    #[Test]
    public function returns_null_when_archive_base_unconfigured(): void
    {
        Config::set('sambaedu.wpkg.reports_archive', '');

        $archiver = new WpkgReportArchiver();
        $path = $archiver->archive('PC-AR-02', 'content', hash('sha256', 'c'));

        $this->assertNull($path);
    }

    #[Test]
    public function sanitizes_hostname_with_dangerous_chars(): void
    {
        $archiver = new WpkgReportArchiver();
        // Hostname pathologique avec /, .., *
        $path = $archiver->archive('../evil*name', 'content', hash('sha256', 'c'));

        $this->assertNotNull($path);
        // Le hostname dans le path doit être sanitizé.
        $this->assertStringNotContainsString('../', basename($path));
        $this->assertStringNotContainsString('*', basename($path));
    }
}
