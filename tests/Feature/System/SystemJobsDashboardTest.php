<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\TestCase;
use App\Models\User;

/**
 * Tests Feature Livewire — Dashboard jobs récents GPO/WPKG.
 *
 * Story 16.14 AC7.1 / AC5.x.
 *
 * Note : les tests de retry/cancel utilisent des mocks DB ou Queue::fake()
 * car le driver queue est `sync` en environnement test (D15).
 */
class SystemJobsDashboardTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->bootstrapSpatieTables();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private function makeAdmin(string $login = 'admin-jobs'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeUser(string $login = 'user-jobs'): User
    {
        return User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
    }

    #[Test]
    public function it_renders_jobs_page_for_admin(): void
    {
        $admin = $this->makeAdmin('admin-jobs-200');
        $this->actingAs($admin);

        // sambaedu.auth lit $_SESSION (non touché par actingAs), bypass requis
        // pour atteindre la page — iso-pattern WinePageTest (Story 16.9).
        $this->withoutMiddleware([
            \App\Http\Middleware\Auth\SambaEduAuth::class,
            \App\Http\Middleware\RequireAdminRights::class,
        ]);

        $response = $this->get('/admin/settings/system/jobs');
        $response->assertStatus(200);
    }

    #[Test]
    public function it_returns_403_for_non_admin(): void
    {
        $user = $this->makeUser('user-jobs-403');
        $this->actingAs($user);

        $this->withoutMiddleware([
            \App\Http\Middleware\Auth\SambaEduAuth::class,
            \App\Http\Middleware\RequireAdminRights::class,
        ]);

        $response = $this->get('/admin/settings/system/jobs');
        $response->assertStatus(403);
    }

    #[Test]
    public function it_renders_polling_indicator(): void
    {
        $admin = $this->makeAdmin('admin-jobs-polling');
        $this->actingAs($admin);

        Livewire::test('pages::admin.settings.system.jobs.index')
            ->assertSeeHtml('data-testid="polling-indicator"');
    }

    #[Test]
    public function it_shows_driver_unsupported_alert_when_not_database(): void
    {
        $admin = $this->makeAdmin('admin-jobs-driver');
        $this->actingAs($admin);

        // En environnement test le driver est `sync` ou `array`
        // Le driver unsupported doit afficher l'alerte D15
        Livewire::test('pages::admin.settings.system.jobs.index')
            ->assertSeeHtml('data-testid="driver-unsupported-alert"');
    }

    #[Test]
    public function it_shows_no_pending_jobs_state_when_database_driver_and_empty(): void
    {
        // Config driver database pour ce test
        config(['queue.default' => 'database']);

        $admin = $this->makeAdmin('admin-jobs-empty');
        $this->actingAs($admin);

        // Créer les tables jobs/failed_jobs si elles n'existent pas
        if (!\Illuminate\Support\Facades\Schema::hasTable('jobs')) {
            \Illuminate\Support\Facades\Schema::create('jobs', function ($table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('failed_jobs')) {
            \Illuminate\Support\Facades\Schema::create('failed_jobs', function ($table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        Livewire::test('pages::admin.settings.system.jobs.index')
            ->assertSeeHtml('data-testid="no-pending-jobs"')
            ->assertSeeHtml('data-testid="no-failed-jobs"');
    }

    #[Test]
    public function it_has_cancel_job_and_retry_job_methods(): void
    {
        $admin = $this->makeAdmin('admin-jobs-methods');
        $this->actingAs($admin);

        $component = Livewire::test('pages::admin.settings.system.jobs.index');
        $reflection = new \ReflectionClass($component->instance());
        self::assertTrue($reflection->hasMethod('cancelJob'));
        self::assertTrue($reflection->hasMethod('retryJob'));
        self::assertTrue($reflection->hasMethod('refreshJobs'));
    }

    /**
     * Helper : crée les tables jobs + failed_jobs si elles n'existent pas.
     */
    private function ensureJobTables(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('jobs')) {
            \Illuminate\Support\Facades\Schema::create('jobs', function ($table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('failed_jobs')) {
            \Illuminate\Support\Facades\Schema::create('failed_jobs', function ($table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    #[Test]
    public function cancel_job_succeeds_when_job_is_pending(): void
    {
        // Config driver database
        config(['queue.default' => 'database']);

        $admin = $this->makeAdmin('admin-jobs-cancel-pending');
        $this->actingAs($admin);

        $this->ensureJobTables();

        // Insérer un job NON réservé (pending)
        DB::table('jobs')->insert([
            'id'           => 8888,
            'queue'        => 'default',
            'payload'      => json_encode(['displayName' => 'App\Gpo\Jobs\GenerateWineImageJob']),
            'attempts'     => 0,
            'reserved_at'  => null, // pas encore pris par un worker
            'available_at' => time(),
            'created_at'   => time(),
        ]);

        // Log::channel('gpo')->log(...) chaîne deux appels : un simple
        // `Log::spy()` retourne null sur `channel()` et casse l'enchaînement.
        // On expose un Mockery::self pour que `channel()` retourne un objet
        // chainable supportant `->log()` (et autres niveaux).
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        // cancelJob(8888) doit réussir (job supprimé) → toast success
        Livewire::test('pages::admin.settings.system.jobs.index')
            ->call('cancelJob', 8888)
            ->assertDispatched('toastMagic');

        // Vérifier que le job a bien été supprimé de la table
        self::assertSame(0, DB::table('jobs')->where('id', 8888)->count(), 'Le job pending doit être supprimé après cancel.');

        // Vérifier qu'un log 'gpo.job.cancel success' a été émis (GpoLogger → Log::channel('gpo'))
        Log::shouldHaveReceived('channel')->with('gpo')->atLeast()->once();
    }

    #[Test]
    public function paginates_jobs_dashboard_at_20_per_page(): void
    {
        // Story 16.14 Q4 — AC5.2 : pagination 20/page.
        config(['queue.default' => 'database']);

        $admin = $this->makeAdmin('admin-jobs-pagination');
        $this->actingAs($admin);

        $this->ensureJobTables();

        // Insérer 25 jobs pending watched.
        for ($i = 1; $i <= 25; $i++) {
            DB::table('jobs')->insert([
                'queue'        => 'default',
                'payload'      => json_encode(['displayName' => 'App\Gpo\Jobs\GenerateWineImageJob', 'data' => ['n' => $i]]),
                'attempts'     => 0,
                'reserved_at'  => null,
                'available_at' => time(),
                'created_at'   => time() - $i,
            ]);
        }

        $component = Livewire::test('pages::admin.settings.system.jobs.index');

        // Page 1 doit contenir 20 jobs.
        $component->assertSet('totalPending', 25);
        self::assertCount(20, $component->get('pendingJobs'), 'Page 1 = 20 jobs (perPage=20).');

        // Aller page 2 → 5 jobs restants.
        $component->call('goToPendingPage', 2);
        $component->assertSet('pendingPage', 2);
        self::assertCount(5, $component->get('pendingJobs'), 'Page 2 = 5 jobs restants sur 25.');

        // Aller page 99 → clampée à dernière page (2).
        $component->call('goToPendingPage', 99);
        $component->assertSet('pendingPage', 2);
    }

    #[Test]
    public function cancel_job_emits_warning_when_already_running(): void
    {
        // Config driver database
        config(['queue.default' => 'database']);

        $admin = $this->makeAdmin('admin-jobs-cancel-running');
        $this->actingAs($admin);

        $this->ensureJobTables();

        // Insérer un job avec reserved_at != null (running)
        DB::table('jobs')->insert([
            'id'           => 9999,
            'queue'        => 'default',
            'payload'      => json_encode(['displayName' => 'App\Gpo\Jobs\GenerateWineImageJob']),
            'attempts'     => 1,
            'reserved_at'  => time(), // déjà réservé
            'available_at' => time(),
            'created_at'   => time(),
        ]);

        // Log::channel('gpo')->log(...) chaîne deux appels : un simple
        // `Log::spy()` retourne null sur `channel()` et casse l'enchaînement.
        // On expose un Mockery::self pour que `channel()` retourne un objet
        // chainable supportant `->log()` (et autres niveaux).
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        // cancelJob(9999) doit émettre un warning (toast) car reserved_at != null
        // Et log failure (finding #11 : échec sémantique sur no-op)
        Livewire::test('pages::admin.settings.system.jobs.index')
            ->call('cancelJob', 9999)
            ->assertDispatched('toastMagic');

        // Le job ne doit PAS être supprimé (il est running)
        self::assertSame(1, DB::table('jobs')->where('id', 9999)->count(), 'Le job running ne doit pas être supprimé.');

        // Vérifier qu'un log 'gpo.job.cancel failure' a été émis
        Log::shouldHaveReceived('channel')->with('gpo')->atLeast()->once();
    }
}


