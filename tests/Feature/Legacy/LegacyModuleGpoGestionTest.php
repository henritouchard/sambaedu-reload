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
 * Test Feature : module legacy GPO (Tier 3) — story 1bis.18b.
 *
 * Vérifie que les 3 pages legacy `gestion_gpo.php`, `gpo-maj.php` et
 * `gpo-export.php` sont copiées dans `legacy/modules/gpo/`, accessibles
 * via le `LegacyCatchallController`, rendues avec CSRF + embedding SER,
 * et que les fonctions GPO consommées sont résolues après bootstrap.
 *
 * Pattern DB (SQLite :memory:) :
 * - DatabaseTransactions + Schema::create() manuel dans setUp() —
 *   convention du projet (cf. UserCreationTest, ShortcutSyncTest…).
 * - Pour contourner Spatie (tables roles/model_has_roles non créées),
 *   le user admin utilise login='admin' : have_right() retourne true
 *   immédiatement pour ce login sans passer par getRoleNames().
 * - Le user sans droit a un login quelconque absent de la table users :
 *   list_rights() retourne SE_NO_RIGHT (user not found) → have_right() false.
 */
class LegacyModuleGpoGestionTest extends TestCase
{
    use DatabaseTransactions;

    private ?string $legacyIncludesPath = null;
    private ?string $tempGitConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Désactivé : portage natif Laravel des modules legacy GPO en cours
        // (Epic 16/17). Les pages legacy (`gestion_gpo.php`, `gpo-maj.php`,
        // `gpo-export.php`) sont remplacées par leurs équivalents natifs ;
        // tester leur invocation via le catchall n'a plus vocation à durer.
        // @todo Supprimer ce test lors de story 16.13 (retrait des shims GPO).
        $this->markTestSkipped('Désactivé pendant le portage natif Laravel des modules legacy GPO (Epic 16/17).');

        $this->withoutVite();

        Config::set('sambaedu.block_migrated_routes', false);
        Config::set('sambaedu.etab_ou', '');
        // legacy/config.inc.php est chargé via require_once : $GLOBALS['config']
        // se fige au premier run et garde la valeur de etab_ou d'un éventuel test
        // précédent. Réinitialiser explicitement ici pour éviter la pollution.
        if (function_exists('legacy_build_config')) {
            $GLOBALS['config'] = legacy_build_config();
        }

        // Le repo /usr/share/sambaedu/gpo/sambaedu-gpo appartient à www-admin ;
        // en CLI (tests) on est souvent root → git refuse à cause de safe.directory.
        // On pointe GIT_CONFIG_GLOBAL vers un fichier temporaire qui autorise tout.
        $this->tempGitConfig = tempnam(sys_get_temp_dir(), 'gitconfig_gpo_');
        file_put_contents($this->tempGitConfig, "[safe]\n\tdirectory = *\n");
        putenv('GIT_CONFIG_GLOBAL=' . $this->tempGitConfig);

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

    protected function tearDown(): void
    {
        if ($this->tempGitConfig && file_exists($this->tempGitConfig)) {
            @unlink($this->tempGitConfig);
        }
        putenv('GIT_CONFIG_GLOBAL');
        parent::tearDown();
    }

