<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Test Feature : module legacy printers (Tier 3) dans legacy/modules/printers/.
 *
 * Vérifie que le module est copié, accessible via le catchall,
 * que les shims LDAP fonctionnent (have_right, SE_ADMIN, SE_COMPUTER_ADMIN),
 * que la sortie raw de out_printers.php est correctement gérée (Content-Type text/plain),
 * et que les exec CUPS échouent gracefully si CUPS n'est pas installé.
 *
 * Story : 1bis-15-module-printers
 */
class LegacyModulePrintersTest extends TestCase
{
    private ?string $originalIncludePath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Désactiver le blocage des routes migrées pour les tests
        Config::set('sambaedu.block_migrated_routes', false);

        // Préparer l'include_path (idempotent — le bootstrap le fait aussi).
        // Snapshot pour restauration en tearDown (évite la pollution d'état
        // entre tests — review 1bis-15 #10).
        $this->originalIncludePath = get_include_path();
        $stubsPath = base_path('legacy/stubs');
        $legacyIncludesPath = config('sambaedu.legacy_path', '/var/www/sambaedu') . '/includes';
        $currentPath = $this->originalIncludePath;
        if (is_dir($stubsPath) && !str_contains($currentPath, $stubsPath)) {
            $currentPath = $stubsPath . PATH_SEPARATOR . $currentPath;
        }
        if (is_dir($legacyIncludesPath) && !str_contains($currentPath, $legacyIncludesPath)) {
            $currentPath .= PATH_SEPARATOR . $legacyIncludesPath;
        }
        set_include_path($currentPath);

        // Créer les tables nécessaires en mémoire (SQLite :memory: ne migre pas automatiquement)
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

        // Reset $_SESSION pour éviter les fuites entre tests
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        \App\Models\LegacyCatchallLog::query()->delete();
        \Illuminate\Support\Facades\DB::table('error_logs')->delete();

        // Restaurer l'include_path original (review 1bis-15 #10)
        if ($this->originalIncludePath !== null) {
            set_include_path($this->originalIncludePath);
        }

