<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Iso\Services;

use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use App\Ipxe\Iso\Exceptions\WindowsIsoLockException;
use App\Ipxe\Iso\Exceptions\WindowsIsoValidationException;
use App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob;
use App\Ipxe\Iso\Services\WindowsIsoDownloadOrchestrator;
use App\Ipxe\Iso\Services\WindowsIsoSourcesReader;
use App\Ipxe\Iso\Services\WindowsIsoUrlValidator;
use App\Models\User;
use App\Models\WindowsIsoDownload;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesWindowsIsoSchema;

/**
 * Story 3.6 — AC4.1, AC4.2 — Tests unitaires de WindowsIsoDownloadOrchestrator.
 */
class WindowsIsoDownloadOrchestratorTest extends TestCase
{
    use CreatesWindowsIsoSchema;
    use DatabaseTransactions;

    private WindowsIsoDownloadOrchestrator $orchestrator;
    private User $user;
    private string $isoStoragePath;
    private string $uploadTmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWindowsIsoSchema();

        config(['ipxe.iso_management.allowed_url_hosts' => [
            'software-static.download.prss.microsoft.com',
            'software-download.microsoft.com',
            'download.microsoft.com',
        ]]);
        config(['ipxe.iso_management.global_lock_key' => 'ipxe.iso.download.test-lock']);
        config(['ipxe.iso_management.global_lock_ttl' => 60]);
        config(['ipxe.iso_management.queue_name' => 'ipxe_iso_downloads_test']);
        // Lock store = array en test (aligné sur cache.default=array ci-dessous) :
        // l'orchestrator prend le lock sur ce store, et les tests l'acquièrent/relâchent
        // via Cache::lock() (= store par défaut) — même store, mêmes clés.
        config(['ipxe.iso_management.lock_store' => 'array']);

        // Dépôt manuel : dossiers temp (même filesystem → rename atomique).
        $this->isoStoragePath = sys_get_temp_dir() . '/se5-iso-store-' . getmypid();
        $this->uploadTmpPath  = $this->isoStoragePath . '/.uploads';
        @mkdir($this->uploadTmpPath, 0775, true);
        config(['ipxe.iso_management.iso_storage_path' => $this->isoStoragePath]);
        config(['ipxe.iso_management.upload_tmp_path' => $this->uploadTmpPath]);
        config(['ipxe.iso_management.upload_max_total_bytes' => 6 * 1024 * 1024 * 1024]);

        // Force le cache driver array pour les tests (pas de redis en sqlite).
        config(['cache.default' => 'array']);

        Queue::fake();

        $this->orchestrator = new WindowsIsoDownloadOrchestrator(new WindowsIsoUrlValidator());
        $this->user = User::query()->create([
            'login'     => 'admin-iso-test',
            'role'      => 'admin',
            'is_active' => true,
        ]);

