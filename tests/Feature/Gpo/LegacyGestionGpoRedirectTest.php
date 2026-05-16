<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature — Catchall override `gpo/gestion_gpo.php` → `/admin/settings/gpo` (AC3.1, AC5.3, maj Story 16.9 D7).
 *
 * Vérifie :
 * - AC3.1 : GET `gpo/gestion_gpo.php` → 302 vers `/admin/settings/gpo` (renommé par 16.9).
 * - AC3.2 : Les pages en cohabitation (`gpo/wine.php`, `gpo/gpo-maj.php`, etc.)
 *   ne sont PAS bloquées (restent accessibles par le shim legacy).
 * - Décision D5 : bloquer uniquement la page d'index, pas les pages d'édition.
 *
 * Pattern hérité de LegacyCatchallTest : Config::set() pour paramétrer le
 * comportement du LegacyCatchallController, Http::fake() pour simuler le proxy.
 */
class LegacyGestionGpoRedirectTest extends TestCase
{
    private string $legacyTmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Désactivé : portage natif Laravel des modules legacy GPO en cours
        // (Epic 16/17). Les redirections testées ici (gestion_gpo.php,
        // gpo-maj.php, ...) vont disparaître avec le portage natif.
        // @todo Supprimer ce test lors de story 16.13 (retrait des shims GPO).
        $this->markTestSkipped('Désactivé pendant le portage natif Laravel des modules legacy GPO (Epic 16/17).');

        $this->legacyTmpDir = sys_get_temp_dir() . '/sambaedu_gpo_test_' . uniqid();
        mkdir($this->legacyTmpDir, 0777, true);

        Config::set('sambaedu.legacy_path', $this->legacyTmpDir);
        Config::set('sambaedu.legacy_base_url', 'http://127.0.0.1:80');
        Config::set('sambaedu.block_migrated_routes', true);
        Config::set('sambaedu.allowed_legacy_routes', []);
        Config::set('sambaedu.blocked_legacy_routes', [
            '^gpo/gestion_gpo\.php$' => 'admin/settings/gpo',
        ]);

        // Créer la table log si absente
        if (!Schema::hasTable('legacy_catchall_logs')) {
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

        // Créer les fichiers legacy pour les tests de cohabitation
        $gpoDir = $this->legacyTmpDir . '/gpo';
        mkdir($gpoDir, 0777, true);
        file_put_contents($gpoDir . '/gestion_gpo.php', '<?php echo "legacy gestion_gpo"; ?>');
        file_put_contents($gpoDir . '/wine.php', '<?php echo "legacy wine"; ?>');
        file_put_contents($gpoDir . '/gpo-maj.php', '<?php echo "legacy gpo-maj"; ?>');
    }

    protected function tearDown(): void
    {
        // setUp() peut s'arrêter avant l'init de $legacyTmpDir (cas markTestSkipped) —
        // tearDown reste appelé par PHPUnit, on protège contre l'accès non-init.
        if (isset($this->legacyTmpDir)) {
            $this->removeDirectory($this->legacyTmpDir);
        }
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // =========================================================================
    // AC3.1 — Blocage de la route d'index legacy (AC5.3)
    // =========================================================================

    #[Test]
    public function it_redirects_gestion_gpo_php_to_admin_settings_gpo(): void
    {
        $response = $this->get('/gpo/gestion_gpo.php');

        // Doit retourner une redirection (301 ou 302) vers /admin/settings/gpo (16.9).
        $this->assertContains($response->getStatusCode(), [301, 302],
            'GET /gpo/gestion_gpo.php doit être redirigé vers /admin/settings/gpo');

        $location = $response->headers->get('Location', '');
        $this->assertStringContainsString('admin/settings/gpo', $location,
            "L'en-tête Location doit pointer vers admin/settings/gpo, obtenu : {$location}");
    }

    #[Test]
    public function it_does_not_redirect_legacy_section_pages(): void
    {
        // wine.php doit passer en mode cohabitation (proxy legacy ou 404/500 OK,
        // mais PAS une redirection vers /admin/settings/gpo, 16.9 D7).
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response('legacy wine content', 200, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->get('/gpo/wine.php');

        $statusCode = $response->getStatusCode();
        $location = $response->headers->get('Location', '');

        // Le code ne doit PAS être une redirection vers /admin/settings/gpo (16.9).
        $isRedirectToAdminGpo = in_array($statusCode, [301, 302])
            && str_contains($location, 'admin/settings/gpo');

        $this->assertFalse($isRedirectToAdminGpo,
            "gpo/wine.php ne doit PAS être redirigé vers /admin/settings/gpo (cohabitation D5). "
            . "Status: {$statusCode}, Location: {$location}");
    }

    #[Test]
    public function it_does_not_redirect_gpo_maj_php(): void
    {
        // gpo-maj.php (page d'édition) doit rester accessible (cohabitation)
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response('legacy gpo-maj content', 200, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->get('/gpo/gpo-maj.php');

        $statusCode = $response->getStatusCode();
        $location = $response->headers->get('Location', '');

        $isRedirectToAdminGpo = in_array($statusCode, [301, 302])
            && str_contains($location, 'admin/settings/gpo');

        $this->assertFalse($isRedirectToAdminGpo,
            "gpo/gpo-maj.php ne doit PAS être redirigé vers /admin/settings/gpo (cohabitation D5).");
    }
}
