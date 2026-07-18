<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use App\Models\User;
use App\Models\WindowsIsoDownload;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesWindowsIsoSchema;

/**
 * Story 3.6 — AC1.2 — Tests unitaires du modèle WindowsIsoDownload.
 */
class WindowsIsoDownloadTest extends TestCase
{
    use CreatesWindowsIsoSchema;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWindowsIsoSchema();
    }

    protected function tearDown(): void
    {
        $this->dropWindowsIsoSchema();
        parent::tearDown();
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'login'    => 'iso-test-' . uniqid(),
            'role'     => 'autre',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function it_casts_status_to_enum(): void
    {
        $user = $this->makeUser();
        $d = WindowsIsoDownload::query()->create([
            'version'              => 'Win11',
            'iso_name'             => 'Win11_24H2.iso',
            'source_url'           => 'https://example.com/Win11_24H2.iso',
            'status'               => WindowsIsoDownloadStatus::Downloading,
            'initiated_by_user_id' => $user->id,
        ]);

        self::assertInstanceOf(WindowsIsoDownloadStatus::class, $d->status);
        self::assertSame('downloading', $d->status->value);
    }

    #[Test]
    public function it_casts_started_and_completed_at_to_datetime(): void
    {
        $user = $this->makeUser();
        $d = WindowsIsoDownload::factory()->success()->create(['initiated_by_user_id' => $user->id]);
        $d->refresh();

        self::assertInstanceOf(\Carbon\Carbon::class, $d->started_at);
        self::assertInstanceOf(\Carbon\Carbon::class, $d->completed_at);
    }

    #[Test]
    public function it_returns_version_num_without_win_prefix(): void
    {
        $d = WindowsIsoDownload::factory()->forVersion('Win11')->make();
        self::assertSame('11', $d->versionNum());

        $d2 = WindowsIsoDownload::factory()->forVersion('Win10')->make();
        self::assertSame('10', $d2->versionNum());
    }

    #[Test]
    public function it_returns_is_running_for_non_terminal_status(): void
    {
        $d = WindowsIsoDownload::factory()->make(['status' => WindowsIsoDownloadStatus::Downloading]);
        self::assertTrue($d->isRunning());

        $d2 = WindowsIsoDownload::factory()->make(['status' => WindowsIsoDownloadStatus::Success]);
        self::assertFalse($d2->isRunning());
    }

    #[Test]
    public function it_returns_is_terminal_for_success_failed_cancelled(): void
    {
        foreach ([WindowsIsoDownloadStatus::Success, WindowsIsoDownloadStatus::Failed, WindowsIsoDownloadStatus::Cancelled] as $s) {
            $d = WindowsIsoDownload::factory()->make(['status' => $s]);
            self::assertTrue($d->isTerminal(), "Status {$s->value} doit être terminal.");
        }
    }

    #[Test]
    public function it_belongs_to_initiating_user(): void
    {
        $user = $this->makeUser();
        $d = WindowsIsoDownload::factory()->create(['initiated_by_user_id' => $user->id]);

        self::assertInstanceOf(User::class, $d->initiatedBy);
        self::assertSame($user->id, $d->initiatedBy->id);
    }

    #[Test]
    public function it_reports_upload_reinject_and_skips_download(): void
    {
        // Story 3.10 — un download URL télécharge (curl), un upload et une
        // ré-injection sautent la phase download (fichier déjà sur disque).
        $url = WindowsIsoDownload::factory()->make(['source' => WindowsIsoDownload::SOURCE_URL]);
        self::assertFalse($url->isUpload());
        self::assertFalse($url->isReinject());
        self::assertFalse($url->skipsDownload());

        $upload = WindowsIsoDownload::factory()->upload()->make();
        self::assertTrue($upload->isUpload());
        self::assertFalse($upload->isReinject());
        self::assertTrue($upload->skipsDownload());

        $reinject = WindowsIsoDownload::factory()->reinject()->make();
        self::assertFalse($reinject->isUpload());
        self::assertTrue($reinject->isReinject());
        self::assertTrue($reinject->skipsDownload());
    }

    #[Test]
    public function it_exposes_expected_fillable_attributes(): void
    {
        $expected = [
            'version', 'iso_name', 'source_url', 'status',
            'started_at', 'completed_at', 'exit_code', 'error',
            'initiated_by_user_id', 'host_ip',
        ];
        $d = new WindowsIsoDownload();
        foreach ($expected as $attr) {
            self::assertContains($attr, $d->getFillable(), "Fillable doit contenir '{$attr}'.");
        }
    }
}
