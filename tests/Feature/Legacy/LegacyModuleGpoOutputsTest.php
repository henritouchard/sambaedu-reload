<?php

namespace Tests\Feature\Legacy;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Test Feature : module legacy GPO — Outputs (story 1bis.18e)
 *
 * Vérifie que les 5 pages legacy output (`network_out.php`, `veyon_out.php`,
 * `wine.php`, `applications.php`, `associations_out.php`) sont copiées dans
 * `legacy/modules/gpo/`, accessibles via le `LegacyCatchallController`, et
 * que les content-types sont correctement détectés (raw vs embedded SER).
 *
 * Tolérance forte sur les ressources absentes en host pur :
 * - APCu non peuplé → apcu_fetch() retourne false → exit silencieux ou 400
 * - packages.xml absent → DOMDocument::load() émet un warning (toléré)
 * - clé publique Veyon absente → openssl_public_encrypt() retourne false (toléré)
 * - fichiers JSON Veyon absents → file_get_contents() retourne false (toléré)
 *
 * Pattern DB (SQLite :memory:) : DatabaseTransactions + Schema::create() manuel
 * dans setUp() — convention du projet (cf. LegacyModuleGpoGestionTest).
 */
class LegacyModuleGpoOutputsTest extends TestCase
{
    use DatabaseTransactions;

    private ?string $legacyIncludesPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Config::set('sambaedu.block_migrated_routes', false);
        Config::set('sambaedu.etab_ou', '');

        if (function_exists('legacy_build_config')) {
            $GLOBALS['config'] = legacy_build_config();
        }

        $stubsPath = base_path('legacy/stubs');
        $this->legacyIncludesPath = config('sambaedu.legacy_path', '/var/www/sambaedu') . '/includes';
        $currentPath = get_include_path();
        if (is_dir($stubsPath) && !str_contains($currentPath, $stubsPath)) {
            $currentPath = $stubsPath . PATH_SEPARATOR . $currentPath;
        }
        if (is_dir($this->legacyIncludesPath) && !str_contains($currentPath, $this->legacyIncludesPath)) {
            $currentPath .= PATH_SEPARATOR . $this->legacyIncludesPath;
        }
        set_include_path($currentPath);

