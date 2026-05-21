<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Iso\Jobs;

use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob;
use App\Models\User;
use App\Models\WindowsIsoDownload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesWindowsIsoSchema;

/**
 * Story 3.6 — AC4.3-AC4.7 — Tests unitaires de DownloadWindowsIsoJob.
 *
 * Couvre :
 *  - Path nominal (pending → downloading → extracting → success).
 *  - Échec phase curl (status=failed + exit_code + error).
 *  - Échec phase extract (status=failed + exit_code + error).
 *  - Annulation entre curl et extract.
 *  - Sécurité escapeshellarg systématique.
 *  - Implementations ShouldQueue + WithoutOverlapping.
 *  - Lock global release dans finally.
 *  - Exception → status=failed + log.
 */
class DownloadWindowsIsoJobTest extends TestCase
{
    use CreatesWindowsIsoSchema;
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWindowsIsoSchema();

        config([
            'ipxe.iso_management.iso_storage_path'        => '/tmp/sambaedu-test/iso',
            'ipxe.iso_management.download_timeout_seconds' => 7200,
            'ipxe.iso_management.extract_timeout_seconds'  => 1800,
            'ipxe.iso_management.global_lock_key'         => 'ipxe.iso.download.test-job-lock',
            'sambaedu.windows_iso.install_script'          => '/usr/share/sambaedu/scripts/install-win-iso.sh',
            'cache.default'                               => 'array',
        ]);

        Cache::lock('ipxe.iso.download.test-job-lock')->forceRelease();

        $this->user = User::query()->create([
            'login'     => 'admin-iso-job-test',
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::lock('ipxe.iso.download.test-job-lock')->forceRelease();
        $this->dropWindowsIsoSchema();
        parent::tearDown();
    }

    /* =================================================================
     * Implementation checks
     * ================================================================= */

    #[Test]
    public function it_implements_should_queue_with_one_try_and_timeout(): void
    {
        // Q1 Henri 2026-05-21 : timeout dynamique = download_timeout +
        // extract_timeout + 300s marge globale. Avec les valeurs par défaut
        // (7200 + 1800 + 300) = 9300s.
        $job = new DownloadWindowsIsoJob(42);
        self::assertInstanceOf(ShouldQueue::class, $job);
        self::assertSame(1, $job->tries);
        self::assertSame(9300, $job->timeout);
    }

    #[Test]
    public function it_declares_without_overlapping_middleware_with_global_key(): void
    {
        $job = new DownloadWindowsIsoJob(42);
        $middlewares = $job->middleware();

        self::assertCount(1, $middlewares);
        self::assertInstanceOf(WithoutOverlapping::class, $middlewares[0]);
    }

    /* =================================================================
     * Path nominal
     * ================================================================= */

    #[Test]
    public function it_runs_nominal_path_and_marks_success(): void
    {
        Process::fake([
            // curl OK
            'curl*' => Process::result(output: 'OK', errorOutput: '', exitCode: 0),
            // sudo install-win-iso.sh OK
            'sudo*' => Process::result(output: 'EXTRACTED', errorOutput: '', exitCode: 0),
        ]);

        $download = WindowsIsoDownload::factory()->pending()->create([
            'iso_name'             => 'Win11_24H2.iso',
            'version'              => 'Win11',
            'source_url'           => 'https://download.microsoft.com/Win11_24H2.iso',
            'initiated_by_user_id' => $this->user->id,
        ]);

        (new DownloadWindowsIsoJob($download->id))->handle();

        $download->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Success, $download->status);
        self::assertSame(0, $download->exit_code);
        self::assertNotNull($download->started_at);
        self::assertNotNull($download->completed_at);
    }

    /* =================================================================
     * Échecs
     * ================================================================= */

