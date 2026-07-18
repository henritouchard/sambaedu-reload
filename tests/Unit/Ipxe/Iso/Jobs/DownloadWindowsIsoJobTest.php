<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Iso\Jobs;

use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use App\Ipxe\Iso\Exceptions\WindowsIsoExtractionException;
use App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob;
use App\Ipxe\Iso\Services\WindowsIsoExtractor;
use App\Models\User;
use App\Models\WindowsIsoDownload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
 *  - Sécurité escapeshellarg systématique (curl).
 *  - Implementations ShouldQueue + WithoutOverlapping.
 *  - Lock global release dans finally.
 *  - Exception → status=failed + log.
 *
 * L'extraction elle-même est testée dans
 * {@see \Tests\Unit\Ipxe\Iso\Services\WindowsIsoExtractorTest}. Ici on stubbe
 * {@see WindowsIsoExtractor} dans le conteneur pour isoler le comportement du
 * Job (transitions, mapping d'échec, bypass curl pour les dépôts).
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
            'ipxe.iso_management.iso_storage_path'         => '/tmp/sambaedu-test/iso',
            'ipxe.iso_management.download_timeout_seconds' => 7200,
            'ipxe.iso_management.extract_timeout_seconds'  => 1800,
            'ipxe.iso_management.global_lock_key'          => 'ipxe.iso.download.test-job-lock',
            // Lock store = array en test (aligné sur cache.default) : releaseLock
            // du Job et les assertions de lock des tests visent le même store.
            'ipxe.iso_management.lock_store'               => 'array',
            'cache.default'                                => 'array',
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

    /**
     * Lie un faux extracteur dans le conteneur. Renvoie l'objet espion : on
     * peut inspecter `->calls` (chaque extract enregistré) et lui faire lever
     * une exception via `$throws`.
     */
    private function fakeExtractor(?\Throwable $throws = null): object
    {
        $spy = new class {
            /** @var array<int, array{version: string, isoPath: string, timeout: ?int}> */
            public array $calls = [];

            public ?\Throwable $throws = null;

            public function extract(string $version, string $isoPath, ?int $timeout = null): void
            {
                $this->calls[] = ['version' => $version, 'isoPath' => $isoPath, 'timeout' => $timeout];

                if ($this->throws !== null) {
                    throw $this->throws;
                }
            }
        };
        $spy->throws = $throws;

        $this->app->instance(WindowsIsoExtractor::class, $spy);

        return $spy;
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
    public function it_declares_no_queue_middleware(): void
    {
        // `WithoutOverlapping` retiré : il casse sur APCu (ApcStore::lock()
        // undefined). Le mutex global vit dans le lock file de l'orchestrator.
        $job = new DownloadWindowsIsoJob(42);

        self::assertSame([], $job->middleware());
    }

    /* =================================================================
     * Path nominal
     * ================================================================= */

    #[Test]
    public function it_runs_nominal_path_and_marks_success(): void
    {
        Process::fake([
            'curl*' => Process::result(output: 'OK', errorOutput: '', exitCode: 0),
        ]);
        $this->fakeExtractor();

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
    public function it_marks_failed_when_extraction_throws(): void
    {
        Process::fake([
            'curl*' => Process::result(output: 'OK', errorOutput: '', exitCode: 0),
        ]);
        // L'extracteur natif échoue (ex. montage loop KO) → exception portant
        // l'exit code + le stderr de la commande fautive.
        $this->fakeExtractor(new WindowsIsoExtractionException('[mount] Mount loop failed', 1));

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
        $spy = $this->fakeExtractor();

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
        self::assertSame([], $spy->calls, 'Aucune extraction sur un download déjà annulé.');
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
        $spy = $this->fakeExtractor();

        (new DownloadWindowsIsoJob($download->id))->handle();

        $download->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Cancelled, $download->status);
        // L'extract ne doit PAS avoir tourné.
        self::assertSame([], $spy->calls, 'L\'extraction ne doit pas tourner après une annulation.');
        Process::assertRan(fn ($p) => str_starts_with($p->command, 'curl'));
    }

    /* =================================================================
     * Sécurité escapeshellarg (curl)
     * ================================================================= */

    #[Test]
    public function it_escapes_shell_arguments_in_curl_command(): void
    {
        Process::fake([
            'curl*' => Process::result(output: 'OK', errorOutput: '', exitCode: 0),
        ]);
        $this->fakeExtractor();

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
    }

    /* =================================================================
     * Lock release
     * ================================================================= */

    #[Test]
    public function it_releases_global_lock_in_finally_on_success(): void
    {
        Process::fake([
            'curl*' => Process::result(output: 'OK', errorOutput: '', exitCode: 0),
        ]);
        $this->fakeExtractor();

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

    /* =================================================================
     * Dépôt manuel (source = upload)
     * ================================================================= */

    #[Test]
    public function it_skips_curl_for_upload_source_and_extracts_directly(): void
    {
        // Le fichier déposé existe déjà (renommé par l'orchestrator).
        $isoPath = '/tmp/sambaedu-test/iso/Win11_24H2.iso';
        @mkdir(dirname($isoPath), 0775, true);
        file_put_contents($isoPath, 'FAKE-ISO-CONTENT');

        Process::fake();
        $spy = $this->fakeExtractor();

        $download = WindowsIsoDownload::factory()->upload()->pending()->create([
            'iso_name'             => 'Win11_24H2.iso',
            'version'              => 'Win11',
            'initiated_by_user_id' => $this->user->id,
        ]);

        try {
            (new DownloadWindowsIsoJob($download->id))->handle();

            $download->refresh();
            self::assertSame(WindowsIsoDownloadStatus::Success, $download->status);
            self::assertSame(0, $download->exit_code);
            self::assertNotNull($download->started_at, 'started_at doit être posé même sans phase download.');

            // Aucun curl ne doit avoir tourné.
            Process::assertNotRan(fn ($p) => str_starts_with($p->command, 'curl'));

            // L'extraction a été invoquée avec la version + le chemin ISO résolu.
            self::assertCount(1, $spy->calls);
            self::assertSame('Win11', $spy->calls[0]['version']);
            self::assertSame($isoPath, $spy->calls[0]['isoPath']);
        } finally {
            @unlink($isoPath);
        }
    }

    #[Test]
    public function it_skips_curl_for_reinject_source_and_extracts_directly(): void
    {
        // Story 3.10 — ré-injection : l'ISO est déjà déployée/présente sur
        // disque. Le Job saute le curl et ré-extrait (fresh boot.wim + pack).
        $isoPath = '/tmp/sambaedu-test/iso/Win11_24H2.iso';
        @mkdir(dirname($isoPath), 0775, true);
        file_put_contents($isoPath, 'FAKE-ISO-CONTENT');

        Process::fake();
        $spy = $this->fakeExtractor();

        $download = WindowsIsoDownload::factory()->reinject()->pending()->create([
            'iso_name'             => 'Win11_24H2.iso',
            'version'              => 'Win11',
            'initiated_by_user_id' => $this->user->id,
        ]);

        try {
            (new DownloadWindowsIsoJob($download->id))->handle();

            $download->refresh();
            self::assertSame(WindowsIsoDownloadStatus::Success, $download->status);
            self::assertSame(0, $download->exit_code);

            Process::assertNotRan(fn ($p) => str_starts_with($p->command, 'curl'));
            self::assertCount(1, $spy->calls);
            self::assertSame('Win11', $spy->calls[0]['version']);
            self::assertSame($isoPath, $spy->calls[0]['isoPath']);
        } finally {
            @unlink($isoPath);
        }
    }

    #[Test]
    public function it_marks_failed_for_reinject_when_iso_missing(): void
    {
        Process::fake();
        $spy = $this->fakeExtractor();

        @unlink('/tmp/sambaedu-test/iso/Win11_GONE.iso');

        $download = WindowsIsoDownload::factory()->reinject()->pending()->create([
            'iso_name'             => 'Win11_GONE.iso',
            'version'              => 'Win11',
            'initiated_by_user_id' => $this->user->id,
        ]);

        (new DownloadWindowsIsoJob($download->id))->handle();

        $download->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Failed, $download->status);
        self::assertStringContainsString('reinject-missing', (string) $download->error);
        self::assertSame([], $spy->calls, 'Pas d\'extraction si l\'ISO source est absente.');
        Process::assertNothingRan();
    }

    #[Test]
    public function it_marks_failed_for_upload_when_file_is_missing(): void
    {
        Process::fake();
        $spy = $this->fakeExtractor();

        // Aucun fichier sur disque pour cette ISO.
        @unlink('/tmp/sambaedu-test/iso/Win11_MISSING.iso');

        $download = WindowsIsoDownload::factory()->upload()->pending()->create([
            'iso_name'             => 'Win11_MISSING.iso',
            'version'              => 'Win11',
            'initiated_by_user_id' => $this->user->id,
        ]);

        (new DownloadWindowsIsoJob($download->id))->handle();

        $download->refresh();
        self::assertSame(WindowsIsoDownloadStatus::Failed, $download->status);
        self::assertStringContainsString('upload-missing', (string) $download->error);
        self::assertSame([], $spy->calls, 'Pas d\'extraction si le fichier déposé est absent.');
        Process::assertNothingRan();
    }
}