    /**
     * Skip si le bootstrap legacy (gpo.inc.php + sambaedu/vendor/autoload.php)
     * n'est pas disponible — tests réservés à la VM via sshlab1Etab.
     */
    private function skipIfBootstrapUnavailable(): void
    {
        if (defined('LEGACY_SKIP_LEGACY_INCLUDES')) {
            $this->markTestSkipped(
                'LEGACY_SKIP_LEGACY_INCLUDES actif (tests) : les modules legacy'
                    . ' GPO nécessitent les includes legacy originaux (include "gpo.inc.php")'
                    . ' qui redéclareraient les fonctions shim. Skip.'
            );
        }

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
     * Crée un utilisateur avec login='admin'.
     * have_right() court-circuite Spatie pour ce login et retourne true
     * sans interroger la table roles.
     */
    private function createAdmin(): User
    {
        return User::create([
            'login'    => 'admin',
            'fullname' => 'Admin GPO',
            'email'    => 'admin@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
    }

    /**
     * Crée un utilisateur non persisté avec un login absent de la DB users.
     *
     * actingAs() n'exige pas la persistance : il set juste l'instance dans le guard.
     * Côté legacy, list_rights() fait User::where('login', 'prof-noadmin')->first()
     * qui retourne null → SE_NO_RIGHT → have_right() false → die() "Vous n'avez pas
     * les droits", sans toucher aux tables Spatie (roles/model_has_roles absentes
     * en SQLite :memory:).
     */
    private function createNonAdmin(): User
    {
        $user = new User();
        $user->id = 999999;
        $user->login = 'prof-noadmin';
        $user->fullname = 'Prof sans droit';
        $user->email = 'prof@test.local';
        $user->is_active = true;
        return $user;
    }

    // ─── AC #1 : Fichiers copiés ────────────────────────────────────────────

    public function test_gpo_module_files_exist(): void
    {
        $base = base_path('legacy/modules/gpo');
        $this->assertFileExists($base . '/gestion_gpo.php');
        $this->assertFileExists($base . '/gpo-maj.php');
        $this->assertFileExists($base . '/gpo-export.php');

        $src = base_path('sambaedu/gpo');
        // gpo-maj.php a été corrigé (bug accès ligne 45) — pas d'assertion d'identité ici
        $this->assertFileExists($src . '/gestion_gpo.php');
        $this->assertSame(
            file_get_contents($src . '/gestion_gpo.php'),
            file_get_contents($base . '/gestion_gpo.php'),
            'gestion_gpo.php doit être identique au source'
        );
        $this->assertSame(
            file_get_contents($src . '/gpo-export.php'),
            file_get_contents($base . '/gpo-export.php'),
            'gpo-export.php doit être identique au source'
        );
    }

    // ─── AC #3 : gestion_gpo.php accessible pour admin ──────────────────────

    public function test_gestion_gpo_page_is_accessible_for_computer_admin(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $response = $this->get('/gpo/gestion_gpo.php');
        $response->assertStatus(200);
        $response->assertSee('Gestion des GPO', false);
        $response->assertSee('gpo-maj.php', false);
        $response->assertSee('gpo-export.php', false);
        $response->assertSee('no_roam.php', false);
    }

    // ─── AC #3/#9 : refus sans droit ────────────────────────────────────────
    //
    // Les pages legacy appellent die("Vous n'avez pas les droits...") après
    // have_right(). En CLI, die() termine tout le process PHPUnit — et même
    // @runInSeparateProcess ne permet pas au subprocess de renvoyer ses
    // assertions (PHPUnit reçoit "ended unexpectedly"). Impossible de tester
    // le deny via $this->get() sans refactorer le catchall pour intercepter
    // die() via shutdown_function + réponse HTTP construite en aval.
    //
    // Cas couvert par : test manuel sur la VM + future suite e2e HTTP.

    public function test_gestion_gpo_page_denies_access_without_right(): void
    {
        $this->markTestSkipped(
            'die() du legacy tue le process PHPUnit (même en subprocess). Deny '
            . 'testable uniquement via e2e HTTP sur la VM.'
        );
    }

    public function test_gpo_export_page_denies_access_without_right(): void
    {
        $this->markTestSkipped(
            'die() du legacy tue le process PHPUnit (même en subprocess). Deny '
            . 'testable uniquement via e2e HTTP sur la VM.'
        );
    }

    // ─── AC #4 : gpo-maj.php rend les <select> ──────────────────────────────

    public function test_gpo_maj_page_renders_templates_selects(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $response = $this->get('/gpo/gpo-maj.php');
        $response->assertStatus(200);

        $html = $response->getContent() ?: '';
        $this->assertStringContainsStringIgnoringCase('<SELECT NAME="imports[]"', $html);
        $this->assertStringContainsString("Importation des GPOs dans l'AD", $html);
    }

    // ─── AC #6 : gpo-export.php rend le <select> ────────────────────────────

    public function test_gpo_export_page_renders_gpo_list(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        $response = $this->get('/gpo/gpo-export.php');
        $response->assertStatus(200);

        $html = $response->getContent() ?: '';
        $this->assertStringContainsString('Export des GPO', $html);
        $this->assertStringContainsStringIgnoringCase('<SELECT NAME="exports[]"', $html);
    }

    // ─── AC #2 : fonctions GPO disponibles après bootstrap ──────────────────

    public function test_gpo_functions_are_available_after_bootstrap(): void
    {
        $this->skipIfBootstrapUnavailable();

        require_once base_path('legacy/bootstrap.php');

        $requiredFunctions = [
            'list_gpo_templates', 'list_gpo_templates_git', 'list_gpo_templates_etab',
            'read_gpo_json', 'gpo_version', 'compare_list_gpo_by_name',
            'check_gpo_templates', 'import_gpo', 'export_gpo', 'read_gpo_sysvol',
            'gpocreate', 'gpogetlink',
            'search_ad', 'have_right',
            'get_config',
            'admin_header_html', 'admin_topbar_html', 'admin_menu_html',
            'admin_footer_html', 'header_authorize',
        ];

        foreach ($requiredFunctions as $fn) {
            $this->assertTrue(function_exists($fn), "La fonction $fn() doit être disponible après bootstrap");
        }
    }

    // ─── AC #7 : CSRF + réécriture des actions ──────────────────────────────

    public function test_gpo_forms_have_csrf_token_and_current_action(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        foreach (['/gpo/gpo-maj.php', '/gpo/gpo-export.php'] as $url) {
            $response = $this->get($url);
            $response->assertStatus(200);
            $html = $response->getContent() ?: '';

            $this->assertStringContainsString(
                '<input type="hidden" name="_token"',
                $html,
                "Token CSRF absent dans $url"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<form[^>]*\saction\s*=\s*["\'](?!\/|https?:)[^"\']*\.php["\']/i',
                $html,
                "Action relative non réécrite dans $url"
            );
        }
    }

    // ─── AC #1 : embedding dans le layout SER ───────────────────────────────

    public function test_gpo_pages_are_embedded_in_ser_layout(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        foreach (['/gpo/gestion_gpo.php', '/gpo/gpo-maj.php', '/gpo/gpo-export.php'] as $url) {
            $response = $this->get($url);
            $response->assertStatus(200);

            $html = $response->getContent() ?: '';
            $this->assertLessThanOrEqual(1, substr_count(strtolower($html), '<html'),
                "Pas plus d'un <html> sur $url");
            $this->assertStringNotContainsString(
                'class="navbar navbar-expand-lg navbar-dark bg-primary topbar"',
                $html,
                "Topbar legacy présente sur $url"
            );
        }
    }

    // ─── AC #10 : error logger propre ───────────────────────────────────────

    public function test_no_fatal_error_after_passive_load(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createAdmin();
        $this->actingAs($admin);

        foreach (['/gpo/gestion_gpo.php', '/gpo/gpo-maj.php', '/gpo/gpo-export.php'] as $url) {
            $this->get($url);
        }

        $fatalErrors = DB::table('error_logs')
            ->where('source', 'legacy')
            ->where(function ($q) {
                $q->where('message', 'like', '%Fatal%')
                    ->orWhere('message', 'like', '%CRITICAL%')
                    ->orWhere('message', 'like', '%Call to undefined function%');
            })
            ->count();

        $this->assertEquals(0, $fatalErrors,
            'Aucune erreur fatale après chargement passif des 3 pages GPO');
    }
}