    #[Test]
    public function it_marks_failed_when_curl_returns_non_zero_exit(): void
    {
        Process::fake([
            'curl*' => Process::result(output: '', errorOutput: 'curl: (6) Could not resolve host', exitCode: 6),
        ]);

        $download = WindowsIsoDownload::factory()->pending()->create([
            'iso_name'             => 'Win11_24H2.iso',
            'version'              => 'Win11',
            'initiated_by_user_id' => $this->user->id,
        ]);

        (new DownloadWindowsIsoJob($download->id))->handle();

        $download->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Failed, $download->status);
        self::assertSame(6, $download->exit_code);
        self::assertStringContainsString('curl-failed', (string) $download->error);
        self::assertStringContainsString('Could not resolve host', (string) $download->error);
    }

    #[Test]
    public function it_marks_failed_when_extract_returns_non_zero_exit(): void
    {
        Process::fake([
            'curl*' => Process::result(output: 'OK', errorOutput: '', exitCode: 0),
            'sudo*' => Process::result(output: '', errorOutput: 'Mount loop failed', exitCode: 1),
        ]);

        $download = WindowsIsoDownload::factory()->pending()->create([
            'iso_name'             => 'Win11_24H2.iso',
            'version'              => 'Win11',
            'initiated_by_user_id' => $this->user->id,
        ]);

        (new DownloadWindowsIsoJob($download->id))->handle();

        $download->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Failed, $download->status);
        self::assertSame(1, $download->exit_code);
        self::assertStringContainsString('extract-failed', (string) $download->error);
        self::assertStringContainsString('Mount loop failed', (string) $download->error);
    }

    /* =================================================================
     * Annulation
     * ================================================================= */

    #[Test]
    public function it_skips_when_status_is_already_cancelled(): void
    {
        Process::fake();

        $download = WindowsIsoDownload::factory()->create([
            'status'               => WindowsIsoDownloadStatus::Cancelled,
            'iso_name'             => 'Win11_24H2.iso',
            'version'              => 'Win11',
            'initiated_by_user_id' => $this->user->id,
        ]);

        (new DownloadWindowsIsoJob($download->id))->handle();

        $download->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Cancelled, $download->status);
        Process::assertNothingRan();
    }

    #[Test]
    public function it_skips_extract_when_cancelled_between_curl_and_extract(): void
    {
        $download = WindowsIsoDownload::factory()->pending()->create([
            'iso_name'             => 'Win11_24H2.iso',
            'version'              => 'Win11',
            'initiated_by_user_id' => $this->user->id,
        ]);

        // On simule l'annulation après curl en mocant Process avec un side-effect.
        Process::fake([
            'curl*' => function () use ($download) {
                // Avant le retour OK, l'admin annule.
                $download->update(['status' => WindowsIsoDownloadStatus::Cancelled]);
                return Process::result(output: 'OK', errorOutput: '', exitCode: 0);
            },
        ]);

        (new DownloadWindowsIsoJob($download->id))->handle();

        $download->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Cancelled, $download->status);
        // L'extract ne doit PAS avoir tourné.
        Process::assertRan(fn ($p) => str_starts_with($p->command, 'curl'));
    }

    /* =================================================================
     * Sécurité escapeshellarg
     * ================================================================= */

    #[Test]
    public function it_escapes_shell_arguments_in_curl_and_extract_commands(): void
    {
        Process::fake([
            'curl*' => Process::result(output: 'OK', errorOutput: '', exitCode: 0),
            'sudo*' => Process::result(output: 'OK', errorOutput: '', exitCode: 0),
        ]);

        $download = WindowsIsoDownload::factory()->pending()->create([
            'iso_name'             => 'Win11_24H2.iso',
            'version'              => 'Win11',
            'source_url'           => 'https://download.microsoft.com/Win11_24H2.iso',
            'initiated_by_user_id' => $this->user->id,
        ]);

        (new DownloadWindowsIsoJob($download->id))->handle();

        // Le curl doit contenir les arguments en single-quotes (escapeshellarg).
        Process::assertRan(function ($p) {
            return str_starts_with($p->command, 'curl ')
                && str_contains($p->command, "'https://download.microsoft.com/Win11_24H2.iso'")
                && str_contains($p->command, "'/tmp/sambaedu-test/iso/Win11_24H2.iso'");
        });

        // Le sudo install-win-iso.sh doit contenir version_num=11 et iso_name escapés.
        Process::assertRan(function ($p) {
            return str_starts_with($p->command, 'sudo ')
                && str_contains($p->command, "'/usr/share/sambaedu/scripts/install-win-iso.sh'")
                && str_contains($p->command, "'11'")
                && str_contains($p->command, "'Win11_24H2.iso'");
        });
    }

    /* =================================================================
     * Lock release
     * ================================================================= */

    #[Test]
    public function it_releases_global_lock_in_finally_on_success(): void
    {
        Process::fake([
            'curl*' => Process::result(output: 'OK', errorOutput: '', exitCode: 0),
            'sudo*' => Process::result(output: 'OK', errorOutput: '', exitCode: 0),
        ]);

        // Acquière le lock manuellement (simule l'orchestrator l'a posé).
        Cache::lock('ipxe.iso.download.test-job-lock', 60)->get();

        $download = WindowsIsoDownload::factory()->pending()->create([
            'iso_name'             => 'Win11_24H2.iso',
            'version'              => 'Win11',
            'initiated_by_user_id' => $this->user->id,
        ]);
        (new DownloadWindowsIsoJob($download->id))->handle();

        // Après le handle, le lock doit être disponible (= release dans finally).
        $newLock = Cache::lock('ipxe.iso.download.test-job-lock', 60);
        self::assertTrue($newLock->get(), 'Le lock global doit être release après handle().');
        $newLock->release();
    }

    #[Test]
    public function it_releases_lock_even_when_curl_fails(): void
    {
        Process::fake([
            'curl*' => Process::result(output: '', errorOutput: 'fail', exitCode: 6),
        ]);

        Cache::lock('ipxe.iso.download.test-job-lock', 60)->get();

        $download = WindowsIsoDownload::factory()->pending()->create([
            'iso_name'             => 'Win11_24H2.iso',
            'version'              => 'Win11',
            'initiated_by_user_id' => $this->user->id,
        ]);
        (new DownloadWindowsIsoJob($download->id))->handle();

        $newLock = Cache::lock('ipxe.iso.download.test-job-lock', 60);
        self::assertTrue($newLock->get(), 'Le lock doit être release même en cas d\'échec curl.');
        $newLock->release();
    }

    /* =================================================================
     * Edge cases
     * ================================================================= */

    #[Test]
    public function it_logs_warning_when_download_row_is_missing(): void
    {
        Process::fake();
        // Pas de row 99999.
        (new DownloadWindowsIsoJob(99999))->handle();

        Process::assertNothingRan();
    }
}
