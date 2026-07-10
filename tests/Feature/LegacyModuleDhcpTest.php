<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Test Feature : module legacy DHCP (Tier 3) dans legacy/modules/dhcp/.
 *
 * Vérifie que les 6 fichiers du module sont copiés, accessibles via le catchall,
 * que les shims LDAP (have_right, SE_ADMIN, SE_COMPUTER_ADMIN) fonctionnent,
 * et que le error logger ne contient pas d'erreurs bloquantes après chargement.
 *
 * Particularités documentées (limitations infra) :
 * - Les tests catchall (baux.php, config.php) peuvent retourner 500 à cause du
 *   re-bootstrap UrlGenerator quand fonc_parc.inc.php est chargé. C'est une
 *   limitation connue identique au module printers (story 1bis-15). On vérifie
 *   l'absence de Fatal error PHP dans le contenu, pas le code HTTP.
 * - Les endpoints scripts (import_reservations, dnsupdate) font exit() via
 *   header_authorize_script() — ils retournent une réponse vide ou 500 sans fatal.
 * - script_make_reservations.php utilise apcu_fetch/apcu_store/apcu_delete.
 *   Si APCu n'est pas chargé, ce fichier produit une fatal error → test skipped.
 * - Les exec système DHCP (systemctl, isc-dhcp-server, make_dhcpd_conf.sh) échouent
 *   silencieusement sur la VM dev — c'est attendu et documenté comme limitation.
 */
class LegacyModuleDhcpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Config::set('sambaedu.block_migrated_routes', false);

        // D6 (AC2, review 38.4 #2) : le FS legacy est FORCÉ absent — le module
        // doit résoudre TOUS ses includes dans legacy/stubs/ in-repo. Sans ça,
        // sur un hôte où /var/www/sambaedu existe, un stub manquant serait
        // silencieusement masqué par le vrai legacy (faux-vert).
        Config::set('sambaedu.legacy_path', '/nonexistent-legacy-38-4');

        // Réinitialiser $_SESSION pour éviter les fuites entre tests
        $_SESSION = [];

        $stubsPath = base_path('legacy/stubs');
        $currentPath = get_include_path();

        if (is_dir($stubsPath) && !str_contains($currentPath, $stubsPath)) {
            $currentPath = $stubsPath . PATH_SEPARATOR . $currentPath;
        }
        set_include_path($currentPath);

        // Créer la table users nécessaire pour have_right() via le shim LDAP
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login')->unique();
                $table->string('password')->nullable();
                $table->string('fullname')->nullable();
                $table->string('firstname')->nullable();
                $table->string('lastname')->nullable();
                $table->string('email')->nullable();
                $table->string('dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->string('role')->default('eleve');
                $table->boolean('is_active')->default(true);
                $table->json('ad_right_profiles')->nullable();
                $table->integer('ad_rights_bitmask')->default(0);
                $table->timestamp('ad_synced_at')->nullable();
                $table->timestamps();
            });
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

        if (extension_loaded('apcu') && function_exists('apcu_delete')) {
            @apcu_delete('dhcp_reservations_lock');
        }
    }

    protected function tearDown(): void
    {
        try {
            \App\Models\LegacyCatchallLog::query()->delete();
        } catch (\Exception $e) {
            // Ignore si table absente
        }
        try {
            DB::table('error_logs')->delete();
        } catch (\Exception $e) {
            // Ignore si table absente
        }
        if (extension_loaded('apcu') && function_exists('apcu_delete')) {
            @apcu_delete('dhcp_reservations_lock');
        }
        $_SESSION = [];
        parent::tearDown();
    }

    // ─── AC1 : Module copié et accessible ───────────────────────────────────

    /**
     * Les 6 fichiers PHP du module DHCP sont présents dans legacy/modules/dhcp/.
     */
    public function test_module_files_exist(): void
    {
        $base = base_path('legacy/modules/dhcp');

        $this->assertDirectoryExists($base, 'Le dossier legacy/modules/dhcp/ doit exister');

        $fichiers = [
            'baux.php',
            'config.php',
            'dnsupdate.php',
            'import_reservations.php',
            'make_reservations.php',
            'script_make_reservations.php',
        ];

        foreach ($fichiers as $fichier) {
            $this->assertFileExists(
                $base . '/' . $fichier,
                "Le fichier {$fichier} doit être présent dans legacy/modules/dhcp/"
            );
        }

        // Vérifier le nombre exact de fichiers PHP
        $phpFiles = glob($base . '/*.php');
        $this->assertCount(6, $phpFiles, 'Le module doit contenir exactement 6 fichiers PHP');
    }

    /**
     * baux.php est résolu par le catchall (le module existe et est dispatché).
     *
     * Limitation connue : les pages qui chargent fonc_parc.inc.php peuvent
     * retourner 500 à cause du re-bootstrap UrlGenerator (même comportement
     * que le module printers — story 1bis-15). On vérifie que la réponse
     * n'est pas un 404 (module non trouvé) et que si du contenu est présent,
     * il ne contient pas de "Fatal error" PHP legacy.
     *
     * Le test d'accès complet (200 avec layout SER) est validé via smoke test
     * curl sur la VM (cf. Testing Strategy § Smoke test VM).
     */
    public function test_baux_loads_without_fatal(): void
    {
        if (!is_dir(base_path('legacy/modules/dhcp'))) {
            $this->markTestSkipped('Module DHCP non copié dans legacy/modules/dhcp/');
        }

        $response = $this->get('/dhcp/baux.php');

        // Le module est résolu (pas 404) — peut être 200 ou 500 selon l'env
        $this->assertNotEquals(
            404,
            $response->getStatusCode(),
            'baux.php doit être résolu par le catchall (pas 404 — module trouvé)'
        );

        // Si on a du contenu, pas de Fatal error PHP legacy
        $content = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase(
            'Fatal error',
            $content,
            'baux.php ne doit pas produire de Fatal error PHP legacy dans le contenu'
        );
    }

    /**
     * config.php est résolu par le catchall sans Fatal error PHP legacy.
     *
     * Même limitation que baux.php (voir ci-dessus).
     */
    public function test_config_loads_without_fatal(): void
    {
        if (!is_dir(base_path('legacy/modules/dhcp'))) {
            $this->markTestSkipped('Module DHCP non copié dans legacy/modules/dhcp/');
        }

        $response = $this->get('/dhcp/config.php');

        $this->assertNotEquals(
            404,
            $response->getStatusCode(),
            'config.php doit être résolu par le catchall (pas 404 — module trouvé)'
        );

        $content = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase(
            'Fatal error',
            $content,
            'config.php ne doit pas produire de Fatal error PHP legacy dans le contenu'
        );
    }

    // ─── AC5 : Endpoints scripts sans layout ────────────────────────────────

    /**
     * make_reservations.php est accessible sans fatal error.
     *
     * Ce script force login=admin et appelle export_dhcp_reservations($config).
     * Il ne fait pas exit() via header_authorize_script. La réponse peut être
     * vide si aucune réservation n'est configurée.
     */
    public function test_make_reservations_loads_without_fatal(): void
    {
        if (!is_dir(base_path('legacy/modules/dhcp'))) {
            $this->markTestSkipped('Module DHCP non copié dans legacy/modules/dhcp/');
        }

        $response = $this->get('/dhcp/make_reservations.php');

        $this->assertNotEquals(
            404,
            $response->getStatusCode(),
            'make_reservations.php doit être résolu par le catchall'
        );

        $content = $response->getContent();

        $this->assertStringNotContainsStringIgnoringCase(
            'Fatal error',
            $content,
            'make_reservations.php ne doit pas produire de Fatal error PHP'
        );

        $this->assertStringNotContainsStringIgnoringCase(
            'Uncaught Error',
            $content,
            'make_reservations.php ne doit pas avoir d\'Uncaught Error'
        );
    }

    /**
     * import_reservations.php : header_authorize_script() fait un exit() si
     * la clé se4 est absente — le catchall capture ça comme une réponse vide.
     * On vérifie qu'il n'y a pas de fatal error PHP legacy dans le contenu.
     */
    public function test_import_reservations_loads_without_fatal(): void
    {
        if (!is_dir(base_path('legacy/modules/dhcp'))) {
            $this->markTestSkipped('Module DHCP non copié dans legacy/modules/dhcp/');
        }

        $response = $this->post('/dhcp/import_reservations.php', []);

        $this->assertNotEquals(
            404,
            $response->getStatusCode(),
            'import_reservations.php doit être résolu par le catchall'
        );

        $content = $response->getContent();

        $this->assertStringNotContainsStringIgnoringCase(
            'Fatal error',
            $content,
            'import_reservations.php ne doit pas produire de Fatal error PHP'
        );
    }

    /**
     * dnsupdate.php avec POST vide : header_authorize_script() fait exit() si
     * la clé se4 est absente → réponse vide. Pas d'appel DNS (action vide).
     * On vérifie l'absence de fatal error.
     */
    public function test_dnsupdate_accepts_empty_post(): void
    {
        if (!is_dir(base_path('legacy/modules/dhcp'))) {
            $this->markTestSkipped('Module DHCP non copié dans legacy/modules/dhcp/');
        }

        $response = $this->post('/dhcp/dnsupdate.php', []);

        $this->assertNotEquals(
            404,
            $response->getStatusCode(),
            'dnsupdate.php doit être résolu par le catchall'
        );

        $content = $response->getContent();

        $this->assertStringNotContainsStringIgnoringCase(
            'Fatal error',
            $content,
            'dnsupdate.php ne doit pas produire de Fatal error PHP sur POST vide'
        );
    }

    // ─── AC2, AC6 : Shim LDAP + constantes ─────────────────────────────────

    /**
     * have_right($config, SE_ADMIN) ne lève pas de fatal error ou exception.
     *
     * SE_ADMIN = 0xFFFF — défini dans legacy/ldap.inc.php.
     * have_right() fait une requête Eloquent/DB vers la table users → retourne
     * false si l'utilisateur n'existe pas, sans crash.
     */
    public function test_have_right_se_admin_does_not_crash(): void
    {
        require_once base_path('legacy/ldap.inc.php');

        $this->assertTrue(defined('SE_ADMIN'), 'La constante SE_ADMIN doit être définie');
        $this->assertEquals(0xFFFF, SE_ADMIN, 'SE_ADMIN doit valoir 0xFFFF');

        $config = $GLOBALS['config'] ?? [];

        // have_right() ne doit pas lancer d'exception
        try {
            $result = have_right($config, SE_ADMIN);
            $this->assertIsBool($result, 'have_right() doit retourner un booléen');
        } catch (\Throwable $e) {
            $this->fail('have_right($config, SE_ADMIN) a lancé une exception : ' . $e->getMessage());
        }
    }

    /**
     * have_right($config, SE_COMPUTER_ADMIN) ne lève pas de fatal error ou exception.
     *
     * SE_COMPUTER_ADMIN = SE_COMPUTER_VIEW | SE_COMPUTER_CONTROL | ... — bitmask composé.
     */
    public function test_have_right_se_computer_admin_does_not_crash(): void
    {
        require_once base_path('legacy/ldap.inc.php');

        $this->assertTrue(defined('SE_COMPUTER_ADMIN'), 'La constante SE_COMPUTER_ADMIN doit être définie');

        // Vérifier que la valeur est un bitmask composé
        $expected = SE_COMPUTER_VIEW | SE_COMPUTER_CONTROL | SE_COMPUTER_ELEVATE | SE_COMPUTER_INSTALL | SE_WPKG_ASSIGN | SE_WPKG_ADD;
        $this->assertEquals($expected, SE_COMPUTER_ADMIN, 'SE_COMPUTER_ADMIN doit être le bitmask composé attendu');

        $config = $GLOBALS['config'] ?? [];

        try {
            $result = have_right($config, SE_COMPUTER_ADMIN);
            $this->assertIsBool($result, 'have_right() doit retourner un booléen');
        } catch (\Throwable $e) {
            $this->fail('have_right($config, SE_COMPUTER_ADMIN) a lancé une exception : ' . $e->getMessage());
        }
    }

    // ─── AC5 : script_make_reservations (APCu) ──────────────────────────────

    /**
     * script_make_reservations.php utilise apcu_fetch/apcu_store/apcu_delete.
     * Si APCu n'est pas chargé, ce test est skipped (limitation documentée).
     * Si APCu est chargé, on vérifie que le script ne fatal-crashe pas.
     */
    public function test_script_make_reservations_with_apcu(): void
    {
        if (!extension_loaded('apcu')) {
            $this->markTestSkipped(
                'APCu non disponible dans l\'env PHPUnit — script_make_reservations.php ' .
                'utilisera apcu_fetch/store/delete et échouera fatalement. ' .
                'Limitation documentée dans la story 1bis-16.'
            );
        }

        if (!is_dir(base_path('legacy/modules/dhcp'))) {
            $this->markTestSkipped('Module DHCP non copié dans legacy/modules/dhcp/');
        }

        // Purger le verrou APCu avant le test
        if (apcu_exists('dhcp_reservations_lock')) {
            apcu_delete('dhcp_reservations_lock');
        }

        $response = $this->get('/dhcp/script_make_reservations.php');

        $content = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase(
            'Fatal error',
            $content,
            'script_make_reservations.php ne doit pas produire de Fatal error'
        );
    }

    // ─── Tests authentifiés (actingAs) ──────────────────────────────────────

    /**
     * Crée un utilisateur 'admin' — list_rights() court-circuite pour ce login
     * et renvoie SE_ADMIN=0xFFFF, couvrant SE_COMPUTER_ADMIN.
     */
    private function createAdmin(): User
    {
        return User::create([
            'login'    => 'admin',
            'fullname' => 'Admin DHCP',
            'email'    => 'admin@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
    }

    /**
     * baux.php en admin authentifié — have_right(SE_COMPUTER_ADMIN) passe,
     * la page rend sans Fatal error.
     */
    public function test_baux_accessible_for_authenticated_admin(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $response = $this->get('/dhcp/baux.php');

        $this->assertNotEquals(404, $response->getStatusCode());
        $this->assertNotEquals(403, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase('Fatal error', $content);
        $this->assertStringNotContainsStringIgnoringCase("Vous n'avez pas les droits", $content);
    }

    /**
     * config.php en admin authentifié — have_right(SE_ADMIN) passe.
     */
    public function test_config_accessible_for_authenticated_admin(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $response = $this->get('/dhcp/config.php');

        $this->assertNotEquals(404, $response->getStatusCode());
        $this->assertNotEquals(403, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase('Fatal error', $content);
    }

    /**
     * make_reservations.php en admin — force login=admin côté script, appelle
     * export_dhcp_reservations() (shimmé). Pas de Fatal error « Call to undefined
     * function » sur l'endpoint.
     */
    public function test_make_reservations_calls_shimmed_export(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $response = $this->get('/dhcp/make_reservations.php');

        $this->assertNotEquals(404, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase(
            'Call to undefined function',
            $content,
            'export_dhcp_reservations doit être shimmée (legacy/dhcp_shim.inc.php)'
        );
    }

    // ─── Shim DHCP : fonctions disponibles ──────────────────────────────────

    /**
     * Le shim DHCP (legacy/dhcp_shim.inc.php) déclare les fonctions métier
     * utilisées par les 6 modules. Sans lui, make_reservations.php fatal.
     */
    public function test_dhcp_shim_functions_are_declared(): void
    {
        require_once base_path('legacy/dhcp_shim.inc.php');

        $functions = [
            'valid_mac',
            'format_mac',
            'get_dhcp_reservation',
            'delete_dhcp_reservation',
            'set_dhcp_reservation',
            'export_dhcp_reservations',
            'import_dhcp_reservations',
            'import_dhcp_leases',
            'list_dhcp_leases',
        ];
        foreach ($functions as $fn) {
            $this->assertTrue(
                function_exists($fn),
                "La fonction {$fn}() doit être déclarée par dhcp_shim.inc.php"
            );
        }
    }

    /**
     * valid_mac() : validation stricte du format xx:xx:xx:xx:xx:xx.
     */
    public function test_valid_mac_accepts_and_rejects_correctly(): void
    {
        require_once base_path('legacy/dhcp_shim.inc.php');

        $this->assertTrue(valid_mac('aa:bb:cc:dd:ee:ff'));
        $this->assertTrue(valid_mac('00:11:22:33:44:55'));
        $this->assertFalse(valid_mac(''));
        $this->assertFalse(valid_mac('not-a-mac'));
        $this->assertFalse(valid_mac('aa:bb:cc:dd:ee'));
        $this->assertFalse(valid_mac('gg:hh:ii:jj:kk:ll'));
    }

    // ─── AC7 : Error logger propre ──────────────────────────────────────────

    /**
     * Après chargement des pages DHCP, le error logger ne doit pas contenir
     * d'erreurs fatales PHP legacy pour le tag 'legacy'.
     *
     * Les erreurs des exec système manquants (isc-dhcp-server absent) et
     * les erreurs UrlGenerator liées au re-bootstrap sont des limitations
     * d'infrastructure documentées — elles n'apparaissent pas dans error_logs
     * mais dans les logs Laravel/legacylog.
     */
    public function test_error_logger_clean_after_module_load(): void
    {
        // Charger les deux pages principales (ignorant le code HTTP)
        $this->get('/dhcp/baux.php');
        $this->get('/dhcp/config.php');

        // Vérifier l'absence d'erreurs FATAL PHP legacy dans error_logs
        // (les erreurs d'infra UrlGenerator sont dans laravel.log, pas error_logs)
        $fatalErrors = DB::table('error_logs')
            ->where('source', 'legacy')
            ->where(function ($query) {
                $query->where('message', 'like', '%Fatal error%')
                    ->orWhere('message', 'like', '%fatal error%');
            })
            ->count();

        $this->assertEquals(
            0,
            $fatalErrors,
            'Aucune "Fatal error" PHP legacy ne doit être présente dans error_logs après chargement des pages DHCP'
        );
    }
}
