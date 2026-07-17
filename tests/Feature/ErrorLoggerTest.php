<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminRights;
use App\Http\Middleware\Auth\SambaEduAuth;
use App\Models\ErrorLog;
use App\Services\ErrorLoggerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests de l'Error Logger (service, handler Laravel, dashboard admin).
 *
 * La table error_logs est créée en mémoire (SQLite) dans setUp().
 */
class ErrorLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        if (! Schema::hasTable('error_logs')) {
            Schema::create('error_logs', function (Blueprint $table) {
                $table->id();
                $table->string('source', 10);
                $table->text('message');
                $table->timestamp('created_at');
            });
        }
    }

    protected function tearDown(): void
    {
        ErrorLog::query()->delete();
        parent::tearDown();
    }

    // ─── Tests unitaires ErrorLoggerService ───────────────────────────────

    /**
     * AC1/AC2 — Le service insère correctement en DB avec source et message.
     */
    public function test_service_logs_error_in_database(): void
    {
        $service = new ErrorLoggerService();
        $service->log('laravel', 'Test exception message');

        $this->assertDatabaseHas('error_logs', [
            'source' => 'laravel',
            'message' => 'Test exception message',
        ]);
    }

    /**
     * AC1/AC2 — Le service ne throw pas si la DB est inaccessible (silencieux).
     */
    public function test_service_does_not_throw_on_db_failure(): void
    {
        // Drop la table pour simuler un échec DB
        Schema::dropIfExists('error_logs');

        Log::shouldReceive('error')->once();

        $service = new ErrorLoggerService();
        $service->log('laravel', 'This should fail silently');

        // Si on arrive ici sans exception, le test passe
        $this->assertTrue(true);

        // Recréer la table pour le tearDown
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 10);
            $table->text('message');
            $table->timestamp('created_at');
        });
    }

    // ─── Tests Feature : Handler Laravel ──────────────────────────────────

    /**
     * AC2 — Le Handler Laravel logge les exceptions via ErrorLoggerService.
     *
     * On déclenche une vraie exception via report() pour exercer le chemin
     * Handler::reportable() → ErrorLoggerService::log().
     */
    public function test_laravel_handler_logs_exception(): void
    {
        $exception = new \RuntimeException('Handler integration test exception');

        // report() passe par Handler::reportable() qui appelle ErrorLoggerService
        report($exception);

        $this->assertDatabaseHas('error_logs', [
            'source' => 'laravel',
            'message' => 'Handler integration test exception',
        ]);
    }

    // ─── Tests Feature : Dashboard admin ──────────────────────────────────
    //
    // Error Logger est désormais l'onglet « Logs » de la page
    // /admin/settings/system-status (décision Henri 2026-07-17). Il capte aussi
    // les exceptions Laravel (diagnostic runtime SE5), donc PAS dans « Migration ».
    // La feature est embarquée : `/admin/error-logger` redirige vers cet onglet
    // et le contenu se teste directement sur le composant Livewire embarqué.

    /**
     * L'ancienne route /admin/error-logger redirige vers l'onglet « Logs »
     * de l'État du système.
     */
    public function test_error_logger_url_redirects_to_system_status_logs_tab(): void
    {
        $response = $this->withoutMiddleware([SambaEduAuth::class, RequireAdminRights::class])
            ->get('/admin/error-logger');

        $response->assertRedirect('/admin/settings/system-status?tab=logs');
    }

    /**
     * AC5 — Utilisateur non-admin → redirigé (auth du groupe /admin).
     */
    public function test_non_admin_is_redirected(): void
    {
        $response = $this->get('/admin/error-logger');

        $response->assertRedirect();
    }

    /**
     * AC3 — Le composant embarqué affiche les erreurs loggées.
     */
    public function test_page_displays_error_log_data(): void
    {
        ErrorLog::create([
            'source' => 'legacy',
            'message' => 'Undefined variable $foo',
            'created_at' => now(),
        ]);

        \Livewire\Livewire::test('pages::admin.error-logger.index')
            ->assertStatus(200)
            ->assertSee('Undefined variable $foo')
            ->assertSee('legacy');
    }

    /**
     * AC4 — Le filtre par source retourne les bonnes entrées.
     */
    public function test_filter_by_source_returns_matching_rows_only(): void
    {
        ErrorLog::create([
            'source' => 'legacy',
            'message' => 'Legacy PHP warning',
            'created_at' => now(),
        ]);
        ErrorLog::create([
            'source' => 'laravel',
            'message' => 'Laravel runtime exception',
            'created_at' => now(),
        ]);

        \Livewire\Livewire::test('pages::admin.error-logger.index')
            ->set('sourceFilter', 'legacy')
            ->assertStatus(200)
            ->assertSee('Legacy PHP warning')
            ->assertDontSee('Laravel runtime exception');
    }
}
