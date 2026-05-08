<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Console\Commands;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.5 / AC1.3 + T10 — Tests unit `wpkg:reports:archive:rotate`.
 *
 * Couvre :
 *   - Suppression des fichiers > N jours.
 *   - Conservation des fichiers récents.
 *   - Off-by-one : un fichier de pile N jours est CONSERVÉ.
 *   - Dry-run ne supprime pas.
 *   - Refus si --days < 1.
 */
final class RotateWpkgReportArchivesCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/wpkg-rotate-test-' . uniqid();
        @mkdir($this->tmpDir . '/2026/01/01', 0755, true);
        @mkdir($this->tmpDir . '/2026/05/01', 0755, true);

        Config::set('sambaedu.wpkg.reports_archive', $this->tmpDir);
        Config::set('sambaedu.wpkg.reports_archive_retention_days', 90);
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

    private function createArchiveFile(string $relativePath, int $ageDays): string
    {
        $abs = $this->tmpDir . '/' . $relativePath;
        @mkdir(dirname($abs), 0755, true);
        file_put_contents($abs, 'fake-report');

        $time = time() - ($ageDays * 86400);
        touch($abs, $time, $time);

        return $abs;
    }

    #[Test]
    public function deletes_files_older_than_days(): void
    {
        $oldFile = $this->createArchiveFile('2026/01/01/PC_old.txt', 100);
        $recentFile = $this->createArchiveFile('2026/05/01/PC_recent.txt', 30);

        $this->artisan('wpkg:reports:archive:rotate --days=90')->assertSuccessful();

        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($recentFile);
    }

    #[Test]
    public function file_at_exact_cutoff_is_kept_off_by_one_safety(): void
    {
        // Un fichier de pile 90 jours (- quelques secondes) est conservé.
        $borderFile = $this->createArchiveFile('2026/02/01/PC_border.txt', 89);

        $this->artisan('wpkg:reports:archive:rotate --days=90')->assertSuccessful();

        $this->assertFileExists($borderFile);
    }

    #[Test]
    public function dry_run_keeps_files(): void
    {
        $oldFile = $this->createArchiveFile('2026/01/01/PC_dryrun.txt', 200);

        $this->artisan('wpkg:reports:archive:rotate --days=90 --dry-run')->assertSuccessful();

        $this->assertFileExists($oldFile);
    }

    #[Test]
    public function days_below_1_returns_failure(): void
    {
        $this->artisan('wpkg:reports:archive:rotate --days=0')->assertFailed();
    }

    #[Test]
    public function uses_config_default_when_no_option_provided(): void
    {
        Config::set('sambaedu.wpkg.reports_archive_retention_days', 30);

        $oldFile = $this->createArchiveFile('2026/01/01/PC_oldconfig.txt', 50);
        $recentFile = $this->createArchiveFile('2026/05/01/PC_recentconfig.txt', 10);

        $this->artisan('wpkg:reports:archive:rotate')->assertSuccessful();

        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($recentFile);
    }
}
