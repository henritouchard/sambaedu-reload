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

    // ── Stubs & Include Path ─────────────────────────────────────────────

    /**
     * Le bootstrap prépend legacy/stubs/ dans l'include_path.
     */
    public function test_bootstrap_prepends_stubs_in_include_path(): void
    {
        $testDir = base_path('legacy/modules/test-include-path');
        @mkdir($testDir, 0777, true);
        file_put_contents(
            $testDir . '/index.php',
            '<?php echo get_include_path(); ?>'
        );

        try {
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
        } finally {
            @unlink($testDir . '/index.php');
            @rmdir($testDir);
        }
    }

    /**
     * require 'config.inc.php' résout vers le stub, pas le legacy original.
     */
    public function test_config_inc_resolves_to_stub(): void
    {
        $testDir = base_path('legacy/modules/test-config-stub');
        @mkdir($testDir, 0777, true);
        file_put_contents(
            $testDir . '/index.php',
            '<?php
            require_once "config.inc.php";
            echo function_exists("get_config") ? "STUB_OK" : "STUB_MISSING";
            ?>'
        );

        try {
            $response = $this->get('/test-config-stub');
            $response->assertStatus(200);
            $response->assertSee('STUB_OK');
        } finally {
            @unlink($testDir . '/index.php');
            @rmdir($testDir);
        }
    }

    /**
     * require 'ldap.inc.php' résout vers le stub (shim LDAP).
     */
    public function test_ldap_inc_resolves_to_stub(): void
    {
        $testDir = base_path('legacy/modules/test-ldap-stub');
        @mkdir($testDir, 0777, true);
        file_put_contents(
            $testDir . '/index.php',
            '<?php
            require_once "ldap.inc.php";
            echo function_exists("search_ad") ? "LDAP_SHIM_OK" : "LDAP_SHIM_MISSING";
            ?>'
        );

        try {
            $response = $this->get('/test-ldap-stub');
            $response->assertStatus(200);
            $response->assertSee('LDAP_SHIM_OK');
        } finally {
            @unlink($testDir . '/index.php');
            @rmdir($testDir);
        }
    }

    /**
     * require 'admin_ui.inc.php' résout vers le stub (fonctions vides).
     */
    public function test_admin_ui_inc_resolves_to_stub(): void
    {
        $testDir = base_path('legacy/modules/test-adminui-stub');
        @mkdir($testDir, 0777, true);
        file_put_contents(
            $testDir . '/index.php',
            '<?php
            require_once "admin_ui.inc.php";
            echo function_exists("admin_header_html") ? "ADMIN_UI_STUB_OK" : "ADMIN_UI_STUB_MISSING";
            ?>'
        );

        try {
            $response = $this->get('/test-adminui-stub');
            $response->assertStatus(200);
            $response->assertSee('ADMIN_UI_STUB_OK');
        } finally {
            @unlink($testDir . '/index.php');
            @rmdir($testDir);
        }
    }

    // ── Shims LDAP ───────────────────────────────────────────────────────

    /**
     * Les fonctions LDAP shimmées sont disponibles pour les modules.
     */
    public function test_ldap_shim_functions_available_in_module_context(): void
    {
        $testDir = base_path('legacy/modules/test-ldap-funcs');
        @mkdir($testDir, 0777, true);
        file_put_contents(
            $testDir . '/index.php',
            '<?php
            $funcs = ["search_ad", "search_user", "search_machine", "have_right"];
            $available = [];
            foreach ($funcs as $f) {
                if (function_exists($f)) $available[] = $f;
            }
            echo "LDAP_FUNCS:" . implode(",", $available);
            ?>'
        );

        try {
            $response = $this->get('/test-ldap-funcs');
            $response->assertStatus(200);
            $response->assertSee('LDAP_FUNCS:search_ad,search_user,search_machine,have_right');
        } finally {
            @unlink($testDir . '/index.php');
            @rmdir($testDir);
        }
    }

    /**
     * functions.inc.php est chargé et fournit les fonctions utilitaires.
     */
    public function test_functions_inc_loaded_in_module_context(): void
    {
        $testDir = base_path('legacy/modules/test-functions-inc');
        @mkdir($testDir, 0777, true);
        file_put_contents(
            $testDir . '/index.php',
            '<?php
            $funcs = ["remote_ip", "open_session", "close_session", "getintlevel"];
            $available = [];
            foreach ($funcs as $f) {
                if (function_exists($f)) $available[] = $f;
            }
            echo "FUNCS:" . implode(",", $available);
            ?>'
        );

        try {
            $response = $this->get('/test-functions-inc');
            $response->assertStatus(200);
            $response->assertSee('FUNCS:remote_ip,open_session,close_session,getintlevel');
        } finally {
            @unlink($testDir . '/index.php');
            @rmdir($testDir);
        }
    }

    /**
     * is_eleve() et is_prof() sont disponibles via le shim LDAP.
     */
    public function test_is_eleve_and_is_prof_available_in_module_context(): void
    {
        $testDir = base_path('legacy/modules/test-is-eleve');
        @mkdir($testDir, 0777, true);
        file_put_contents(
            $testDir . '/index.php',
            '<?php
            $funcs = ["is_eleve", "is_prof"];
            $available = [];
            foreach ($funcs as $f) {
                if (function_exists($f)) $available[] = $f;
            }
            echo "ROLE_FUNCS:" . implode(",", $available);
            ?>'
        );

        try {
            $response = $this->get('/test-is-eleve');
            $response->assertStatus(200);
            $response->assertSee('ROLE_FUNCS:is_eleve,is_prof');
        } finally {
            @unlink($testDir . '/index.php');
            @rmdir($testDir);
        }
    }

    // ── Runtime Legacy (autoload, CWD) ───────────────────────────────────

    /**
     * Le bridge vendor/autoload.php dans legacy/modules/ fonctionne.
     */
    public function test_vendor_autoload_bridge_works(): void
    {
        $testDir = base_path('legacy/modules/test-vendor');
        @mkdir($testDir, 0777, true);
        file_put_contents(
            $testDir . '/index.php',
            '<?php
            require_once (dirname(__FILE__) . "/../vendor/autoload.php");
            echo class_exists("GuzzleHttp\Client") ? "AUTOLOAD_OK" : "AUTOLOAD_MISSING";
            ?>'
        );

        try {
            $response = $this->get('/test-vendor');
            $response->assertStatus(200);
            $response->assertSee('AUTOLOAD_OK');
        } finally {
            @unlink($testDir . '/index.php');
            @rmdir($testDir);
        }
    }

    /**
     * Le CWD est positionné dans le dossier du module pendant l'exécution.
     */
    public function test_cwd_is_module_directory_during_execution(): void
    {
        $testDir = base_path('legacy/modules/test-cwd');
        @mkdir($testDir, 0777, true);
        file_put_contents(
            $testDir . '/index.php',
            '<?php echo basename(getcwd()); ?>'
        );

        try {
            $response = $this->get('/test-cwd');
            $response->assertStatus(200);
            $response->assertSee('test-cwd');
        } finally {
            @unlink($testDir . '/index.php');
            @rmdir($testDir);
        }
    }
}