        parent::tearDown();
    }

    // ─── Tâche 1 : Structure du module ──────────────────────────────────────

    /**
     * Les 11 fichiers PHP du module printers sont présents dans legacy/modules/printers/.
     */
    public function test_module_files_exist(): void
    {
        $base = base_path('legacy/modules/printers');

        $expectedFiles = [
            'add_driver.php',
            'add_printer.php',
            'config_printer.php',
            'cups_driver.php',
            'delete_printer_choice.php',
            'delete_printer.php',
            'list_printers.php',
            'out_printers.php',
            'printer_jobs.php',
            'server_CUPS.php',
            'view_printers.php',
        ];

        $this->assertDirectoryExists($base, 'Le dossier legacy/modules/printers/ doit exister');

        foreach ($expectedFiles as $file) {
            $this->assertFileExists($base . '/' . $file, "Le fichier {$file} doit être présent dans legacy/modules/printers/");
        }

        // Compter les fichiers PHP (doit être exactement 11)
        $phpFiles = glob($base . '/*.php');
        $this->assertCount(11, $phpFiles, 'Le module doit contenir exactement 11 fichiers PHP');
    }

    // ─── Tâche 5 : Sortie raw out_printers.php ──────────────────────────────

    /**
     * isHtmlWebPage() retourne false pour text/plain → out_printers.php est servi raw.
     */
    public function test_catchall_does_not_wrap_text_plain_in_layout(): void
    {
        $controller = new \App\Http\Controllers\LegacyCatchallController();
        $method = new \ReflectionMethod($controller, 'isHtmlWebPage');
        $method->setAccessible(true);

        // text/plain avec contenu CUPS → ne doit PAS être wrappé
        $cupsContent = "#!/bin/bash\nlpadmin -p cups-pdf -E -v cups-pdf:/ -m CUPS-PDF.ppd\n";
        $this->assertFalse(
            $method->invoke($controller, 'text/plain', $cupsContent),
            'text/plain ne doit pas être considéré comme une page HTML (out_printers.php)'
        );

        // text/html avec contenu de page → doit être wrappé
        $htmlContent = '<div><h1>Imprimantes</h1><form action="config_printer.php">...</form></div>';
        $this->assertTrue(
            $method->invoke($controller, 'text/html; charset=UTF-8', $htmlContent),
            'text/html avec marqueurs HTML doit être considéré comme une page web (list_printers.php)'
        );
    }

    /**
     * out_printers.php est accessible via le catchall sans fatal error.
     * La réponse contient un script bash (#!/bin/bash) sans layout SER wrappé.
     *
     * NOTE : En contexte PHPUnit, headers_list() ne capture pas les header() PHP
     * (limitation PHP CLI). On vérifie donc le contenu de la réponse plutôt que le
     * Content-Type. L'AC2 est validée via isHtmlWebPage() qui détecte text/plain
     * (test test_catchall_does_not_wrap_text_plain_in_layout).
     */
    public function test_out_printers_serves_plain_text_raw(): void
    {
        $base = base_path('legacy/modules/printers');
        if (!is_dir($base)) {
            $this->markTestSkipped('Module printers non copié dans legacy/modules/printers/');
        }

        // Smoke sans paramètre (ligne shebang seule, vérifie que cups_client_command()
        // se charge sans fatal).
        $response = $this->get('/printers/out_printers.php');
        $response->assertSuccessful();

        $content = $response->getContent();
        $this->assertStringContainsString('#!/bin/bash', $content,
            'out_printers.php doit générer un script bash commençant par #!/bin/bash');

        // Vérifier qu'il n'y a PAS de layout SER dans la réponse
        // (pas de DOCTYPE, pas de balise html du layout)
        $this->assertStringNotContainsString('<html', strtolower($content),
            'out_printers.php ne doit pas contenir de balise <html> (pas de layout SER)');
        $this->assertStringNotContainsString('id="app"', $content,
            'out_printers.php ne doit pas être wrappé dans le layout SER (div#app)');

        // Test renforcé (review 1bis-15 #9) : appel avec paramètres réels pour
        // prouver que cups_client_command() produit effectivement une commande
        // CUPS (lpadmin/lpstat) et pas seulement le shebang hardcodé.
        $response2 = $this->get('/printers/out_printers.php?printer=cups-pdf&action=add&machine=test-poste');
        $response2->assertSuccessful();

        $content2 = $response2->getContent();
        $this->assertStringContainsString('#!/bin/bash', $content2);
        $this->assertMatchesRegularExpression(
            '/lpadmin|lpstat|lpoptions|cupsenable|cancel/',
            $content2,
            'out_printers.php avec action=add doit produire une commande CUPS client (lpadmin/lpstat/...)'
        );
    }

    // ─── Tâche 6 : Tests Feature smoke test ─────────────────────────────────

    /**
     * list_printers.php est accessible via le catchall sans fatal error PHP.
     */
    public function test_list_printers_loads_without_fatal(): void
    {
        $base = base_path('legacy/modules/printers');
        if (!is_dir($base)) {
            $this->markTestSkipped('Module printers non copié dans legacy/modules/printers/');
        }

        $response = $this->get('/printers/list_printers.php');
        $response->assertSuccessful();

        // Vérifier qu'il n'y a pas d'erreur fatale PHP dans la réponse
        $content = $response->getContent();
        $this->assertStringNotContainsString('Fatal error', $content,
            'list_printers.php ne doit pas produire de Fatal error PHP');
        $this->assertStringNotContainsString('Parse error', $content,
            'list_printers.php ne doit pas produire de Parse error PHP');
    }

    /**
     * view_printers.php contient un die() si have_right(SE_COMPUTER_ADMIN) est false.
     * Ce comportement est normal en production (Laravel auth contrôle l'accès en amont).
     *
     * En contexte test PHPUnit, die() tuerait le process. On vérifie ici uniquement
     * que le fichier PHP est syntaxiquement correct (parseable).
     */
    public function test_view_printers_file_is_syntactically_valid(): void
    {
        $file = base_path('legacy/modules/printers/view_printers.php');
        $this->assertFileExists($file, 'view_printers.php doit être présent dans le module');

        // Vérifier la syntaxe PHP sans exécuter le fichier
        $output = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors detected', $output,
            'view_printers.php doit être syntaxiquement valide : ' . $output);
    }

    /**
     * config_printer.php contient un die() si have_right(SE_ADMIN) est false.
     * Ce comportement est normal en production (Laravel auth contrôle l'accès en amont).
     *
     * En contexte test PHPUnit, die() tuerait le process. On vérifie ici uniquement
     * que le fichier PHP est syntaxiquement correct (parseable).
     */
    public function test_config_printer_file_is_syntactically_valid(): void
    {
        $file = base_path('legacy/modules/printers/config_printer.php');
        $this->assertFileExists($file, 'config_printer.php doit être présent dans le module');

        $output = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors detected', $output,
            'config_printer.php doit être syntaxiquement valide : ' . $output);
    }

    /**
     * server_CUPS.php est accessible via le catchall sans fatal error PHP.
     * Si CUPS n'est pas installé, la réponse doit être graceful (statut 200, pas d'erreur PHP fatale).
     */
    public function test_server_cups_loads_without_fatal(): void
    {
        $base = base_path('legacy/modules/printers');
        if (!is_dir($base)) {
            $this->markTestSkipped('Module printers non copié dans legacy/modules/printers/');
        }

        $response = $this->get('/printers/server_CUPS.php');
        $response->assertSuccessful();

        $content = $response->getContent();
        $this->assertStringNotContainsString('Fatal error', $content,
            'server_CUPS.php ne doit pas produire de Fatal error PHP');
        $this->assertStringNotContainsString('Parse error', $content,
            'server_CUPS.php ne doit pas produire de Parse error PHP');
    }

    /**
     * cups_driver.php est accessible via le catchall sans fatal error PHP.
     * Les exec lpinfo échouent gracefully si CUPS n'est pas installé.
     */
    public function test_cups_driver_loads_without_fatal(): void
    {
        $base = base_path('legacy/modules/printers');
        if (!is_dir($base)) {
            $this->markTestSkipped('Module printers non copié dans legacy/modules/printers/');
        }

        $response = $this->get('/printers/cups_driver.php');
        $response->assertSuccessful();

        $content = $response->getContent();
        $this->assertStringNotContainsString('Fatal error', $content,
            'cups_driver.php ne doit pas produire de Fatal error PHP');
    }

    // ─── Tâche 3 : Shim LDAP have_right + constantes ────────────────────────

    /**
     * SE_ADMIN et SE_COMPUTER_ADMIN sont définies (via legacy/ldap.inc.php).
     * have_right() ne lève pas de fatal error avec ces constantes.
     */
    public function test_have_right_se_admin_does_not_crash(): void
    {
        require_once base_path('legacy/ldap.inc.php');

        $this->assertTrue(defined('SE_ADMIN'),
            'La constante SE_ADMIN doit être définie dans legacy/ldap.inc.php');
        $this->assertTrue(defined('SE_COMPUTER_ADMIN'),
            'La constante SE_COMPUTER_ADMIN doit être définie dans legacy/ldap.inc.php');

        // Vérifier que les valeurs sont des entiers (bitmask)
        $this->assertIsInt(SE_ADMIN, 'SE_ADMIN doit être un entier bitmask');
        $this->assertIsInt(SE_COMPUTER_ADMIN, 'SE_COMPUTER_ADMIN doit être un entier bitmask');

        // SE_ADMIN = 0xFFFF = 65535
        $this->assertEquals(0xFFFF, SE_ADMIN,
            'SE_ADMIN doit valoir 0xFFFF (65535)');

        // have_right() ne doit pas lever d'exception avec SE_ADMIN
        $config = $GLOBALS['config'] ?? [];
        $result = have_right($config, SE_ADMIN);
        $this->assertIsBool($result,
            'have_right($config, SE_ADMIN) doit retourner un booléen sans fatal error');
    }

    /**
     * have_right() ne lève pas de fatal error avec SE_COMPUTER_ADMIN.
     */
    public function test_have_right_se_computer_admin_does_not_crash(): void
    {
        require_once base_path('legacy/ldap.inc.php');

        $this->assertTrue(defined('SE_COMPUTER_ADMIN'),
            'La constante SE_COMPUTER_ADMIN doit être définie');

        $config = $GLOBALS['config'] ?? [];
        $result = have_right($config, SE_COMPUTER_ADMIN);
        $this->assertIsBool($result,
            'have_right($config, SE_COMPUTER_ADMIN) doit retourner un booléen sans fatal error');
    }

    // ─── Tâche 6 : Error logger propre ──────────────────────────────────────

    /**
     * AC6 — Après chargement d'une page du module, aucune erreur fatale legacy
     * concernant une route `/printers/*` n'apparaît dans error_logs.
     *
     * NB (review 1bis-15 #5) : l'AC mentionnait initialement un tag `printers`
     * dans l'error logger, or `ErrorLoggerService` n'utilise partout que le tag
     * `legacy`. Le test est donc reformulé pour vérifier qu'aucune entrée
     * `source='legacy'` avec un `message LIKE '%/printers/%'` ET `LIKE '%Fatal%'`
     * n'a été enregistrée par le chargement. Pour éliminer la tautologie (0
     * ligne avant = 0 ligne après), on vérifie l'existence réelle du chemin
     * scanné et on fait une assertion négative ciblée sur les logs du module.
     */
    public function test_ac6_no_fatal_logged_for_printers_module(): void
    {
        $base = base_path('legacy/modules/printers');
        if (!is_dir($base)) {
            $this->markTestSkipped('Module printers non copié dans legacy/modules/printers/');
        }

        // Baseline : aucune fatal /printers/* initialement (tearDown des autres
        // tests efface error_logs, mais on s'assure explicitement ici).
        \Illuminate\Support\Facades\DB::table('error_logs')->delete();

        // Charger plusieurs pages du module pour élargir la couverture
        $this->get('/printers/list_printers.php')->assertSuccessful();
        $this->get('/printers/server_CUPS.php')->assertSuccessful();
        $this->get('/printers/cups_driver.php')->assertSuccessful();

        // Assertion non-tautologique : on cherche les erreurs fatales propres
        // aux pages /printers/* (chemin inclus dans le message par le catchall).
        $printersFatal = \Illuminate\Support\Facades\DB::table('error_logs')
            ->where('source', 'legacy')
            ->where(function ($q) {
                $q->where('message', 'like', '%Fatal%')
                  ->orWhere('message', 'like', '%Parse error%')
                  ->orWhere('message', 'like', '%Cannot redeclare%');
            })
            ->where('message', 'like', '%/printers/%')
            ->get();

        $this->assertCount(0, $printersFatal,
            'Aucune erreur fatale ne doit être loguée pour les pages /printers/*. '
            . 'Logs trouvés : ' . $printersFatal->pluck('message')->toJson()
        );
    }
}
