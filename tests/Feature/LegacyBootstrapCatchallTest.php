<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Test Feature : le catchall charge le bootstrap pour un module dans legacy/modules/.
 *
 * AC6 — Given une requête arrive sur une route non Livewire,
 *       when le path correspond à un module dans legacy/modules/,
 *       then le LegacyCatchallController charge legacy/bootstrap.php
 *       avant d'exécuter le module.
 */
class LegacyBootstrapCatchallTest extends TestCase
{
    private string $legacyTmpDir;
    private string $modulesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Créer un répertoire legacy temporaire pour le proxy (routes non-modules)
        $this->legacyTmpDir = sys_get_temp_dir() . '/sambaedu_legacy_test_' . uniqid();
        mkdir($this->legacyTmpDir, 0777, true);
        Config::set('sambaedu.legacy_path', $this->legacyTmpDir);
        Config::set('sambaedu.legacy_base_url', 'http://127.0.0.1:80');
        Config::set('sambaedu.block_migrated_routes', false);

        // Créer le dossier legacy/modules/ avec un module de test
        $this->modulesDir = base_path('legacy/modules');
        if (!is_dir($this->modulesDir . '/test-module')) {
            mkdir($this->modulesDir . '/test-module', 0777, true);
        }

        // Créer la table de log en mémoire
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
    }

    protected function tearDown(): void
    {
        // Nettoyer le module de test
        $testFile = $this->modulesDir . '/test-module/index.php';
        if (file_exists($testFile)) {
            unlink($testFile);
        }

        $testPhp = $this->modulesDir . '/test-module/hello.php';
        if (file_exists($testPhp)) {
            unlink($testPhp);
        }

        // Nettoyer le dossier temporaire legacy
        $this->removeDirectory($this->legacyTmpDir);

        \App\Models\LegacyCatchallLog::query()->delete();
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * AC6 — Un module dans legacy/modules/ est exécuté via le bootstrap.
     */
    public function test_module_in_legacy_modules_is_executed_via_bootstrap(): void
    {
        // Créer un fichier PHP de test dans legacy/modules/
        file_put_contents(
            $this->modulesDir . '/test-module/index.php',
            '<?php echo "LEGACY_MODULE_OK"; ?>'
        );

        $response = $this->get('/test-module');

        $response->assertStatus(200);
        $response->assertSee('LEGACY_MODULE_OK');

        // Vérifier le log d'accès
        $this->assertDatabaseHas('legacy_catchall_logs', [
            'method' => 'GET',
            'path' => 'test-module',
        ]);
    }

    /**
     * AC6 — Un fichier PHP direct dans legacy/modules/ est exécuté.
     */
    public function test_php_file_in_legacy_modules_is_executed(): void
    {
        file_put_contents(
            $this->modulesDir . '/test-module/hello.php',
            '<?php echo "HELLO_FROM_MODULE"; ?>'
        );

        $response = $this->get('/test-module/hello.php');

        $response->assertStatus(200);
        $response->assertSee('HELLO_FROM_MODULE');
    }

    /**
     * AC6 — Le bootstrap rend app() disponible dans le module.
     */
    public function test_bootstrap_provides_laravel_context_to_module(): void
    {
        file_put_contents(
            $this->modulesDir . '/test-module/index.php',
            '<?php echo function_exists("app") ? "APP_OK" : "APP_MISSING"; ?>'
        );

        $response = $this->get('/test-module');

        $response->assertStatus(200);
        $response->assertSee('APP_OK');
    }
}