        // Tables nécessaires
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255)->unique();
                $table->string('password', 255)->nullable();
                $table->string('fullname', 255)->nullable();
                $table->string('firstname', 255)->nullable();
                $table->string('lastname', 255)->nullable();
                $table->string('email', 255)->nullable();
                $table->text('dn')->nullable();
                $table->string('ad_guid', 36)->nullable()->unique();
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->json('ad_right_profiles')->nullable();
                $table->unsignedInteger('ad_rights_bitmask')->default(0);
                $table->timestamp('ad_synced_at')->nullable();
                $table->rememberToken();
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
    }

    /**
     * Skip si le bootstrap legacy (gpo.inc.php + sambaedu/vendor/autoload.php)
     * n'est pas disponible — tests réservés à la VM via sshlab1Etab.
     *
     * Pattern aligné sur LegacyModuleGpoGestionTest (18b) : on ne teste PAS
     * la constante LEGACY_SKIP_LEGACY_INCLUDES — seulement la disponibilité
     * réelle des chemins. Ainsi les tests tournent sur VM (où les chemins
     * existent) et skippent proprement sur host pur.
     */
    private function skipIfBootstrapUnavailable(): void
    {
        $gpoIncPath = $this->legacyIncludesPath . '/gpo.inc.php';
        if (!is_file($gpoIncPath)) {
            $this->markTestSkipped(
                'legacy_path/includes/gpo.inc.php introuvable (' . $gpoIncPath . ')'
                    . ' — exécuter sur la VM via se4ssh.'
            );
        }

        $vendorAutoload = base_path('sambaedu/vendor/autoload.php');
        if (!is_file($vendorAutoload)) {
            $this->markTestSkipped(
                'sambaedu/vendor/autoload.php introuvable — traitement_data.inc.php et'
                    . ' list_gpo_templates_git requièrent HTMLPurifier/CzProject via sambaedu/vendor.'
                    . ' Exécuter sur VM.'
            );
        }
    }

    /**
     * Crée un utilisateur admin (login='admin').
     * have_right() court-circuite Spatie pour ce login → true sans interroger les tables roles.
     */
    private function createAdmin(): User
    {
        return User::create([
            'login'    => 'admin',
            'fullname' => 'Admin GPO Outputs',
            'email'    => 'admin-outputs@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
    }

    /**
     * Crée un utilisateur non-admin persisté en DB avec un login absent
     * des rôles admin.
     *
     * Le user est persisté (contrairement à l'ancienne version qui utilisait
     * `new User()` sans save — fonctionnait par accident car list_rights()
     * ne trouvait rien → SE_NO_RIGHT). Plus fidèle au flow prod.
     *
     * Côté legacy, list_rights() fait User::where('login', 'noadmin-outputs')
     * ->first() qui retourne l'instance, mais have_right() retourne false car
     * login != 'admin' et aucun bit d'admin n'est positionné dans
     * ad_rights_bitmask.
     */
    private function createNonAdmin(): User
    {
        return User::create([
            'login'    => 'noadmin-outputs',
            'fullname' => 'User sans droit',
            'email'    => 'noadmin@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
            'ad_rights_bitmask' => 0,
        ]);
    }

    // ─── AC #1 / T4.2 : Fichiers copiés à l'identique ──────────────────────

    /**
     * @test
     */
    public function test_gpo_output_module_files_exist(): void
    {
        $base = base_path('legacy/modules/gpo');
        $src = base_path('sambaedu/gpo');

        // Les 5 fichiers doivent exister dans legacy/modules/gpo/
        $this->assertFileExists($base . '/network_out.php', 'network_out.php absent');
        $this->assertFileExists($base . '/veyon_out.php', 'veyon_out.php absent');
        $this->assertFileExists($base . '/wine.php', 'wine.php absent');
        $this->assertFileExists($base . '/applications.php', 'applications.php absent');
        $this->assertFileExists($base . '/associations_out.php', 'associations_out.php absent');

        // Identité byte-pour-byte avec les sources
        foreach (['network_out.php', 'veyon_out.php', 'wine.php', 'applications.php', 'associations_out.php'] as $file) {
            $this->assertSame(
                file_get_contents($src . '/' . $file),
                file_get_contents($base . '/' . $file),
                "$file doit être identique au source sambaedu/gpo/"
            );
        }

        // network.inc.php disponible via include_path (sur VM) ou markTestSkipped
        $networkInc = $this->legacyIncludesPath . '/network.inc.php';
        if (!is_file($networkInc)) {
            $this->markTestSkipped(
                'sambaedu/includes/network.inc.php introuvable ('
                . $networkInc
                . ') — disponible uniquement sur la VM.'
            );
        }
        $this->assertFileExists($networkInc, 'network.inc.php introuvable via legacy_path');
    }

    // ─── AC #2 / T4.3 : network_out.php — script text/plain ────────────────

    /**
     * @test
     */
    public function test_network_out_returns_plain_text_script(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $response = $this->post('/gpo/network_out.php', [
            'action' => 'startup',
            'os'     => 'linux',
            'id'     => 'dummy_test_id',
        ]);

        $response->assertStatus(200);

        // Le body doit contenir #!/bin/bash (header script bash)
        $body = $response->getContent() ?: '';
        $this->assertStringContainsString('#!/bin/bash', $body,
            'network_out.php startup linux doit retourner un script bash');

        // Content-Type doit être text/plain (pas de layout SER)
        $contentType = $response->headers->get('Content-Type', '');
        $this->assertStringContainsString('text/plain', $contentType,
            'network_out.php doit avoir Content-Type text/plain');

        // Le body NE DOIT PAS contenir du layout SER
        $this->assertStringNotContainsString('<html', strtolower($body),
            'network_out.php ne doit pas être wrappé dans le layout SER');
    }

    // ─── AC #2 / T4.4 : network_out.php sans action — gracieux ─────────────

    /**
     * @test
     */
    public function test_network_out_without_action_is_graceful(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        // GET sans paramètre — la page doit répondre sans Fatal error
        $response = $this->get('/gpo/network_out.php');
        // 200 (body vide) ou 204 — pas de 5xx (Fatal)
        $this->assertLessThan(500, $response->getStatusCode(),
            'network_out.php sans action ne doit pas lever une Fatal error');

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('Fatal error', $body,
            'network_out.php ne doit pas rendre "Fatal error" dans le body');
        $this->assertStringNotContainsString('Uncaught', $body,
            'network_out.php ne doit pas rendre "Uncaught" dans le body');
    }

    // ─── AC #3 / T4.5 : veyon_out.php — mode licence ───────────────────────

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_veyon_out_licence_mode(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        // POST avec licence=1 — si le fichier licence existe, retourner son contenu
        // sinon retourner réponse vide (exit() silencieux)
        $response = $this->post('/gpo/veyon_out.php', ['licence' => '1']);

        // 200 acceptable (exit() avec output vide ou avec contenu fichier)
        $response->assertStatus(200);

        // Pas de Fatal error (pas de 500)
        $this->assertNotEquals(500, $response->getStatusCode(),
            'veyon_out.php ?licence=1 ne doit pas lever une Fatal error');

        // Le body ne doit pas contenir le layout SER
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('<html', strtolower($body),
            'veyon_out.php licence=1 ne doit pas être wrappé dans le layout SER');
    }

    // ─── AC #3 / T4.6 : veyon_out.php nominal sans APCu — gracieux ─────────

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_veyon_out_nominal_without_apcu_is_graceful(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        // POST avec id non peuplé dans APCu → $nom_poste vide → exit() silencieux
        $response = $this->post('/gpo/veyon_out.php', ['id' => 'dummy_test_id_absent']);

        // 200 acceptable (exit silencieux = body vide)
        $response->assertStatus(200);
        $this->assertLessThan(500, $response->getStatusCode(),
            'veyon_out.php avec APCu miss ne doit pas lever une Fatal error');

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('Fatal error', $body,
            'veyon_out.php ne doit pas rendre "Fatal error" dans le body');
        $this->assertStringNotContainsString('Uncaught', $body,
            'veyon_out.php ne doit pas rendre "Uncaught" dans le body');
    }

    // ─── AC #4 / T4.7 : wine.php — refus sans droits admin ─────────────────

    /**
     * @test
     *
     * Note : le legacy fait print("Vous n'avez pas les droits...") dans le else
     * (il n'y a PAS de die() sur le refus dans wine.php — contrairement à gestion_gpo.php).
     * La page continue et affiche le footer. Test safe sans @runInSeparateProcess.
     */
    public function test_wine_page_denies_access_without_admin(): void
    {
        $this->skipIfBootstrapUnavailable();

        $noAdmin = $this->createNonAdmin();
        $this->actingAs($noAdmin);

        $response = $this->get('/gpo/wine.php');
        $response->assertStatus(200);

        $body = $response->getContent() ?: '';
        $this->assertStringContainsString(
            "Vous n'avez pas les droits",
            $body,
            "wine.php doit afficher le message de refus pour un user sans SE_ADMIN"
        );

        // La page de refus ne doit PAS contenir les éléments admin (bouton,
        // formulaire, select). Vérifie que le fallback noadmin court-circuite
        // bien la render de la partie admin.
        $this->assertStringNotContainsString("Générer l'image", $body,
            "La page noadmin ne doit pas exposer le bouton Générer l'image");
        $this->assertStringNotContainsString('<form', $body,
            "La page noadmin ne doit pas exposer le formulaire admin");
        $this->assertStringNotContainsString('<select name=application', $body,
            "La page noadmin ne doit pas exposer le select application");
    }

    // ─── AC #4 / T4.8 : wine.php — page admin avec formulaire ──────────────

    /**
     * @test
     */
    public function test_wine_page_renders_form_for_admin(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $response = $this->get('/gpo/wine.php');
        $response->assertStatus(200);

        $body = $response->getContent() ?: '';

        // La page doit contenir un formulaire
        $this->assertStringContainsStringIgnoringCase('<form', $body,
            'wine.php doit contenir un formulaire pour admin');

        // La page doit contenir le select application
        $this->assertStringContainsStringIgnoringCase('select', $body,
            'wine.php doit contenir un select application');

        // Le bouton "Générer l'image" doit être présent
        $this->assertStringContainsString("Générer l'image", $body,
            'wine.php doit contenir le bouton Générer l\'image');

        // La page doit être dans le layout SER (embedded — pas de <html raw)
        // Le layout SER peut ajouter son propre <html mais la page legacy ne doit pas
        // avoir de topbar legacy
        $this->assertStringNotContainsString(
            'class="navbar navbar-expand-lg navbar-dark bg-primary topbar"',
            $body,
            'wine.php ne doit pas contenir la topbar legacy brute'
        );
    }

    // ─── AC #5 / T4.9 : applications.php sans APCu — gracieux ──────────────

    /**
     * @test
     */
    public function test_applications_php_without_apcu_is_graceful(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        // POST sans context APCu → get_app_scripts_info() retourne vide → rien
        $response = $this->post('/gpo/applications.php', [
            'action'  => 'logon',
            'os'      => 'linux',
            'user'    => 'testuser',
            'machine' => 'PC001',
        ]);

        // Pas de Fatal error (pas de 5xx)
        $this->assertLessThan(500, $response->getStatusCode(),
            'applications.php sans APCu ne doit pas lever une Fatal error');

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('Fatal error', $body,
            'applications.php ne doit pas rendre "Fatal error" dans le body');
        $this->assertStringNotContainsString('Uncaught', $body,
            'applications.php ne doit pas rendre "Uncaught" dans le body');
    }

    // ─── AC #6 / T4.10 : associations_out.php — reject sans id/list ────────

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_associations_out_rejects_missing_id_or_list(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        // POST sans id ni list → header("HTTP/1.1 400 Bad request") + exit()
        // En mode executeViaBootstrap, http_response_code() est capturé
        $response = $this->post('/gpo/associations_out.php', []);

        // La page doit retourner 400
        $this->assertEquals(400, $response->getStatusCode(),
            'associations_out.php sans id/list doit retourner HTTP 400');
    }

    // ─── AC #6 / T4.11 : associations_out.php avec APCu seedé ──────────────

    /**
     * @test
     * Conditionné par la disponibilité d'APCu (et d'inclues legacy).
     * Si APCu est absent → markTestSkipped.
     */
    public function test_associations_out_returns_json_content_type_with_mocked_apcu(): void
    {
        $this->skipIfBootstrapUnavailable();

        // APCu doit être disponible et activé pour ce test
        if (!function_exists('apcu_store') || !apcu_enabled()) {
            $this->markTestSkipped(
                'APCu non disponible ou désactivé en CLI — test conditionné par APCu.'
            );
        }

        // packages.xml (maintenu par 1bis-11) — sans lui DOMDocument::load()
        // retourne null et associations_out.php lève une TypeError PHP 8 → 500.
        // AC12 tolère l'absence ; on skip proprement pour ne pas masquer les
        // vrais échecs (cf. review P11).
        if (!file_exists('/var/sambaedu/unattended/install/wpkg/packages.xml')) {
            $this->markTestSkipped(
                'packages.xml absent — 1bis-11 (wpkg) non mergé ou fichier non populé'
                . ' par appstore sync. Test conditionné par la présence du fichier.'
            );
        }

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        // Seeder APCu avec un contexte minimal
        $testId = 'test_assoc_' . uniqid();
        apcu_store('apps.' . $testId, [
            'machine'            => ['cn' => 'PC001-TEST', 'samaccountname' => 'PC001$'],
            'user'               => ['cn' => 'testuser'],
            'salle'              => 'salle-test',
            'list'               => ['testuser', 'Eleves', 'all'],
            'liste_applications' => ['firefox', 'vlc'],
            'context'            => 'test',
            'interpreter'        => 'bash',
            'action'             => 'logon',
            'os'                 => 'linux',
            'remote'             => false,
        ]);

        // POST avec id APCu peuplé + list JSON valide
        $response = $this->post('/gpo/associations_out.php', [
            'id'   => $testId,
            'list' => json_encode(['file' => ['pdf,AcroRead']]),
        ]);

        // Nettoyer APCu après le test
        apcu_delete('apps.' . $testId);

        // 200 attendu (même si packages.xml absent — known-limitation AC12)
        $response->assertStatus(200);

        // Content-Type doit être text/json
        $contentType = $response->headers->get('Content-Type', '');
        $this->assertStringContainsString('text/json', $contentType,
            'associations_out.php doit retourner Content-Type text/json');

        // Body doit contenir "result" (même si vide — packages.xml absent)
        $body = $response->getContent() ?: '';
        $this->assertStringContainsString('"result"', $body,
            'associations_out.php doit retourner un JSON avec clé "result"');
    }

    // ─── AC #10 / T4.12 : error logger propre — 1 test / endpoint ───────────
    //
    // Chaque test isole l'appel dans un subprocess (@runInSeparateProcess) car
    // plusieurs endpoints (veyon_out, associations_out) font `exit()` qui tue
    // le process PHPUnit et rend muets les hits suivants (cf. review P8). Le
    // split permet de couvrir réellement les 5 endpoints individuellement.
    //
    // NOTE: le helper `assertNoFatalInErrorLogs` est appelé APRÈS la requête
    // pour vérifier que l'endpoint n'a pas loggé d'erreur fatale.

    /**
     * Vérifie qu'aucune erreur Fatal/Cannot redeclare/Call to undefined
     * n'a été enregistrée dans error_logs sur le channel `legacy`.
     */
    private function assertNoFatalInErrorLogs(string $context): void
    {
        $fatalErrors = DB::table('error_logs')
            ->where('source', 'legacy')
            ->where(function ($q) {
                $q->where('message', 'like', '%Fatal%')
                    ->orWhere('message', 'like', '%Uncaught%')
                    ->orWhere('message', 'like', '%CRITICAL%')
                    ->orWhere('message', 'like', '%Call to undefined function%')
                    ->orWhere('message', 'like', '%Cannot redeclare%');
            })
            ->count();

        $this->assertEquals(0, $fatalErrors,
            "Aucune erreur fatale attendue dans error_logs après $context");
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_no_fatal_error_network_out(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $response = $this->post('/gpo/network_out.php', [
            'action' => 'logon', 'os' => 'linux', 'id' => 'dummy_no_fatal',
        ]);

        $this->assertLessThan(500, $response->getStatusCode(),
            'network_out.php ne doit pas renvoyer 5xx');
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('Fatal error', $body);
        $this->assertStringNotContainsString('Uncaught', $body);

        $this->assertNoFatalInErrorLogs('network_out.php');
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_no_fatal_error_veyon_out(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $response = $this->post('/gpo/veyon_out.php', ['id' => 'dummy_no_fatal_veyon']);

        $this->assertLessThan(500, $response->getStatusCode(),
            'veyon_out.php ne doit pas renvoyer 5xx');
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('Fatal error', $body);
        $this->assertStringNotContainsString('Uncaught', $body);

        $this->assertNoFatalInErrorLogs('veyon_out.php');
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_no_fatal_error_wine(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $response = $this->get('/gpo/wine.php');

        $this->assertLessThan(500, $response->getStatusCode(),
            'wine.php ne doit pas renvoyer 5xx');
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('Fatal error', $body);
        $this->assertStringNotContainsString('Uncaught', $body);

        $this->assertNoFatalInErrorLogs('wine.php');
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_no_fatal_error_applications(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $response = $this->post('/gpo/applications.php', [
            'action' => 'startup', 'os' => 'linux',
        ]);

        $this->assertLessThan(500, $response->getStatusCode(),
            'applications.php ne doit pas renvoyer 5xx');
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('Fatal error', $body);
        $this->assertStringNotContainsString('Uncaught', $body);

        $this->assertNoFatalInErrorLogs('applications.php');
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_no_fatal_error_associations_out(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        // POST sans id/list → 400 attendu (guard legacy) mais pas de Fatal
        $response = $this->post('/gpo/associations_out.php', []);

        // 400 (guard) ou 200 — mais pas 5xx
        $this->assertLessThan(500, $response->getStatusCode(),
            'associations_out.php ne doit pas renvoyer 5xx');
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('Fatal error', $body);
        $this->assertStringNotContainsString('Uncaught', $body);

        $this->assertNoFatalInErrorLogs('associations_out.php');
    }

    // ─── AC #7/stub : stub wpkg_libsql.php anti-collision ───────────────────

    /**
     * @test
     */
    public function test_wpkg_libsql_stub_exists_for_collision_prevention(): void
    {
        $this->assertFileExists(
            base_path('legacy/stubs/wpkg_libsql.php'),
            'legacy/stubs/wpkg_libsql.php doit exister pour éviter la collision avec sambaedu/includes/wpkg_libsql.php'
        );

        // Vérifier que le stub pointe vers le shim
        $content = file_get_contents(base_path('legacy/stubs/wpkg_libsql.php'));
        $this->assertStringContainsString(
            'wpkg_libsql.php',
            $content,
            'Le stub doit référencer le shim legacy/wpkg_libsql.php'
        );
    }
}