        // Release lock zombi entre tests (le driver array partage entre instances).
        Cache::lock('ipxe.iso.download.test-lock')->forceRelease();
    }

    protected function tearDown(): void
    {
        Cache::lock('ipxe.iso.download.test-lock')->forceRelease();
        // Nettoyage des dossiers temp upload.
        foreach (glob($this->uploadTmpPath . '/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->isoStoragePath . '/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->uploadTmpPath);
        @rmdir($this->isoStoragePath);
        $this->dropWindowsIsoSchema();
        parent::tearDown();
    }

    #[Test]
    public function it_submits_valid_url_creates_row_pending_and_dispatches_job(): void
    {
        $url = 'https://software-static.download.prss.microsoft.com/dbazure/Win11_24H2.iso';

        $download = $this->orchestrator->submit($url, $this->user->id, '127.0.0.1');

        self::assertInstanceOf(WindowsIsoDownload::class, $download);
        self::assertSame('Win11', $download->version);
        self::assertSame('Win11_24H2.iso', $download->iso_name);
        self::assertSame($url, $download->source_url);
        self::assertSame(WindowsIsoDownloadStatus::Pending, $download->status);
        self::assertSame($this->user->id, $download->initiated_by_user_id);
        self::assertSame('127.0.0.1', $download->host_ip);

        Queue::assertPushed(DownloadWindowsIsoJob::class, function (DownloadWindowsIsoJob $job) use ($download) {
            return $job->downloadId === $download->id;
        });
    }

    #[Test]
    public function it_throws_validation_exception_for_invalid_url(): void
    {
        $this->expectException(WindowsIsoValidationException::class);
        $this->orchestrator->submit('http://evil.com/Win11.iso', $this->user->id, '127.0.0.1');
    }

    #[Test]
    public function it_throws_lock_exception_when_global_lock_already_held(): void
    {
        // Acquière le lock global avant le call.
        $lock = Cache::lock('ipxe.iso.download.test-lock', 60);
        self::assertTrue($lock->get());

        try {
            $this->expectException(WindowsIsoLockException::class);
            $this->orchestrator->submit(
                'https://software-static.download.prss.microsoft.com/Win11_24H2.iso',
                $this->user->id,
                '127.0.0.1',
            );
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function it_does_not_dispatch_job_when_validation_fails(): void
    {
        try {
            $this->orchestrator->submit('not-a-url-at-all', $this->user->id, '127.0.0.1');
        } catch (WindowsIsoValidationException) {
            // Expected.
        }
        Queue::assertNothingPushed();
        self::assertSame(0, WindowsIsoDownload::query()->count());
    }

    #[Test]
    public function it_dispatches_job_to_configured_queue(): void
    {
        $this->orchestrator->submit(
            'https://software-static.download.prss.microsoft.com/Win11_24H2.iso',
            $this->user->id,
            '127.0.0.1',
        );

        Queue::assertPushedOn('ipxe_iso_downloads_test', DownloadWindowsIsoJob::class);
    }

    #[Test]
    public function it_cancels_a_running_download_and_marks_status_cancelled(): void
    {
        $download = WindowsIsoDownload::factory()->downloading()->create(['initiated_by_user_id' => $this->user->id]);

        $result = $this->orchestrator->cancel($download, $this->user->id);

        self::assertTrue($result);
        $download->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Cancelled, $download->status);
        self::assertNotNull($download->completed_at);
    }

    #[Test]
    public function it_is_noop_when_cancelling_a_terminal_download(): void
    {
        $download = WindowsIsoDownload::factory()->success()->create(['initiated_by_user_id' => $this->user->id]);

        $result = $this->orchestrator->cancel($download, $this->user->id);

        self::assertFalse($result);
        $download->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Success, $download->status);
    }

    /* =================================================================
     * Dépôt manuel (submitUpload)
     * ================================================================= */

    #[Test]
    public function it_submit_upload_creates_row_moves_file_and_dispatches_job(): void
    {
        $partPath = $this->uploadTmpPath . '/' . '11111111-2222-4333-8444-555566667777.part';
        file_put_contents($partPath, 'FAKE-ISO-BYTES');

        $download = $this->orchestrator->submitUpload(
            $partPath,
            'Win11_24H2.iso',
            'Win11',
            $this->user->id,
            '127.0.0.1',
        );

        self::assertSame('Win11', $download->version);
        self::assertSame('Win11_24H2.iso', $download->iso_name);
        self::assertNull($download->source_url);
        self::assertSame(WindowsIsoDownload::SOURCE_UPLOAD, $download->source);
        self::assertSame(WindowsIsoDownloadStatus::Pending, $download->status);

        // Le `.part` a été renommé vers la destination finale (atomique).
        self::assertFileDoesNotExist($partPath);
        self::assertFileExists($this->isoStoragePath . '/Win11_24H2.iso');

        Queue::assertPushedOn('ipxe_iso_downloads_test', DownloadWindowsIsoJob::class);
    }

    #[Test]
    public function it_submit_upload_throws_validation_for_bad_filename(): void
    {
        $partPath = $this->uploadTmpPath . '/aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000.part';
        file_put_contents($partPath, 'X');

        $this->expectException(WindowsIsoValidationException::class);
        try {
            $this->orchestrator->submitUpload($partPath, '../evil.iso', 'Win11', $this->user->id, '127.0.0.1');
        } finally {
            Queue::assertNothingPushed();
            self::assertSame(0, WindowsIsoDownload::query()->count());
        }
    }

    #[Test]
    public function it_submit_upload_throws_validation_when_assembled_file_missing(): void
    {
        $this->expectException(WindowsIsoValidationException::class);
        $this->orchestrator->submitUpload(
            $this->uploadTmpPath . '/does-not-exist.part',
            'Win11_24H2.iso',
            'Win11',
            $this->user->id,
            '127.0.0.1',
        );
    }

    #[Test]
    public function it_submit_upload_throws_lock_when_global_lock_held(): void
    {
        $partPath = $this->uploadTmpPath . '/abababab-cdcd-4ede-8fef-010101010101.part';
        file_put_contents($partPath, 'X');

        $lock = Cache::lock('ipxe.iso.download.test-lock', 60);
        self::assertTrue($lock->get());

        try {
            $this->expectException(WindowsIsoLockException::class);
            $this->orchestrator->submitUpload($partPath, 'Win11_24H2.iso', 'Win11', $this->user->id, '127.0.0.1');
        } finally {
            $lock->release();
        }
    }

    /* =================================================================
     * Ré-injection des pilotes (resubmitExtraction — Story 3.10)
     * ================================================================= */

    /**
     * Stub du reader de sources déployées : contrôle ce que « la version
     * courante » retourne pour chaque OS sans toucher au filesystem.
     */
    private function stubSourcesReader(?string $win10, ?string $win11): WindowsIsoSourcesReader
    {
        return new class($win10, $win11) extends WindowsIsoSourcesReader {
            public function __construct(private ?string $w10, private ?string $w11) {}

            public function list(): array
            {
                return [
                    'win10' => ['current' => $this->w10, 'old' => null],
                    'win11' => ['current' => $this->w11, 'old' => null],
                ];
            }
        };
    }

    #[Test]
    public function it_resubmit_extraction_creates_reinject_row_and_dispatches_job(): void
    {
        // L'ISO déployée est encore sur disque (conservée au succès).
        file_put_contents($this->isoStoragePath . '/Win11_24H2.iso', 'FAKE-ISO');

        $download = $this->orchestrator->resubmitExtraction(
            'Win11',
            $this->user->id,
            '127.0.0.1',
            $this->stubSourcesReader(null, 'Win11_24H2.iso'),
        );

        self::assertSame('Win11', $download->version);
        self::assertSame('Win11_24H2.iso', $download->iso_name);
        self::assertNull($download->source_url);
        self::assertSame(WindowsIsoDownload::SOURCE_REINJECT, $download->source);
        self::assertTrue($download->skipsDownload());
        self::assertSame(WindowsIsoDownloadStatus::Pending, $download->status);

        Queue::assertPushedOn('ipxe_iso_downloads_test', DownloadWindowsIsoJob::class, function (DownloadWindowsIsoJob $job) use ($download) {
            return $job->downloadId === $download->id;
        });
    }

    #[Test]
    public function it_resubmit_throws_when_no_version_deployed(): void
    {
        try {
            $this->expectException(WindowsIsoValidationException::class);
            $this->orchestrator->resubmitExtraction(
                'Win11',
                $this->user->id,
                '127.0.0.1',
                $this->stubSourcesReader(null, null), // aucune version déployée
            );
        } finally {
            Queue::assertNothingPushed();
            self::assertSame(0, WindowsIsoDownload::query()->count());
        }
    }

    #[Test]
    public function it_resubmit_throws_when_iso_source_missing_on_disk(): void
    {
        // Version déployée déclarée, mais l'ISO source a été purgée du disque.
        try {
            $this->expectException(WindowsIsoValidationException::class);
            $this->orchestrator->resubmitExtraction(
                'Win11',
                $this->user->id,
                '127.0.0.1',
                $this->stubSourcesReader(null, 'Win11_PURGED.iso'),
            );
        } finally {
            Queue::assertNothingPushed();
            self::assertSame(0, WindowsIsoDownload::query()->count());
        }
    }

    #[Test]
    public function it_resubmit_throws_for_unsupported_version(): void
    {
        $this->expectException(WindowsIsoValidationException::class);
        $this->orchestrator->resubmitExtraction(
            'Win95',
            $this->user->id,
            '127.0.0.1',
            $this->stubSourcesReader('Win95.iso', null),
        );
    }

    #[Test]
    public function it_resubmit_throws_lock_when_global_lock_held(): void
    {
        file_put_contents($this->isoStoragePath . '/Win11_24H2.iso', 'FAKE-ISO');

        $lock = Cache::lock('ipxe.iso.download.test-lock', 60);
        self::assertTrue($lock->get());

        try {
            $this->expectException(WindowsIsoLockException::class);
            $this->orchestrator->resubmitExtraction(
                'Win11',
                $this->user->id,
                '127.0.0.1',
                $this->stubSourcesReader(null, 'Win11_24H2.iso'),
            );
        } finally {
            $lock->release();
        }
    }
}
