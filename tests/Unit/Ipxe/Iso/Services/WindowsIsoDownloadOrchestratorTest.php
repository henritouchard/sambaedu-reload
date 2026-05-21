<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Iso\Services;

use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use App\Ipxe\Iso\Exceptions\WindowsIsoLockException;
use App\Ipxe\Iso\Exceptions\WindowsIsoValidationException;
use App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob;
use App\Ipxe\Iso\Services\WindowsIsoDownloadOrchestrator;
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
}
