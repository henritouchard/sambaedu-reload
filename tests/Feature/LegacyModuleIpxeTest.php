<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Test Feature : module legacy iPXE (Tier 2) dans legacy/modules/ipxe/.
 *
 * Vérifie que le module est copié, accessible via le catchall,
 * que les shims LDAP fonctionnent dans ce contexte, et que
 * le Content-Type text/plain est correctement géré.
 *
 * NOTE : boot.php appelle exit() quand mac/uuid sont vides, ce qui tue
 * le process PHPUnit. Les tests d'accessibilité catchall utilisent donc
 * config.php (qui ne fait pas exit).
 */
class LegacyModuleIpxeTest extends TestCase
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
        \Illuminate\Support\Facades\DB::table('error_logs')->delete();
        parent::tearDown();
    }

    // ─── AC 1 : Module copié et accessible ──────────────────────────────────

    /**
     * Le module iPXE existe dans legacy/modules/ipxe/ avec sa structure complète.
     */
    public function test_ipxe_module_files_exist(): void
    {
        $base = base_path('legacy/modules/ipxe');

        $this->assertFileExists($base . '/boot.php', 'boot.php manquant');
        $this->assertFileExists($base . '/action.php', 'action.php manquant');
        $this->assertFileExists($base . '/config.php', 'config.php manquant');
        $this->assertFileExists($base . '/admin.php', 'admin.php manquant');
        $this->assertFileExists($base . '/enregistrement.php', 'enregistrement.php manquant');

        $this->assertDirectoryExists($base . '/actions', 'actions/ manquant');
        $this->assertDirectoryExists($base . '/Win10', 'Win10/ manquant');
        $this->assertDirectoryExists($base . '/linux', 'linux/ manquant');
        $this->assertDirectoryExists($base . '/clonezilla', 'clonezilla/ manquant');
        $this->assertDirectoryExists($base . '/sysrescuecd', 'sysrescuecd/ manquant');
    }

    /**
     * Le module contient ~111 fichiers (72 PHP, 25 .cfg).
     */
    public function test_ipxe_module_file_count(): void
    {
        $base = base_path('legacy/modules/ipxe');

        if (!is_dir($base)) {
            $this->markTestSkipped('Module iPXE non copié dans legacy/modules/ipxe/');
        }

        $allFiles = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        $total = 0;
        $phpCount = 0;
        $cfgCount = 0;

        foreach ($allFiles as $file) {
            if ($file->isFile()) {
                $total++;
                if ($file->getExtension() === 'php') {
                    $phpCount++;
                }
                if ($file->getExtension() === 'cfg') {
                    $cfgCount++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(100, $total, "Le module devrait contenir ~111 fichiers, trouvé: {$total}");
        $this->assertGreaterThanOrEqual(70, $phpCount, "Le module devrait contenir ~72 PHP, trouvé: {$phpCount}");
        $this->assertGreaterThanOrEqual(20, $cfgCount, "Le module devrait contenir ~25 .cfg, trouvé: {$cfgCount}");
    }

    /**
     * Le module iPXE est résolu par le catchall (config.php ne fait pas exit).
     */
    public function test_ipxe_config_accessible_via_catchall(): void
    {
        $response = $this->get('/ipxe/config.php');
        $response->assertSuccessful();
    }

    // ─── AC 2 : LDAP via shim ──────────────────────────────────────────────

    /**
     * ldap_dn2oudn() retourne le parent DN correct dans le contexte iPXE.
     */
    public function test_ldap_dn2oudn_returns_parent_dn(): void
    {
        require_once base_path('legacy/ldap.inc.php');

        $dn = 'CN=PC-SALLE101,OU=computers,OU=0991229Y,DC=ecole,DC=local';
        $result = ldap_dn2oudn($dn);

        $this->assertEquals(
            'OU=computers,OU=0991229Y,DC=ecole,DC=local',
            $result,
            'ldap_dn2oudn doit retourner le parent DN (sans le premier RDN)'
        );
    }

    /**
     * ldap_dn2cn() retourne le CN correct.
     */
    public function test_ldap_dn2cn_extracts_cn(): void
    {
        require_once base_path('legacy/ldap.inc.php');

        $dn = 'CN=PC-SALLE101,OU=computers,DC=ecole,DC=local';
        $result = ldap_dn2cn($dn);

        $this->assertEquals('PC-SALLE101', $result);
    }

    /**
     * move_ad() est un stub qui log et retourne false (limitation connue).
     */
    public function test_move_ad_is_stub_returns_false(): void
    {
        require_once base_path('legacy/ldap.inc.php');

        $config = ['se4ad_ip' => '127.0.0.1'];
        $result = move_ad($config, 'PC-TEST', 'CN=PC-TEST,OU=new,DC=local', 'machine');

        $this->assertFalse($result, 'move_ad doit retourner false (stub non implémenté)');
    }

    // ─── AC 1, 4 : Content-Type text/plain non wrappé ───────────────────────

    /**
     * Le catchall ne wrappe pas les réponses non-HTML dans le layout.
     * Vérifie via isHtmlWebPage() que text/plain n'est pas considéré HTML.
     */
    public function test_catchall_does_not_wrap_text_plain_in_layout(): void
    {
        $controller = new \App\Http\Controllers\LegacyCatchallController();
        $method = new \ReflectionMethod($controller, 'isHtmlWebPage');
        $method->setAccessible(true);

        // text/plain avec contenu iPXE → ne doit PAS être wrappé
        $ipxeContent = "#!ipxe\nparams\nparam mac \${net0/mac}\n";
        $this->assertFalse(
            $method->invoke($controller, 'text/plain', $ipxeContent),
            'text/plain ne doit pas être considéré comme une page HTML'
        );

        // text/html avec contenu de page → doit être wrappé
        $htmlContent = '<div><h1>Test</h1><form action="test.php">...</form></div>';
        $this->assertTrue(
            $method->invoke($controller, 'text/html; charset=UTF-8', $htmlContent),
            'text/html avec marqueurs HTML doit être considéré comme une page web'
        );
    }

    // ─── AC 5 : Pas d'erreur fatale ─────────────────────────────────────────

    /**
     * Le chargement d'un module iPXE simple via catchall ne produit pas d'erreur fatale.
     */
    public function test_no_fatal_error_after_ipxe_module_load(): void
    {
        $this->get('/ipxe/config.php');

        $fatalErrors = \Illuminate\Support\Facades\DB::table('error_logs')
            ->where('source', 'legacy')
            ->where('message', 'like', '%Fatal%')
            ->count();

        $this->assertEquals(0, $fatalErrors,
            'Aucune erreur fatale ne doit être loguée après chargement du module iPXE');
    }
}
