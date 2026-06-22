<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\InstallationStatus;
use App\Jobs\InstallApplicationJob;
use App\Models\Application;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Models\InstallationLog;
use App\Services\AppStore\AppStoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesAppStoreSchema;

/**
 * Story 8.2.7 (AC4, AC7) — Tests du Job d'installation en tâche de fond.
 *
 * Couvre :
 *  - implémentation ShouldQueue + tries/backoff/timeout + WithoutOverlapping ;
 *  - handle() délègue à AppStoreService::installApplication() avec le bon
 *    DepotApplication et le bon initiated_by ;
 *  - handle() sur une row supprimée → pas d'appel + warning ;
 *  - failed() idempotent : ne réécrit pas un log déjà terminal, passe un log
 *    non-terminal en Failed + l'application en Error.
 */
class InstallApplicationJobTest extends TestCase
{
    use CreatesAppStoreSchema;

    private Depot $depot;
    private DepotApplication $depotApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAppStoreSchema();
        Log::spy();

        $this->depot = Depot::create([
            'name' => 'Test Depot',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => false,
            'is_active' => true,
        ]);

        $this->depotApp = DepotApplication::create([
            'depot_id' => $this->depot->id,
            'app_id' => 'job-test-app',
            'name' => 'Job Test Application',
            'version' => '1.0.0',
            'category' => 'Test',
            'compatibility' => '10',
            'branch' => 'stable',
            'xml_url' => 'http://test.example.com/wpkg/stable/job-test-app.xml',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->dropAppStoreSchema();
        parent::tearDown();
    }

    /* =================================================================
     * Implementation checks
     * ================================================================= */

    #[Test]
    public function it_implements_should_queue_with_tries_backoff_and_timeout(): void
    {
        config(['sambaedu.wpkg.download_timeout' => 300]);
        $job = new InstallApplicationJob($this->depotApp->id, 'alice');

        self::assertInstanceOf(ShouldQueue::class, $job);
        self::assertSame(3, $job->tries);
        self::assertSame(30, $job->backoff);
        // 12 * 300 + 300 = 3900s — bien au-delà du retry_after=90s database.
        self::assertSame(3900, $job->timeout);
        self::assertGreaterThan(90, $job->timeout, 'timeout doit dépasser retry_after=90s');
    }

    #[Test]
    public function it_targets_the_default_queue(): void
    {
        $job = new InstallApplicationJob($this->depotApp->id, 'alice');
        self::assertSame('default', $job->queue);
    }

    #[Test]
    public function it_declares_without_overlapping_middleware_keyed_on_app(): void
    {
        $job = new InstallApplicationJob($this->depotApp->id, 'alice');
        $middlewares = $job->middleware();

        self::assertCount(1, $middlewares);
        self::assertInstanceOf(WithoutOverlapping::class, $middlewares[0]);
        // La key DOIT être dérivée de l'app_id du dépôt (identité fonctionnelle
        // de l'app), et non de l'id de row : c'est ce qui sérialise deux jobs
        // sur la MÊME application. Un retour accidentel à depotApplicationId
        // casserait la protection anti-double-pickup sans casser ce test sinon.
        self::assertSame('appstore.install.job-test-app', $middlewares[0]->key);
    }

    /* =================================================================
     * handle()
     * ================================================================= */

    #[Test]
    public function handle_delegates_to_install_application_with_correct_args(): void
    {
        $mock = Mockery::mock(AppStoreService::class);
        $mock->shouldReceive('installApplication')
            ->once()
            ->withArgs(function (DepotApplication $depotApp, string $initiatedBy): bool {
                return $depotApp->id === $this->depotApp->id
                    && $initiatedBy === 'bob';
            });

        $job = new InstallApplicationJob($this->depotApp->id, 'bob');
        $job->handle($mock);

        // L'assertion est portée par ->once() + withArgs().
        $this->assertTrue(true);
    }

    #[Test]
    public function handle_does_not_call_service_when_depot_application_missing(): void
    {
        $mock = Mockery::mock(AppStoreService::class);
        $mock->shouldNotReceive('installApplication');

        $job = new InstallApplicationJob(999999, 'carol');
        $job->handle($mock);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'introuvable'))
            ->once();

        // Garantit que la méthode ne s'est pas terminée par une exception
        // silencieuse et que le service n'a pas été sollicité (shouldNotReceive).
        self::assertTrue(true);
    }

    /* =================================================================
     * failed() — garde-fou idempotent
     * ================================================================= */

    #[Test]
    public function failed_marks_non_terminal_log_as_failed_and_app_as_error(): void
    {
        $application = Application::create([
            'depot_id' => $this->depot->id,
            'app_id' => $this->depotApp->app_id,
            'name' => $this->depotApp->name,
            'version' => '1.0.0',
            'status' => ApplicationStatus::Downloading,
        ]);

        $log = InstallationLog::create([
            'application_id' => $application->id,
            'status' => InstallationStatus::Downloading,
            'version' => '1.0.0',
            'initiated_by' => 'dave',
            'progress' => 30,
            'started_at' => now(),
        ]);

        $job = new InstallApplicationJob($this->depotApp->id, 'dave');
        $job->failed(new \RuntimeException('boom réseau'));

        $log->refresh();
        $application->refresh();

        self::assertSame(InstallationStatus::Failed, $log->status);
        self::assertNotNull($log->completed_at);
        self::assertStringContainsString('boom réseau', (string) $log->message);
        self::assertSame(ApplicationStatus::Error, $application->status);
    }

    #[Test]
    public function failed_does_not_rewrite_an_already_terminal_log(): void
    {
        $application = Application::create([
            'depot_id' => $this->depot->id,
            'app_id' => $this->depotApp->app_id,
            'name' => $this->depotApp->name,
            'version' => '1.0.0',
            'status' => ApplicationStatus::Installed,
        ]);

        $log = InstallationLog::create([
            'application_id' => $application->id,
            'status' => InstallationStatus::Success,
            'version' => '1.0.0',
            'initiated_by' => 'eve',
            'progress' => 100,
            'message' => 'Installation terminee avec succes',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        $job = new InstallApplicationJob($this->depotApp->id, 'eve');
        $job->failed(new \RuntimeException('appel tardif'));

        $log->refresh();
        $application->refresh();

        // Le log Success n'est PAS écrasé (le catch du service l'avait déjà
        // résolu, ou bien c'était bien un succès).
        self::assertSame(InstallationStatus::Success, $log->status);
        self::assertSame('Installation terminee avec succes', $log->message);
        self::assertSame(ApplicationStatus::Installed, $application->status);
    }

    #[Test]
    public function failed_is_noop_when_depot_application_missing(): void
    {
        $job = new InstallApplicationJob(999999, 'frank');

        // Ne doit pas lever d'exception ni toucher la base.
        $job->failed(new \RuntimeException('orphelin'));

        self::assertSame(0, InstallationLog::count());
    }
}
