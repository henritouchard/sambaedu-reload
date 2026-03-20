<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminRights;
use App\Http\Middleware\SambaEduAuth;
use App\Models\LegacyCatchallLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests du Dashboard Legacy Monitor (/admin/legacy-monitor).
 *
 * La table legacy_catchall_logs est créée en mémoire (SQLite) dans setUp().
 * Le middleware RequireAdminRights est bypassé via withoutMiddleware() pour les
 * tests fonctionnels ; le test d'accès non-admin vérifie le comportement réel.
 */
class LegacyMonitorDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        if (! Schema::hasTable('legacy_catchall_logs')) {
            Schema::create('legacy_catchall_logs', function (Blueprint $table) {
                $table->id();
                $table->string('method', 10);
                $table->string('path', 2048);
                $table->string('ip', 45);
                $table->text('query_string')->nullable();
                $table->text('referer')->nullable();
                $table->timestamp('created_at');
            });
        }
    }

    protected function tearDown(): void
    {
        LegacyCatchallLog::query()->delete();
        parent::tearDown();
    }

    /**
     * AC4 — Admin authentifié peut accéder à /admin/legacy-monitor → 200
     */
    public function test_admin_can_access_legacy_monitor(): void
    {
        $response = $this->withoutMiddleware([SambaEduAuth::class, RequireAdminRights::class])
            ->get('/admin/legacy-monitor');

        $response->assertStatus(200);
    }

    /**
     * AC4 — Utilisateur non-admin (pas de session) → redirigé ou 403
     */
    public function test_non_admin_is_redirected_or_forbidden(): void
    {
        $response = $this->get('/admin/legacy-monitor');

        // Le middleware RequireAdminRights renvoie un redirect si pas d'utilisateur
        $response->assertRedirect();
    }

    /**
     * AC1 — La page affiche les données de legacy_catchall_logs
     */
    public function test_page_displays_catchall_log_data(): void
    {
        LegacyCatchallLog::create([
            'method'       => 'GET',
            'path'         => 'app/old-users.php',
            'ip'           => '192.168.1.1',
            'query_string' => null,
            'referer'      => null,
            'created_at'   => now(),
        ]);

        $response = $this->withoutMiddleware([SambaEduAuth::class, RequireAdminRights::class])
            ->get('/admin/legacy-monitor');

        $response->assertStatus(200);
        $response->assertSee('app/old-users.php');
    }

    /**
     * AC3 — Filtre par path : la page filtrée n'affiche que les lignes matchantes
     */
    public function test_filter_by_path_returns_matching_rows_only(): void
    {
        LegacyCatchallLog::create([
            'method' => 'GET', 'path' => 'app/users.php',
            'ip' => '10.0.0.1', 'query_string' => null, 'referer' => null,
            'created_at' => now(),
        ]);
        LegacyCatchallLog::create([
            'method' => 'GET', 'path' => 'app/machines.php',
            'ip' => '10.0.0.1', 'query_string' => null, 'referer' => null,
            'created_at' => now(),
        ]);

        $response = $this->withoutMiddleware([SambaEduAuth::class, RequireAdminRights::class])
            ->get('/admin/legacy-monitor?filterPath=users');

        $response->assertStatus(200);
        $response->assertSee('app/users.php');
        $response->assertDontSee('app/machines.php');
    }

    /**
     * AC3 — Filtre par méthode HTTP : seules les lignes de la méthode sélectionnée
     */
    public function test_filter_by_method_returns_matching_rows_only(): void
    {
        LegacyCatchallLog::create([
            'method' => 'GET', 'path' => 'app/users.php',
            'ip' => '10.0.0.1', 'query_string' => null, 'referer' => null,
            'created_at' => now(),
        ]);
        LegacyCatchallLog::create([
            'method' => 'POST', 'path' => 'app/form.php',
            'ip' => '10.0.0.1', 'query_string' => null, 'referer' => null,
            'created_at' => now(),
        ]);

        $response = $this->withoutMiddleware([SambaEduAuth::class, RequireAdminRights::class])
            ->get('/admin/legacy-monitor?filterMethod=POST');

        $response->assertStatus(200);
        $response->assertSee('app/form.php');
        $response->assertDontSee('app/users.php');
    }
}
