<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Exceptions\ExtensionOperationException;
use App\Jobs\RunExtensionOperationJob;
use App\Models\Extension;
use App\Models\ExtensionInstallRun;
use App\Models\User;
use App\Services\Extensions\ExtensionOperationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.3 (AC2, AC5) — L'orchestrateur de runs : ce qui garantit qu'un clic
 * produit UNE ligne d'état et UN Job, et que deux clics n'en produisent pas
 * deux.
 *
 * ⚠️ `phpunit.xml` force `QUEUE_CONNECTION=sync` : un dispatch s'exécute INLINE
 * en test. Pour prouver « mis en file » sans l'exécuter, on utilise
 * `Queue::fake()` — sans quoi ces tests testeraient le Job, pas l'orchestrateur.
 */
class ExtensionOperationRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function runner(): ExtensionOperationRunner
    {
        return $this->app->make(ExtensionOperationRunner::class);
    }

    private function admin(string $login = 'ops-admin'): User
    {
        return User::query()->create([
            'login' => $login,
            'role' => 'autre',
            'is_active' => true,
        ]);
    }

    private function app_(): Extension
    {
        return Extension::factory()->app()->withInstallBlock()->create(['key' => 'hello']);
    }

    // =====================================================================
    // AC2 — un clic = une ligne d'état + un Job
    // =====================================================================

    #[Test]
    public function starting_an_operation_creates_a_pending_run_and_queues_the_job(): void
    {
        Queue::fake();

        $extension = $this->app_();
        $admin = $this->admin();

        $run = $this->runner()->start(ExtensionInstallRun::OPERATION_INSTALL, $extension->id, $admin);

        self::assertSame(ExtensionInstallRun::STATUS_PENDING, $run->status);
        self::assertSame(ExtensionInstallRun::OPERATION_INSTALL, $run->operation);
        self::assertSame($extension->id, $run->extension_id);
        self::assertSame($admin->id, $run->requested_by_user_id);
        self::assertSame('ops-admin', $run->requested_by_login, 'le login est dénormalisé : la trace survit au départ de l\'admin');
        self::assertNull($run->started_at);

        Queue::assertPushedOn('default', RunExtensionOperationJob::class);
        Queue::assertPushed(RunExtensionOperationJob::class, fn (RunExtensionOperationJob $job): bool => $job->runId === $run->id);
    }

    #[Test]
    public function the_job_carries_an_identifier_never_a_serialized_model(): void
    {
        // Un payload `SerializesModels` référençant un admin supprimé entre le
        // clic et le pickup lèverait `ModelNotFoundException` au unserialize,
        // hors de tout filet applicatif.
        Queue::fake();

        $run = $this->runner()->start(ExtensionInstallRun::OPERATION_INSTALL, $this->app_()->id, $this->admin());

        Queue::assertPushed(RunExtensionOperationJob::class, function (RunExtensionOperationJob $job) use ($run): bool {
            $properties = get_object_vars($job);

            foreach ($properties as $value) {
                self::assertFalse($value instanceof \Illuminate\Database\Eloquent\Model);
            }

            return $job->runId === $run->id;
        });
    }

    #[Test]
    public function the_job_timeout_comes_from_the_configuration(): void
    {
        Config::set('extensions.install.job_timeout', 4242);

        $job = new RunExtensionOperationJob(1);

        self::assertSame(4242, $job->timeout);
        self::assertSame(1, $job->tries, 'un échec d\'installation est terminal');
        self::assertSame([], $job->middleware(), 'WithoutOverlapping s\'appuie sur APCu : interdit (piège daté)');
    }

    // =====================================================================
    // AC5 — concurrence
    // =====================================================================

    #[Test]
    public function a_second_operation_is_refused_while_one_is_active(): void
    {
        Queue::fake();

        $first = $this->app_();
        $second = Extension::factory()->app()->withInstallBlock()->create(['key' => 'autre']);
        $admin = $this->admin();

        $this->runner()->start(ExtensionInstallRun::OPERATION_INSTALL, $first->id, $admin);

        try {
            $this->runner()->start(ExtensionInstallRun::OPERATION_INSTALL, $second->id, $this->admin('second-admin'));
            self::fail('Le verrou du moteur est GLOBAL : l\'orchestrateur doit le refléter.');
        } catch (ExtensionOperationException $e) {
            self::assertStringContainsString('déjà en cours', $e->getMessage());
        }

        self::assertSame(1, ExtensionInstallRun::query()->count(), 'une seule ligne active, un seul Job');
        Queue::assertPushed(RunExtensionOperationJob::class, 1);
    }

    #[Test]
    public function a_double_click_on_the_same_extension_creates_a_single_run(): void
    {
        Queue::fake();

        $extension = $this->app_();
        $admin = $this->admin();

        $this->runner()->start(ExtensionInstallRun::OPERATION_INSTALL, $extension->id, $admin);

        $this->expectException(ExtensionOperationException::class);
        $this->runner()->start(ExtensionInstallRun::OPERATION_INSTALL, $extension->id, $admin);
    }

    #[Test]
    public function a_terminated_run_does_not_block_the_next_operation(): void
    {
        Queue::fake();

        $extension = $this->app_();
        ExtensionInstallRun::factory()->for($extension, 'extension')->success()->create();

        $run = $this->runner()->start(ExtensionInstallRun::OPERATION_REMOVE, $extension->id, $this->admin());

        self::assertSame(ExtensionInstallRun::STATUS_PENDING, $run->status);
    }

    #[Test]
    public function a_stale_run_no_longer_blocks_the_library(): void
    {
        // Un worker tué ne doit pas condamner la bibliothèque : passé le
        // timeout du Job + marge, le run cesse d'être « actif » pour l'UI.
        Queue::fake();

        $extension = $this->app_();
        ExtensionInstallRun::factory()->for($extension, 'extension')->stale()->create();

        self::assertNull($this->runner()->hasActiveRun());

        $run = $this->runner()->start(ExtensionInstallRun::OPERATION_INSTALL, $extension->id, $this->admin());

        self::assertSame(ExtensionInstallRun::STATUS_PENDING, $run->status);
        self::assertSame(2, ExtensionInstallRun::query()->count(), 'le run interrompu n\'est ni réécrit ni retraité');
    }

    #[Test]
    public function a_run_that_is_merely_waiting_for_a_busy_worker_is_not_declared_stale(): void
    {
        // La marge protège du faux positif : un run en file derrière un autre
        // travail n'est pas un run mort.
        Queue::fake();

        $extension = $this->app_();
        $run = ExtensionInstallRun::factory()->for($extension, 'extension')->create();

        ExtensionInstallRun::query()->where('id', $run->id)->update([
            'created_at' => now()->subSeconds(60),
            'updated_at' => now()->subSeconds(60),
        ]);

        self::assertNotNull($this->runner()->hasActiveRun());
    }

    #[Test]
    public function the_staleness_threshold_follows_the_job_timeout(): void
    {
        Config::set('extensions.install.job_timeout', 900);

        self::assertGreaterThan(900, $this->runner()->staleAfterSeconds());
    }

    #[Test]
    public function a_creation_lock_held_elsewhere_refuses_rather_than_racing(): void
    {
        Queue::fake();

        $extension = $this->app_();

        // ⚠️ `Cache::store('file')` et non `Cache::lock()` : le store par défaut
        // du projet est APCu, qui n'a aucun support de lock.
        $lock = Cache::store('file')->lock('extensions:ui-run', 10);
        self::assertTrue($lock->get());

        try {
            $this->runner()->start(ExtensionInstallRun::OPERATION_INSTALL, $extension->id, $this->admin());
            self::fail('La création de run doit être sérialisée.');
        } catch (ExtensionOperationException $e) {
            self::assertStringContainsString('déjà en cours', $e->getMessage());
        } finally {
            $lock->release();
        }

        self::assertSame(0, ExtensionInstallRun::query()->count());

        // Le verrou relâché, l'opération redevient possible : il n'a pas fuité.
        self::assertNotNull($this->runner()->start(ExtensionInstallRun::OPERATION_INSTALL, $extension->id, $this->admin('autre')));
    }

    // =====================================================================
    // AC2 — atomicité row ⇒ Job
    // =====================================================================

    #[Test]
    public function a_dispatch_that_fails_leaves_no_orphan_row(): void
    {
        // Avec le driver `database`, le dispatch est un INSERT qui peut
        // échouer. Sans transaction, on obtiendrait une ligne « en cours » que
        // personne n'exécuterait : l'écran mentirait jusqu'à la staleness.
        $extension = $this->app_();

        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('file d\'attente indisponible'));

        try {
            $this->runner()->start(ExtensionInstallRun::OPERATION_INSTALL, $extension->id, $this->admin());
            self::fail('Le dispatch en échec doit remonter.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('file d\'attente indisponible', $e->getMessage());
        }

        self::assertSame(0, ExtensionInstallRun::query()->count(), 'la transaction doit avoir tout annulé');
    }

    // =====================================================================
    // Gardes de base
    // =====================================================================

    #[Test]
    public function an_unknown_extension_is_refused_without_creating_a_run(): void
    {
        Queue::fake();

        $this->expectException(ExtensionOperationException::class);

        try {
            $this->runner()->start(ExtensionInstallRun::OPERATION_INSTALL, 999_999, $this->admin());
        } finally {
            self::assertSame(0, ExtensionInstallRun::query()->count());
            Queue::assertNothingPushed();
        }
    }

    #[Test]
    public function a_link_extension_is_refused_the_background_channel(): void
    {
        Queue::fake();

        $extension = Extension::factory()->link()->create(['key' => 'doc']);

        try {
            $this->runner()->start(ExtensionInstallRun::OPERATION_INSTALL, $extension->id, $this->admin());
            self::fail('Le canal de fond n\'existe que pour le type `app`.');
        } catch (ExtensionOperationException $e) {
            self::assertStringContainsString('lien', $e->getMessage());
        }

        self::assertSame(0, ExtensionInstallRun::query()->count());
    }

    #[Test]
    public function an_unsupported_operation_is_refused(): void
    {
        Queue::fake();

        $this->expectException(ExtensionOperationException::class);

        $this->runner()->start('reboot', $this->app_()->id, $this->admin());
    }

    // =====================================================================
    // Lecture : UNE requête, des tableaux plats
    // =====================================================================

    #[Test]
    public function the_library_reads_the_latest_run_of_each_extension(): void
    {
        $a = $this->app_();
        $b = Extension::factory()->app()->withInstallBlock()->create(['key' => 'autre']);

        ExtensionInstallRun::factory()->for($a, 'extension')->failed('sha256 du paquet non concordant')->create();
        ExtensionInstallRun::factory()->for($a, 'extension')->success()->create();
        ExtensionInstallRun::factory()->for($b, 'extension')->running()->create();

        $state = $this->runner()->runsForLibrary();

        self::assertCount(2, $state['by_extension']);
        self::assertSame(ExtensionInstallRun::STATUS_SUCCESS, $state['by_extension'][$a->id]['status'], 'le DERNIER run fait foi');
        self::assertSame(ExtensionInstallRun::STATUS_RUNNING, $state['by_extension'][$b->id]['status']);
        self::assertNotNull($state['active']);
        self::assertSame($b->id, $state['active']['extension_id']);
    }

    #[Test]
    public function a_presented_run_carries_french_step_labels_not_raw_constants(): void
    {
        $extension = $this->app_();
        ExtensionInstallRun::factory()->for($extension, 'extension')->running()->create([
            'current_step' => \App\Services\Extensions\ExtensionInstallService::STEP_APT,
            'steps' => [
                \App\Services\Extensions\ExtensionInstallService::STEP_PACKAGE,
                \App\Services\Extensions\ExtensionInstallService::STEP_OIDC,
            ],
        ]);

        $row = $this->runner()->latestRunFor($extension->id);

        self::assertNotNull($row);
        self::assertSame('Intégration', $row['operation_label']);
        self::assertSame('paquet installé (apt)', $row['current_step_label']);
        self::assertSame(
            ['paquet téléchargé et sha256 vérifié', 'client OIDC enregistré'],
            array_column($row['steps'], 'label'),
        );
        self::assertTrue($row['is_active']);
    }

    #[Test]
    public function a_stale_run_is_presented_as_interrupted_and_no_longer_active(): void
    {
        $extension = $this->app_();
        ExtensionInstallRun::factory()->for($extension, 'extension')->stale()->create();

        $row = $this->runner()->latestRunFor($extension->id);

        self::assertNotNull($row);
        self::assertTrue($row['is_stale']);
        self::assertFalse($row['is_active']);
        self::assertSame('Interrompue', $row['status_label']);
    }

    #[Test]
    public function a_technical_failure_category_is_translated_for_the_admin(): void
    {
        $extension = $this->app_();
        ExtensionInstallRun::factory()->for($extension, 'extension')
            ->failed(ExtensionInstallRun::ERROR_ENGINE_BUSY)
            ->create();

        $row = $this->runner()->latestRunFor($extension->id);

        self::assertNotNull($row);
        self::assertSame(ExtensionInstallRun::ERROR_ENGINE_BUSY, $row['error']);
        self::assertStringContainsString('moteur', $row['error_label']);
    }

    #[Test]
    public function an_engine_category_is_shown_verbatim(): void
    {
        // Les catégories du moteur sont déjà françaises, courtes et sans URL :
        // les re-traduire créerait une seconde source de vérité.
        $extension = $this->app_();
        ExtensionInstallRun::factory()->for($extension, 'extension')
            ->failed('sha256 du paquet non concordant')
            ->create();

        $row = $this->runner()->latestRunFor($extension->id);

        self::assertNotNull($row);
        self::assertSame('sha256 du paquet non concordant', $row['error_label']);
    }

    #[Test]
    public function reading_an_empty_registry_is_a_clean_empty_state(): void
    {
        $state = $this->runner()->runsForLibrary();

        self::assertSame(['active' => null, 'by_extension' => []], $state);
        self::assertNull($this->runner()->latestRunFor(1));
    }
}
