<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Test Feature : intégration des modules legacy Tier 1 via le catchall.
 *
 * Story 1bis.4 — Vérifie que les modules Tier 1 copiés dans legacy/modules/
 * sont accessibles, que les stubs sont prépendus dans l'include path,
 * et que le bootstrap + shims fournissent le contexte nécessaire.
 */
class LegacyModulesTier1Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // NE PAS overrider legacy_path — le bootstrap en a besoin pour
        // construire l'include_path et charger functions.inc.php.
        // Les modules Tier 1 sont dans legacy/modules/ → exécutés via
        // executeViaBootstrap(), pas via proxy HTTP.
        Config::set('sambaedu.block_migrated_routes', false);

        // S'assurer que l'include_path contient les stubs et le legacy includes,
        // même si le bootstrap a déjà été chargé par un autre test avec un
        // legacy_path temporaire.
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

        // Charger functions.inc.php si pas encore fait (le bootstrap peut
        // avoir été chargé par un autre test sans le bon include path)
        if (is_dir($legacyIncludesPath) && !function_exists('remote_ip')) {
            $functionsPath = $legacyIncludesPath . '/functions.inc.php';
            if (file_exists($functionsPath)) {
                require_once $functionsPath;
            }
        }

        // Créer les tables de log en mémoire si absentes
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

    // ── AC4 : Stubs chargés avant les includes originaux ──────────────

    /**
     * AC4 — Le bootstrap prépend legacy/stubs/ dans l'include_path.
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

            // Vérifier que stubs est AVANT le includes legacy dans l'include_path
            // Rechercher spécifiquement le dossier legacy includes (pas 'stubs' qui contient aussi 'includes')
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
     * AC4 — require 'config.inc.php' résout vers le stub, pas le legacy original.
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
     * AC4 — require 'ldap.inc.php' résout vers le stub (shim LDAP).
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
     * AC4 — require 'admin_ui.inc.php' résout vers le stub (fonctions vides).
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

    // ── AC1 : Modules Tier 1 copiés et accessibles ────────────────────

    /**
     * AC1 — Les modules Tier 1 existent dans legacy/modules/.
     */
    public function test_tier1_modules_exist_in_legacy_modules(): void
    {
        $modulesBase = base_path('legacy/modules');

        $expectedModules = [
            'display/index.php',
            'display/screen.php',
            'display/config.php',
            'oauth2/login.php',
            'oauth2/callback.php',
            'sso/cas.php',
            'sso/oauth2.php',
            'sso/openid.php',
            'cas/cas.php',
            'cas/ent.php',
            'api/ecowatt.php',
            'user/index.php',
            'dossier_echange/dossier_echange.php',
        ];

        foreach ($expectedModules as $module) {
            $this->assertFileExists(
                $modulesBase . '/' . $module,
                "Module Tier 1 manquant : {$module}"
            );
        }
    }

    /**
     * AC1 — Les assets statiques du module display sont copiés.
     */
    public function test_display_module_assets_are_copied(): void
    {
        $displayDir = base_path('legacy/modules/display');

        $this->assertDirectoryExists($displayDir . '/css', 'display/css manquant');
        $this->assertDirectoryExists($displayDir . '/js', 'display/js manquant');
        $this->assertDirectoryExists($displayDir . '/IMG', 'display/IMG manquant');
        $this->assertFileExists($displayDir . '/css/reveal.css');
        $this->assertFileExists($displayDir . '/js/reveal.js');
    }

    /**
     * AC1 — Le module api/ecowatt.php est accessible via catchall (pas de 404).
     */
    public function test_api_ecowatt_module_accessible_via_catchall(): void
    {
        $response = $this->get('/api/ecowatt.php');
        // Le module peut retourner une erreur de dépendance mais pas un 404
        $this->assertNotEquals(404, $response->status(), 'Le module api/ecowatt.php ne doit pas retourner 404');
    }

    // ── AC2 : Données via shim LDAP ───────────────────────────────────

    /**
     * AC2 — Les fonctions LDAP shimmées sont disponibles pour les modules.
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
     * AC2 — functions.inc.php est chargé et fournit les fonctions utilitaires.
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
     * AC2 — is_eleve() et is_prof() sont disponibles via le shim LDAP.
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

    /**
     * Le bridge vendor/autoload.php dans legacy/modules/ fonctionne.
     */
    public function test_vendor_autoload_bridge_works(): void
    {
        $testDir = base_path('legacy/modules/test-vendor');
        @mkdir($testDir, 0777, true);
        // Simule le require_once que font les modules legacy
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

    // ── AC3 : Pas d'erreur récurrente ──────────────────────────────────

    /**
     * AC3 — L'exécution d'un module Tier 1 ne génère pas d'erreur dans le error logger.
     */
    public function test_module_execution_does_not_generate_error_logs(): void
    {
        // Vider les erreurs existantes
        \App\Models\ErrorLog::where('source', 'legacy')->delete();

        $testDir = base_path('legacy/modules/test-ac3');
        @mkdir($testDir, 0777, true);
        // Module minimal qui charge le bootstrap complet (stubs + shims)
        file_put_contents(
            $testDir . '/index.php',
            '<?php
            require_once "config.inc.php";
            require_once "ldap.inc.php";
            $config = get_config();
            echo "AC3_OK";
            ?>'
        );

        try {
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
        } finally {
            @unlink($testDir . '/index.php');
            @rmdir($testDir);
        }
    }
}
