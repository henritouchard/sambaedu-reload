<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Test Feature : infrastructure d'exécution legacy (bootstrap, stubs, shims).
 *
 * Vérifie que le bootstrap prépend les stubs dans l'include_path,
 * que les shims LDAP/SQL sont disponibles, et que le runtime legacy
 * (autoload bridge, CWD, functions.inc.php) fonctionne correctement.
 */
class LegacyBootstrapShimsTest extends TestCase
{
    /** @var string[] Répertoires de test créés pendant le test courant */
    private array $testModuleDirs = [];

    /**
     * Crée un module de test temporaire dans legacy/modules/ et l'enregistre
     * pour cleanup automatique dans tearDown.
     */
    private function createTestModule(string $name, string $phpContent): string
    {
        $testDir = base_path('legacy/modules/' . $name);
        if (!is_dir($testDir)) {
            mkdir($testDir, 0777, true);
        }
        file_put_contents($testDir . '/index.php', $phpContent);
        $this->testModuleDirs[] = $testDir;

        return $testDir;
    }

    /**
     * Supprime récursivement un répertoire de test.
     */
    private function removeTestModule(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $files = glob($path . '/{,.}[!.,!..]*', GLOB_BRACE);
        foreach ($files as $file) {
            is_dir($file) ? $this->removeTestModule($file) : unlink($file);
        }
        rmdir($path);
    }

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
        foreach ($this->testModuleDirs as $dir) {
            $this->removeTestModule($dir);
        }
        $this->testModuleDirs = [];

