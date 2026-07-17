<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests du LegacyCatchallController.
 *
 * Les requêtes PHP legacy sont mockées via Http::fake() (proxy vers vhost legacy).
 * Les fichiers legacy sont créés dans sys_get_temp_dir() pour vérifier la résolution de path.
 */
class LegacyCatchallTest extends TestCase
{
    private string $legacyTmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Créer un répertoire legacy temporaire pour les tests
        $this->legacyTmpDir = sys_get_temp_dir() . '/sambaedu_legacy_test_' . uniqid();
        mkdir($this->legacyTmpDir, 0777, true);

        // Pointer la config vers ce répertoire
        Config::set('sambaedu.legacy_path', $this->legacyTmpDir);
        Config::set('sambaedu.legacy_base_url', 'http://127.0.0.1:80');
        Config::set('sambaedu.block_migrated_routes', true);
        Config::set('sambaedu.blocked_legacy_routes', []);
        Config::set('sambaedu.allowed_legacy_routes', []);

        // Créer la table de log en mémoire
        if (! Schema::hasTable('legacy_catchall_logs')) {
            Schema::create('legacy_catchall_logs', function (Blueprint $table) {
                $table->id();
                // Story 38.2 — colonnes additives (observabilité tombstones).
                $table->string('source', 16)->default('catchall')->index();
                $table->string('method', 10);
                $table->string('path', 2048);
                $table->string('ip', 45);
                $table->string('machine', 255)->nullable();
                $table->string('user_login', 255)->nullable();
                $table->text('query_string')->nullable();
                $table->text('referer')->nullable();
                $table->timestamp('created_at');
            });
        }
    }

    protected function tearDown(): void
    {
        // Nettoyer les fichiers temporaires
        $this->removeDirectory($this->legacyTmpDir);

        \App\Models\LegacyCatchallLog::query()->delete();
        parent::tearDown();
    }

    /**
     * AC1 — Route legacy existante → contenu servi via proxy + log en DB
     */
    public function test_legacy_php_route_is_served_and_logged(): void
    {
        // Créer le fichier PHP pour que la résolution de path le trouve
        file_put_contents($this->legacyTmpDir . '/test-page.php', '<?php echo "Hello"; ?>');

        // Mock du proxy HTTP vers le vhost legacy
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response('Hello from legacy', 200, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->get('/test-page.php');

        $response->assertStatus(200);
        $response->assertSee('Hello from legacy');

        $this->assertDatabaseHas('legacy_catchall_logs', [
            'method' => 'GET',
            'path'   => 'test-page.php',
        ]);
    }

    /**
     * AC2 — Route bloquée + LEGACY_BLOCK_MIGRATED_ROUTES=true → redirect vers SER + pas de log
     */
    public function test_blocked_route_redirects_to_ser_and_does_not_log(): void
    {
        Config::set('sambaedu.blocked_legacy_routes', [
            '^old/page\.php$' => '/app/new-page',
        ]);

        // Créer le fichier legacy (il existe mais doit être bloqué)
        mkdir($this->legacyTmpDir . '/old', 0777, true);
        file_put_contents($this->legacyTmpDir . '/old/page.php', '<?php echo "old page"; ?>');

        $response = $this->get('/old/page.php');

        $response->assertRedirect('/app/new-page');

        $this->assertDatabaseCount('legacy_catchall_logs', 0);
    }

    /**
     * Convention `noop:` — endpoint script poste bloqué → 200 + commentaire
     * cmd (jamais un 302 : son corps HTML, CALLé par cmd, avorte le batch
     * logon entier).
     */
    public function test_blocked_script_route_with_noop_returns_comment_not_redirect(): void
    {
        // Path SYNTHÉTIQUE (story 38.2) : `gpo/shortcuts_out.php` matche désormais
        // le tombstone natif AVANT le catchall — on exerce la convention `noop:`
        // générique sur un path fictif qui atteint bien le catchall.
        Config::set('sambaedu.blocked_legacy_routes', [
            'gpo/fake_noop_route\.php' => 'noop:route legacy neutralisee (test convention noop)',
        ]);

        $response = $this->post('/gpo/fake_noop_route.php', ['os' => 'windows', 'action' => 'logon']);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringStartsWith('REM ', $response->getContent());
    }

    /**
     * AC7 (review 38.1 #4) — la convention `noop:` est indifférente à l'état
     * du FS legacy : même réponse inerte avec `legacy_path` absent (null).
     */
    public function test_blocked_script_route_noop_still_works_with_missing_legacy_path(): void
    {
        Config::set('sambaedu.legacy_path', null);
        Config::set('sambaedu.blocked_legacy_routes', [
            'gpo/fake_noop_route\.php' => 'noop:route legacy neutralisee (test convention noop)',
        ]);

        $response = $this->post('/gpo/fake_noop_route.php', ['os' => 'windows', 'action' => 'logon']);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringStartsWith('REM ', $response->getContent());
    }

    /**
     * Convention `noop:` — variante linux : commentaire bash `#`.
     */
    public function test_blocked_script_route_noop_uses_bash_comment_for_linux(): void
    {
        Config::set('sambaedu.blocked_legacy_routes', [
            'gpo/fake_noop_route\.php' => 'noop:route legacy neutralisee (test convention noop)',
        ]);

        $response = $this->post('/gpo/fake_noop_route.php', ['os' => 'linux', 'action' => 'logon']);

        $response->assertStatus(200);
        $this->assertStringStartsWith('# ', $response->getContent());
    }

    /**
     * AC3 — Route bloquée + LEGACY_BLOCK_MIGRATED_ROUTES=false → contenu legacy servi via proxy
     */
    public function test_blocked_route_with_blocking_disabled_serves_legacy(): void
    {
        Config::set('sambaedu.block_migrated_routes', false);
        Config::set('sambaedu.blocked_legacy_routes', [
            '^old/page\.php$' => '/app/new-page',
        ]);

        mkdir($this->legacyTmpDir . '/old', 0777, true);
        file_put_contents($this->legacyTmpDir . '/old/page.php', '<?php echo "legacy content"; ?>');

        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response('legacy content', 200),
        ]);

        $response = $this->get('/old/page.php');

        $response->assertStatus(200);
        $response->assertSee('legacy content');
    }

    /**
     * Story 38.1 (D4) — LEGACY_PATH invalide (dossier inexistant) → 404 loggé,
     * plus jamais 500. Le monitoring d'extinction (legacy_catchall_logs) reste
     * fonctionnel sans le FS legacy.
     */
    public function test_invalid_legacy_path_returns_404_and_is_logged(): void
    {
        Config::set('sambaedu.legacy_path', '/this/path/does/not/exist');

        $response = $this->get('/some/route.php');

        $response->assertStatus(404);

        $this->assertDatabaseHas('legacy_catchall_logs', [
            'method' => 'GET',
            'path'   => 'some/route.php',
        ]);
    }

    /**
     * Story 38.1 (D4) — LEGACY_PATH absent (null) → 404 loggé, plus jamais 500.
     */
    public function test_missing_legacy_path_returns_404_and_is_logged(): void
    {
        Config::set('sambaedu.legacy_path', null);

        $response = $this->get('/some/route.php');

        $response->assertStatus(404);

        $this->assertDatabaseHas('legacy_catchall_logs', [
            'method' => 'GET',
            'path'   => 'some/route.php',
        ]);
    }

    /**
     * Story 38.1 (D4) — LEGACY_PATH absent + log_404=false → 404 sans ligne DB
     * (le drapeau LEGACY_LOG_404 gouverne aussi ce chemin dégradé).
     */
    public function test_missing_legacy_path_with_log_404_disabled_returns_404_without_log(): void
    {
        Config::set('sambaedu.legacy_path', null);
        Config::set('sambaedu.log_404', false);

        $response = $this->get('/some/route.php');

        $response->assertStatus(404);

        $this->assertDatabaseCount('legacy_catchall_logs', 0);
    }

    /**
     * Story 38.1 (D4) — les early-returns du catchall (ici la redirection native
     * gpo/no_roam.php → page admin, story 1bis.18f) précèdent la résolution FS et
     * restent fonctionnels même avec legacy_path absent.
     */
    public function test_early_return_redirect_still_works_with_missing_legacy_path(): void
    {
        Config::set('sambaedu.legacy_path', null);

        $response = $this->get('/gpo/no_roam.php');

        $response->assertRedirect('/admin/settings/files?tab=roaming');
    }

    /**
     * Route inexistante → 404 + log en DB (log_404=true par défaut)
     */
    public function test_nonexistent_legacy_route_returns_404_and_is_logged(): void
    {
        $response = $this->get('/this-does-not-exist-anywhere.php');

        $response->assertStatus(404);

        $this->assertDatabaseHas('legacy_catchall_logs', [
            'method' => 'GET',
            'path'   => 'this-does-not-exist-anywhere.php',
        ]);
    }

    /**
     * Route inexistante + log_404=false → 404 sans log
     */
    public function test_nonexistent_legacy_route_with_log_404_disabled_is_not_logged(): void
    {
        Config::set('sambaedu.log_404', false);

        $response = $this->get('/this-does-not-exist-anywhere.php');

        $response->assertStatus(404);

        $this->assertDatabaseCount('legacy_catchall_logs', 0);
    }

    /**
     * Route explicitement autorisée prend la priorité sur le blocage → proxy vers legacy
     */
    public function test_allowed_route_overrides_blocking(): void
    {
        Config::set('sambaedu.blocked_legacy_routes', [
            '^legacy-section/' => '/app/new-section',
        ]);
        Config::set('sambaedu.allowed_legacy_routes', [
            '^legacy-section/exception\.php$',
        ]);

        mkdir($this->legacyTmpDir . '/legacy-section', 0777, true);
        file_put_contents($this->legacyTmpDir . '/legacy-section/exception.php', '<?php echo "exception allowed"; ?>');

        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response('exception allowed', 200),
        ]);

        $response = $this->get('/legacy-section/exception.php');

        $response->assertStatus(200);
        $response->assertSee('exception allowed');
    }

    /**
     * Dossier sensible → 403
     */
    public function test_forbidden_directory_returns_403(): void
    {
        $response = $this->get('/vendor/some/file.php');

        $response->assertStatus(403);
    }

    /**
     * Supprime récursivement un répertoire temporaire.
     */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
