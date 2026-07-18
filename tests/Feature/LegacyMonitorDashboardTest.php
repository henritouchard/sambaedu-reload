<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminRights;
use App\Http\Middleware\Auth\SambaEduAuth;
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
                // Story 38.2 (migration 2026_07_10) — le dashboard groupe par
                // `source` (tombstone|catchall) ; sans cette colonne la requête
                // d'agrégation lève « no such column: source » → 500.
                $table->string('source', 16)->default('catchall');
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

    // Legacy Monitor est désormais l'onglet « Legacy Monitor » de la page
    // /admin/settings/migration (décision Henri 2026-07-17). La feature est
    // embarquée : `/admin/legacy-monitor` redirige vers cet onglet et le contenu
    // se teste directement sur le composant Livewire embarqué.

    /**
     * L'ancienne route /admin/legacy-monitor redirige vers l'onglet migration.
     */
    public function test_legacy_monitor_url_redirects_to_migration_tab(): void
    {
        $response = $this->withoutMiddleware([SambaEduAuth::class, RequireAdminRights::class])
            ->get('/admin/legacy-monitor');

        $response->assertRedirect('/admin/settings/migration?tab=legacy-monitor');
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
     * AC1 — Le composant embarqué affiche les données de legacy_catchall_logs
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

        \Livewire\Livewire::test('pages::admin.legacy-monitor.index')
            ->assertStatus(200)
            ->assertSee('app/old-users.php');
    }

    /**
     * AC3 — Filtre par path : seules les lignes matchantes sont affichées
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

        \Livewire\Livewire::test('pages::admin.legacy-monitor.index')
            ->set('filterPath', 'users')
            ->assertStatus(200)
            ->assertSee('app/users.php')
            ->assertDontSee('app/machines.php');
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

        \Livewire\Livewire::test('pages::admin.legacy-monitor.index')
            ->set('filterMethod', 'POST')
            ->assertStatus(200)
            ->assertSee('app/form.php')
            ->assertDontSee('app/users.php');
    }
}