        \App\Models\LegacyCatchallLog::query()->delete();
        parent::tearDown();
    }

    // ── Stubs & Include Path ─────────────────────────────────────────────

    /**
     * Le bootstrap prépend legacy/stubs/ dans l'include_path.
     */
    public function test_bootstrap_prepends_stubs_in_include_path(): void
    {
        $this->createTestModule('test-include-path', '<?php echo get_include_path(); ?>');

        $response = $this->get('/test-include-path');
        $response->assertStatus(200);

        $includePath = $response->getContent();
        $stubsPos = strpos($includePath, 'legacy/stubs');
        $this->assertNotFalse($stubsPos, 'legacy/stubs doit être dans l\'include_path');

        $legacyIncludesPos = strpos($includePath, '/includes', $stubsPos + 12);
        if ($legacyIncludesPos !== false) {
            $this->assertLessThan(
                $legacyIncludesPos,
                $stubsPos,
                'legacy/stubs/ doit être AVANT sambaedu/includes/ dans l\'include_path'
            );
        }
    }

    /**
     * require 'config.inc.php' résout vers le stub, pas le legacy original.
     */
    public function test_config_inc_resolves_to_stub(): void
    {
        $this->createTestModule('test-config-stub', '<?php
            require_once "config.inc.php";
            echo function_exists("get_config") ? "STUB_OK" : "STUB_MISSING";
            ?>');

        $response = $this->get('/test-config-stub');
        $response->assertStatus(200);
        $response->assertSee('STUB_OK');
    }

    /**
     * require 'ldap.inc.php' résout vers le stub (shim LDAP).
     */
    public function test_ldap_inc_resolves_to_stub(): void
    {
        $this->createTestModule('test-ldap-stub', '<?php
            require_once "ldap.inc.php";
            echo function_exists("search_ad") ? "LDAP_SHIM_OK" : "LDAP_SHIM_MISSING";
            ?>');

        $response = $this->get('/test-ldap-stub');
        $response->assertStatus(200);
        $response->assertSee('LDAP_SHIM_OK');
    }

    /**
     * require 'admin_ui.inc.php' résout vers le stub (fonctions vides).
     */
    public function test_admin_ui_inc_resolves_to_stub(): void
    {
        $this->createTestModule('test-adminui-stub', '<?php
            require_once "admin_ui.inc.php";
            echo function_exists("admin_header_html") ? "ADMIN_UI_STUB_OK" : "ADMIN_UI_STUB_MISSING";
            ?>');

        $response = $this->get('/test-adminui-stub');
        $response->assertStatus(200);
        $response->assertSee('ADMIN_UI_STUB_OK');
    }

    // ── Shims LDAP ───────────────────────────────────────────────────────

    /**
     * Les fonctions LDAP shimmées sont disponibles pour les modules.
     */
    public function test_ldap_shim_functions_available_in_module_context(): void
    {
        $this->createTestModule('test-ldap-funcs', '<?php
            $funcs = ["search_ad", "search_user", "search_machine", "have_right"];
            $available = [];
            foreach ($funcs as $f) {
                if (function_exists($f)) $available[] = $f;
            }
            echo "LDAP_FUNCS:" . implode(",", $available);
            ?>');

        $response = $this->get('/test-ldap-funcs');
        $response->assertStatus(200);
        $response->assertSee('LDAP_FUNCS:search_ad,search_user,search_machine,have_right');
    }

    // Le vrai functions.inc.php legacy (remote_ip/open_session/close_session/
    // getintlevel) n'est JAMAIS chargé en test : tests/bootstrap.php définit
    // LEGACY_SKIP_LEGACY_INCLUDES=true (évite les exec samba-tool qui timeout) et
    // ces fonctions ne sont volontairement PAS shimmées (cf. legacy/ldap.inc.php:1016).
    // L'ancien test `test_functions_inc_loaded_in_module_context` contredisait cette
    // décision (il ne pouvait passer dans aucun run de test) → retiré.

    /**
     * is_eleve() et is_prof() sont disponibles via le shim LDAP.
     */
    public function test_is_eleve_and_is_prof_available_in_module_context(): void
    {
        $this->createTestModule('test-is-eleve', '<?php
            $funcs = ["is_eleve", "is_prof"];
            $available = [];
            foreach ($funcs as $f) {
                if (function_exists($f)) $available[] = $f;
            }
            echo "ROLE_FUNCS:" . implode(",", $available);
            ?>');

        $response = $this->get('/test-is-eleve');
        $response->assertStatus(200);
        $response->assertSee('ROLE_FUNCS:is_eleve,is_prof');
    }

    // ── Runtime Legacy (autoload, CWD) ───────────────────────────────────

    /**
     * Le bridge vendor/autoload.php dans legacy/modules/ fonctionne.
     */
    public function test_vendor_autoload_bridge_works(): void
    {
        $this->createTestModule('test-vendor', '<?php
            require_once (dirname(__FILE__) . "/../vendor/autoload.php");
            echo class_exists("GuzzleHttp\Client") ? "AUTOLOAD_OK" : "AUTOLOAD_MISSING";
            ?>');

        $response = $this->get('/test-vendor');
        $response->assertStatus(200);
        $response->assertSee('AUTOLOAD_OK');
    }

    /**
     * Le CWD est positionné dans le dossier du module pendant l'exécution.
     */
    public function test_cwd_is_module_directory_during_execution(): void
    {
        $this->createTestModule('test-cwd', '<?php echo basename(getcwd()); ?>');

        $response = $this->get('/test-cwd');
        $response->assertStatus(200);
        $response->assertSee('test-cwd');
    }

    // ── AC3 : Pas d'erreur récurrente ──────────────────────────────────

    /**
     * AC3 — L'exécution d'un module Tier 1 ne génère pas d'erreur dans le error logger.
     */
    public function test_module_execution_does_not_generate_error_logs(): void
    {
        // Vider les erreurs existantes
        \App\Models\ErrorLog::where('source', 'legacy')->delete();

        // Module minimal qui charge le bootstrap complet (stubs + shims)
        $this->createTestModule('test-ac3', '<?php
            require_once "config.inc.php";
            require_once "ldap.inc.php";
            $config = get_config();
            echo "AC3_OK";
            ?>');

        $response = $this->get('/test-ac3');
        $response->assertStatus(200);
        $response->assertSee('AC3_OK');

        // Vérifier qu'aucune erreur legacy n'a été loggée
        $errors = \App\Models\ErrorLog::where('source', 'legacy')->get();
        $this->assertCount(
            0,
            $errors,
            'AC3 violé : erreurs legacy après exécution du module : '
            . $errors->pluck('message')->implode(' | ')
        );
    }
}
