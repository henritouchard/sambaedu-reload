<?php

namespace Tests\Feature\Legacy;

use App\Enums\SambaRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Test Feature : module legacy GPO (Tier 3) — story 1bis.18b.
 *
 * Vérifie que les 3 pages legacy `gestion_gpo.php`, `gpo-maj.php` et
 * `gpo-export.php` sont copiées dans `legacy/modules/gpo/`, accessibles
 * via le `LegacyCatchallController`, rendues avec CSRF + embedding SER,
 * et que les fonctions GPO consommées sont résolues après bootstrap.
 *
 * Contraintes d'environnement :
 * - Le bootstrap legacy charge `sambaedu/includes/gpo.inc.php` etc. depuis
 *   `legacy_path` (par défaut `/var/www/sambaedu/includes/`). Sur la VM dev
 *   ces chemins existent ; sur le host CI ils peuvent être absents. Chaque
 *   test qui nécessite le bootstrap appelle `skipIfBootstrapUnavailable()`
 *   qui skipe si `legacy_path/includes/gpo.inc.php` ou
 *   `sambaedu/vendor/autoload.php` sont introuvables.
 * - `gestion_gpo.php` appelle `check_gpo_templates($config)` qui fait un
 *   `require_once sambaedu/vendor/autoload.php` (via `list_gpo_templates_git`).
 *   Hors VM (sambaedu/vendor/ absent), cet appel peut échouer — le skip
 *   ci-dessus protège ces tests.
 * - `exit()` / `die()` dans les pages sont interceptés par `ob_start` dans
 *   `executeViaBootstrap` du catchall — PHPUnit n'est pas tué. On utilise
 *   toujours un utilisateur avec le rôle `computer-admin` pour éviter le
 *   `die()` du `have_right()`, et on vérifie le message de refus via le
 *   contenu HTML capturé par le catchall.
 */
class LegacyModuleGpoGestionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Chemin vers les includes legacy (où gpo.inc.php doit résoudre).
     */
    private ?string $legacyIncludesPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Config::set('sambaedu.block_migrated_routes', false);
        Config::set('sambaedu.etab_ou', '');

        // Reconstruire l'include_path (idempotent) pour que les tests
        // puissent `require_once legacy/bootstrap.php` plusieurs fois.
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

        // Créer les rôles Spatie nécessaires (computer-admin donne SE_COMPUTER_ADMIN).
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::findOrCreate(SambaRole::ComputerAdmin->value, 'web');
        Role::findOrCreate(SambaRole::Prof->value, 'web');
    }

    /**
     * Utilitaire — skip les tests qui dépendent du bootstrap legacy si :
     * - les includes legacy (gpo.inc.php) ne sont pas disponibles (hors VM), ou
     * - sambaedu/vendor/autoload.php est absent (traitement_data.inc.php et
     *   list_gpo_templates_git nécessitent HTMLPurifier/CzProject\GitPhp via vendor).
     */
    private function skipIfBootstrapUnavailable(): void
    {
        $gpoIncPath = $this->legacyIncludesPath . '/gpo.inc.php';
        if (!is_file($gpoIncPath)) {
            $this->markTestSkipped(
                'legacy_path/includes/gpo.inc.php introuvable (' . $gpoIncPath . ')'
                    . ' — le test doit être exécuté sur la VM via sshlab1Etab.'
            );
        }

        $vendorAutoload = base_path('sambaedu/vendor/autoload.php');
        if (!is_file($vendorAutoload)) {
            $this->markTestSkipped(
                'sambaedu/vendor/autoload.php introuvable (' . $vendorAutoload . ')'
                    . ' — traitement_data.inc.php et list_gpo_templates_git requièrent'
                    . ' HTMLPurifier/CzProject\GitPhp via sambaedu/vendor. Exécuter sur VM.'
            );
        }
    }

    /**
     * Crée un utilisateur Spatie avec le rôle `computer-admin`
     * (qui donne le bitmask SE_COMPUTER_ADMIN via le mapping de legacy/ldap.inc.php).
     */
    private function createComputerAdmin(string $login = 'gpo-admin'): User
    {
        $user = User::create([
            'login' => $login,
            'fullname' => 'GPO Admin',
            'email' => $login . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $user->assignRole(SambaRole::ComputerAdmin->value);
        return $user;
    }

    /**
     * Crée un utilisateur sans droit admin (rôle `prof`).
     */
    private function createNonAdmin(string $login = 'gpo-prof'): User
    {
        $user = User::create([
            'login' => $login,
            'fullname' => 'GPO Prof',
            'email' => $login . '@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $user->assignRole(SambaRole::Prof->value);
        return $user;
    }

    // ─── AC #1 : Fichiers copiés ────────────────────────────────────────────

    /**
     * Les 3 fichiers GPO sont copiés à l'identique dans legacy/modules/gpo/.
     */
    public function test_gpo_module_files_exist(): void
    {
        $base = base_path('legacy/modules/gpo');
        $this->assertFileExists($base . '/gestion_gpo.php', 'gestion_gpo.php manquant');
        $this->assertFileExists($base . '/gpo-maj.php', 'gpo-maj.php manquant');
        $this->assertFileExists($base . '/gpo-export.php', 'gpo-export.php manquant');

        // Vérifier que la copie est identique au source legacy
        $src = base_path('sambaedu/gpo');
        foreach (['gestion_gpo.php', 'gpo-maj.php', 'gpo-export.php'] as $f) {
            $this->assertSame(
                file_get_contents($src . '/' . $f),
                file_get_contents($base . '/' . $f),
                "$f doit être une copie à l'identique du legacy source"
            );
        }
    }

    // ─── AC #3 : gestion_gpo.php accessible pour computer-admin ─────────────

    /**
     * AC #3 — Un utilisateur `computer-admin` accède à gestion_gpo.php,
     * le titre « Gestion des GPO » est présent, les liens vers gpo-maj.php,
     * gpo-export.php et no_roam.php sont affichés (etab_ou vide dans setUp).
     */
    public function test_gestion_gpo_page_is_accessible_for_computer_admin(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createComputerAdmin();
        $this->actingAs($admin);

        $response = $this->get('/gpo/gestion_gpo.php');
        $response->assertStatus(200);
        $response->assertSee('Gestion des GPO', false);
        $response->assertSee('gpo-maj.php', false);
        $response->assertSee('gpo-export.php', false);
        $response->assertSee('no_roam.php', false);
    }

    // ─── AC #3 / #9 : refus sans droit admin ────────────────────────────────

    /**
     * AC #3 / #9 — Un utilisateur sans droit COMPUTER_ADMIN voit le message
     * « Vous n'avez pas les droits » (le legacy fait die() avec ce message,
     * capturé par ob_start dans executeViaBootstrap).
     *
     * Note : le die() est intercepté par le catchall (ob_start/ob_end_clean)
     * avant d'atteindre PHPUnit — pas besoin de @runInSeparateProcess.
     */
    public function test_gestion_gpo_page_denies_access_without_right(): void
    {
        $this->skipIfBootstrapUnavailable();

        $prof = $this->createNonAdmin();
        $this->actingAs($prof);

        $response = $this->get('/gpo/gestion_gpo.php');
        $response->assertStatus(200);
        $response->assertSee("Vous n'avez pas les droits", false);
    }

    /**
     * AC #9 — gpo-export.php refuse l'accès à un utilisateur sans droit.
     */
    public function test_gpo_export_page_denies_access_without_right(): void
    {
        $this->skipIfBootstrapUnavailable();

        $prof = $this->createNonAdmin();
        $this->actingAs($prof);

        $response = $this->get('/gpo/gpo-export.php');
        $response->assertStatus(200);
        $response->assertSee("Vous n'avez pas les droits", false);
    }

    // ─── AC #4 : gpo-maj.php rend les <select> de templates ─────────────────

    /**
     * AC #4 — La page gpo-maj.php rend le <SELECT name="imports[]">.
     * Note : La condition d'accès de gpo-maj.php (ligne 45 du source legacy)
     * utilise `&&` au lieu de `||` : `! have_right($config, SE_COMPUTER_ADMIN)
     * && ! empty($config['etab_ou'])`. Avec etab_ou='' (setUp), un non-admin
     * peut accéder à la page — bug legacy hérité, documenté mais hors scope
     * de correction dans cette story. Ce test vérifie uniquement le rendu
     * pour un admin.
     */
    public function test_gpo_maj_page_renders_templates_selects(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createComputerAdmin();
        $this->actingAs($admin);

        $response = $this->get('/gpo/gpo-maj.php');
        $html = $response->getContent() ?: '';

        $response->assertStatus(200);

        $this->assertStringContainsStringIgnoringCase(
            '<SELECT NAME="imports[]"',
            $html,
            'Le <SELECT name="imports[]"> doit être présent sur gpo-maj.php'
        );

        $this->assertStringContainsString(
            "Importation des GPOs dans l'AD",
            $html
        );
    }

    // ─── AC #6 : gpo-export.php rend le <select> ────────────────────────────

    /**
     * AC #6 — La page gpo-export.php rend un <SELECT NAME="exports[]">.
     * Hors VM, `gpogetlink` renvoie vide (pas de connexion samba-tool) ;
     * la page affiche quand même le <SELECT> et le titre « Export des GPO ».
     */
    public function test_gpo_export_page_renders_gpo_list(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createComputerAdmin();
        $this->actingAs($admin);

        $response = $this->get('/gpo/gpo-export.php');
        $response->assertStatus(200);

        $html = $response->getContent() ?: '';
        $this->assertStringContainsString('Export des GPO', $html);
        $this->assertStringContainsStringIgnoringCase(
            '<SELECT NAME="exports[]"',
            $html,
            'Le <SELECT name="exports[]"> doit être présent sur gpo-export.php'
        );
    }

    // ─── AC #2 : fonctions GPO disponibles après bootstrap ──────────────────

    /**
     * AC #2 — Les fonctions consommées par les 3 pages sont résolues après
     * `require_once legacy/bootstrap.php` — chacune via function_exists.
     */
    public function test_gpo_functions_are_available_after_bootstrap(): void
    {
        $this->skipIfBootstrapUnavailable();

        require_once base_path('legacy/bootstrap.php');

        $requiredFunctions = [
            // gpo.inc.php
            'list_gpo_templates',
            'list_gpo_templates_git',
            'list_gpo_templates_etab',
            'read_gpo_json',
            'gpo_version',
            'compare_list_gpo_by_name',
            'check_gpo_templates',
            'import_gpo',
            'export_gpo',
            'read_gpo_sysvol',
            // samba-tool.inc.php
            'gpocreate',
            'gpogetlink',
            // legacy/ldap.inc.php (shim)
            'search_ad',
            'have_right',
            // stubs config
            'get_config',
            // stubs admin_ui
            'admin_header_html',
            'admin_topbar_html',
            'admin_menu_html',
            'admin_footer_html',
            'header_authorize',
        ];

        foreach ($requiredFunctions as $fn) {
            $this->assertTrue(
                function_exists($fn),
                "La fonction $fn() doit être disponible après bootstrap"
            );
        }
    }

    // ─── AC #7 : CSRF + réécriture des actions ──────────────────────────────

    /**
     * AC #7 — Les formulaires rendus par gpo-maj.php et gpo-export.php
     * contiennent un `<input type="hidden" name="_token">` (CSRF) injecté
     * par cleanLegacyHtml() et leurs actions pointent vers l'URL courante.
     */
    public function test_gpo_forms_have_csrf_token_and_current_action(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createComputerAdmin();
        $this->actingAs($admin);

        foreach (['/gpo/gpo-maj.php', '/gpo/gpo-export.php'] as $url) {
            $response = $this->get($url);
            $response->assertStatus(200);
            $html = $response->getContent() ?: '';

            $this->assertStringContainsString(
                '<input type="hidden" name="_token"',
                $html,
                "Le token CSRF doit être injecté dans les formulaires de $url"
            );

            // Les actions relatives (ex: action="gpo-maj.php") doivent avoir
            // été réécrites — on vérifie qu'aucune action relative ne subsiste.
            $this->assertDoesNotMatchRegularExpression(
                '/<form[^>]*\saction\s*=\s*["\'](?!\/|https?:|' . preg_quote(url()->current(), '/') . ')[^"\']*\.php["\']/i',
                $html,
                "Aucune <form action=\"xxx.php\"> relative ne doit subsister sur $url"
            );
        }
    }

    // ─── AC #1 : embedding dans le layout SER ───────────────────────────────

    /**
     * AC #1 — Les 3 pages sont embarquées dans le layout SER : le HTML
     * legacy (doctype/html/head/body) a été retiré par cleanLegacyHtml().
     * Le layout SER Blade ré-injecte un <html> global — on vérifie que
     * le markup spécifique legacy a disparu (pas de topbar legacy, pas de
     * <head> legacy en double).
     */
    public function test_gpo_pages_are_embedded_in_ser_layout(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createComputerAdmin();
        $this->actingAs($admin);

        foreach (['/gpo/gestion_gpo.php', '/gpo/gpo-maj.php', '/gpo/gpo-export.php'] as $url) {
            $response = $this->get($url);
            $response->assertStatus(200);

            $html = $response->getContent() ?: '';

            // Le layout SER (legacy-embed.blade.php) peut contenir <html>
            // mais une seule fois — on s'assure qu'il n'y a pas deux <html>.
            $this->assertLessThanOrEqual(
                1,
                substr_count(strtolower($html), '<html'),
                "Pas plus d'un <html> ne doit apparaitre (layout SER unique) sur $url"
            );

            // La topbar legacy ne doit pas être présente (nettoyée par cleanLegacyHtml).
            $this->assertStringNotContainsString(
                'class="navbar navbar-expand-lg navbar-dark bg-primary topbar"',
                $html,
                "La topbar legacy ne doit pas être présente sur $url"
            );
        }
    }

    // ─── AC #10 : error logger propre après chargement passif ───────────────

    /**
     * AC #10 — Après un GET sur les 3 pages en mode passif (sans POST),
     * l'ErrorLoggerService ne contient aucune entrée de niveau ERROR /
     * CRITICAL / Fatal pour le channel `legacy`.
     */
    public function test_no_fatal_error_after_passive_load(): void
    {
        $this->skipIfBootstrapUnavailable();

        $admin = $this->createComputerAdmin();
        $this->actingAs($admin);

        foreach (['/gpo/gestion_gpo.php', '/gpo/gpo-maj.php', '/gpo/gpo-export.php'] as $url) {
            $this->get($url);
        }

        $fatalErrors = \Illuminate\Support\Facades\DB::table('error_logs')
            ->where('source', 'legacy')
            ->where(function ($q) {
                $q->where('message', 'like', '%Fatal%')
                    ->orWhere('message', 'like', '%CRITICAL%')
                    ->orWhere('message', 'like', '%Call to undefined function%');
            })
            ->count();

        $this->assertEquals(
            0,
            $fatalErrors,
            'Aucune erreur fatale ne doit être loguée après chargement passif des 3 pages GPO'
        );
    }
}
