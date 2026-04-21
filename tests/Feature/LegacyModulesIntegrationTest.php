<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Test Feature : présence et accessibilité des modules legacy dans legacy/modules/.
 *
 * Vérifie que les modules copiés depuis sambaedu/ existent,
 * que leurs assets sont présents, et qu'ils sont accessibles via le catchall.
 */
class LegacyModulesIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Config::set('sambaedu.block_migrated_routes', false);

        $stubsPath = base_path('legacy/stubs');
        $legacyIncludesPath = config('sambaedu.legacy_path', '/var/www/sambaedu') . '/includes';
        $currentPath = get_include_path();
        if (is_dir($stubsPath) && !str_contains($currentPath, $stubsPath)) {
            $currentPath = $stubsPath . PATH_SEPARATOR . $currentPath;
        }
        if (is_dir($legacyIncludesPath) && !str_contains($currentPath, $legacyIncludesPath)) {
            $currentPath .= PATH_SEPARATOR . $legacyIncludesPath;
        }
        set_include_path($currentPath);

        if (is_dir($legacyIncludesPath) && !function_exists('remote_ip')) {
            $functionsPath = $legacyIncludesPath . '/functions.inc.php';
            if (file_exists($functionsPath)) {
                require_once $functionsPath;
            }
        }

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
        if (!Schema::hasTable('error_logs')) {
            Schema::create('error_logs', function (Blueprint $table) {
                $table->id();
                $table->string('source');
                $table->text('message');
                $table->timestamp('created_at');
            });
        }
    }

    protected function tearDown(): void
    {
        \App\Models\LegacyCatchallLog::query()->delete();
        parent::tearDown();
    }

    /**
     * Les modules legacy existent dans legacy/modules/.
     */
    public function test_legacy_modules_exist(): void
    {
        $modulesBase = base_path('legacy/modules');

        // Modules effectivement copiés dans legacy/modules/ (bootstrap direct).
        // Les autres modules legacy (api/, user/, …) restent servis par proxy
        // HTTP vers le vhost legacy — cf. test_api_ecowatt_module_accessible_via_catchall.
        $expectedModules = [
            'display/index.php',
            'display/screen.php',
            'display/config.php',
            'dossier_echange/dossier_echange.php',
        ];

        foreach ($expectedModules as $module) {
            $this->assertFileExists(
                $modulesBase . '/' . $module,
                "Module legacy manquant : {$module}"
            );
        }
    }

    /**
     * Les assets statiques du module display sont présents.
     */
    public function test_display_module_assets_are_present(): void
    {
        $displayDir = base_path('legacy/modules/display');

        $this->assertDirectoryExists($displayDir . '/css', 'display/css manquant');
        $this->assertDirectoryExists($displayDir . '/js', 'display/js manquant');
        $this->assertDirectoryExists($displayDir . '/IMG', 'display/IMG manquant');
        $this->assertFileExists($displayDir . '/css/reveal.css');
        $this->assertFileExists($displayDir . '/js/reveal.js');
    }

    /**
     * Le module api/ecowatt.php est accessible via catchall (pas de 404).
     */
    public function test_api_ecowatt_module_accessible_via_catchall(): void
    {
        $response = $this->get('/api/ecowatt.php');
        $this->assertNotEquals(404, $response->status(), 'Le module api/ecowatt.php ne doit pas retourner 404');
    }
}
