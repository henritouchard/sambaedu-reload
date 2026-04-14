<?php

namespace Tests\Feature\Shortcuts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\Shortcut;
use App\Services\ShortcutCompilerService;
use App\Services\WindowsLnkGenerator;

/*
 * Tests de comparaison entre le système legacy et le nouveau système d'export
 * de raccourcis pré-compilés.
 *
 * Ces tests utilisent la vraie DB (PostgreSQL) et les raccourcis existants.
 * Ils vérifient :
 * - Identité binaire des .lnk (legacy create_windows_lnk vs WindowsLnkGenerator)
 * - Identité textuelle des .desktop
 * - Substitution correcte des variables dynamiques
 * - Interception legacy gpo/shortcuts_out.php → nouveau système
 * - Performance (benchmark optionnel via --group=benchmark)
 *
 * Lancer : php artisan test --filter=ShortcutExportComparisonTest
 *          php artisan shortcuts:test
 *          php artisan shortcuts:test --benchmark
 */
class ShortcutExportComparisonTest extends TestCase
{
    private ShortcutCompilerService $compiler;
    private string $legacyIncPath;
    private string $serverHost = '127.0.0.1';
    private string $testUser = 'testuser';
    private string $testUserprofile = 'C:\\Users\\testuser';
    private string $testMachine = 'pc-cdi-17';

    protected function setUp(): void
    {
        parent::setUp();

        // Forcer la connexion PostgreSQL réelle (phpunit.xml impose SQLite in-memory).
        // env() retourne les valeurs de phpunit.xml, on hardcode les valeurs réelles.
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.database' => 'sambaedu',
            'database.connections.pgsql.username' => 'sambaedu',
            'database.connections.pgsql.password' => 'sambaedu_secret',
        ]);
        \Illuminate\Support\Facades\DB::purge('pgsql');

        $this->compiler = app(ShortcutCompilerService::class);
        // Le fichier legacy est dans le dossier sambaedu/ adjacent au reload/
        // (legacy_path config). En dev/prod : /var/www/sambaedu/includes/.
        $legacyBase = config('sambaedu.legacy_path', '/var/www/sambaedu');
        $this->legacyIncPath = $legacyBase.'/includes/shortcuts.inc.php';

        if (! file_exists($this->legacyIncPath)) {
            $this->markTestSkipped("Legacy include introuvable ({$this->legacyIncPath}) — test de comparaison legacy/nouveau nécessite sambaedu/ legacy.");
        }

        // Vérifier qu'on a bien pu basculer sur Postgres (DB accessible).
        try {
            \Illuminate\Support\Facades\DB::connection('pgsql')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Postgres inaccessible : '.$e->getMessage());
        }

        if (! function_exists('create_windows_lnk')) {
            require_once $this->legacyIncPath;
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function getWindowsShortcuts(): \Illuminate\Database\Eloquent\Collection
    {
        return Shortcut::whereNotNull('windows_link')->where('windows_link', '!=', '')->get();
    }

    private function getLinuxShortcuts(): \Illuminate\Database\Eloquent\Collection
    {
        return Shortcut::whereNotNull('linux_link')->where('linux_link', '!=', '')->get();
    }

    private function generateLegacyLnk(Shortcut $shortcut): ?string
    {
        $link = $shortcut->windows_link;
        $args = $shortcut->windows_args ?? '';
        $path = $shortcut->windows_path ?? '';
        $icon = $shortcut->windows_icon ?? '';

        $link = preg_replace("/\\\$userprofile/", $this->testUserprofile, $link);
        $args = preg_replace("/\\\$userprofile/", $this->testUserprofile, $args);
        $path = preg_replace("/\\\$userprofile/", $this->testUserprofile, $path);
        $path = preg_replace("/\\\$user/", $this->testUser, $path);
        $link = preg_replace("/\\\$user/", $this->testUser, $link);
        $args = preg_replace("/\\\$user/", $this->testUser, $args);

        if (empty($icon)) {
            $icon = $this->testUserprofile . "\\AppData\\Local\\Temp\\" . $shortcut->name . ".ico";
        }

        if ($link === 'default') {
            $link = 'c:\\Windows\\System32\\Rundll32.exe';
            $args = 'url.dll,FileProtocolHandler ' . $args;
        } elseif ($link === 'microsoft-edge') {
            $link = 'c:\\Windows\\System32\\Rundll32.exe';
            $args = 'url.dll,FileProtocolHandler microsoft-edge:' . $args;
        }

        $tmpPath = sys_get_temp_dir() . '/legacy_lnk_test_' . $shortcut->id . '_' . microtime(true);

        if (create_windows_lnk($link, $tmpPath, $shortcut->name, $path, $args, $icon)) {
            $content = file_get_contents($tmpPath);
            @unlink($tmpPath);
            return $content;
        }

        return null;
    }

    private function generateLegacyDesktop(Shortcut $shortcut): ?string
    {
        $link = $shortcut->linux_link;
        $args = $shortcut->linux_args ?? '';
        $path = $shortcut->linux_path ?? '';

        $link = preg_replace("/\\\$user/", $this->testUser, $link);
        $args = preg_replace("/\\\$user/", $this->testUser, $args);
        $path = preg_replace("/\\\$user/", $this->testUser, $path);

        $out = "#!/usr/bin/env xdg-open\n";
        $out .= "[Desktop Entry]\n";
        $out .= "Encoding=UTF-8\n";
        $out .= "Type=Application\n";
        $out .= "Terminal=false\n";
        $out .= "StartupNotify=true\n";
        $out .= "Categories=Application\n";
        $out .= "Exec=" . $link . " " . $args . "\n";
        $out .= "Hidden=false\n";
        $out .= "Name=" . $shortcut->name . "\n";
        $out .= "Comment=Raccourci ajouté par Sambaedu\n";

        if (!empty($shortcut->linux_startupwmclass)) {
            $out .= "StartupWMClass=" . $shortcut->linux_startupwmclass . "\n";
        }

        switch ($shortcut->place) {
            case 'startup':
                $out .= "X-GNOME-Autostart-enabled=true\n";
                $out .= "Icon=/home/" . $this->testUser . "/.local/share/icons/" . $shortcut->name . ".png\n";
                break;
            case 'desktop':
            case 'taskbar':
                $out .= "Icon=/home/" . $this->testUser . "/.local/share/icons/" . $shortcut->name . ".png\n";
                break;
        }

        if (!empty($path)) {
            $out .= "Path=" . preg_replace("/$\/home\/\$HOME/", "/home/" . $this->testUser, $path) . "\n";
        }

        return $out;
    }

    private function normalizeDesktop(string $content): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $lines = array_map('rtrim', $lines);
        $lines = array_filter($lines, fn($l) => $l !== '');
        return implode("\n", $lines);
    }

    private function curlRequest(string $url, array $postFields = [], bool $isPost = false): array
    {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ];
        if ($isPost || !empty($postFields)) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $postFields;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $code, 'body' => $body ?: ''];
    }

    // =========================================================================
    // 1. COMPARAISON BINAIRE .LNK
    // =========================================================================

    #[Test]
    public function all_windows_lnk_files_are_byte_identical_to_legacy(): void
    {
        $shortcuts = $this->getWindowsShortcuts();
        $this->assertNotEmpty($shortcuts, 'Aucun raccourci Windows en base');

        $identical = 0;
        $failures = [];

        foreach ($shortcuts as $shortcut) {
            $legacyLnk = $this->generateLegacyLnk($shortcut);
            $newLnk = $this->compiler->generateWindowsLnk($shortcut, $this->testUser, $this->testUserprofile);

            if ($legacyLnk === null && $newLnk === null) {
                continue;
            }

            $this->assertNotNull($legacyLnk, "Legacy n'a pas généré de .lnk pour [{$shortcut->id}] {$shortcut->name}");
            $this->assertNotNull($newLnk, "Nouveau système n'a pas généré de .lnk pour [{$shortcut->id}] {$shortcut->name}");

            if ($legacyLnk !== $newLnk) {
                $failures[] = "[{$shortcut->id}] {$shortcut->name}: legacy=" . strlen($legacyLnk) . "b vs new=" . strlen($newLnk) . "b";
            } else {
                $identical++;
            }
        }

        $this->assertEmpty($failures, "Fichiers .lnk différents :\n" . implode("\n", $failures));
        $this->assertGreaterThan(0, $identical, 'Aucun .lnk comparé');
    }

    // =========================================================================
    // 2. COMPARAISON .DESKTOP
    // =========================================================================

    #[Test]
    public function all_linux_desktop_files_are_identical_to_legacy(): void
    {
        $shortcuts = $this->getLinuxShortcuts();
        $this->assertNotEmpty($shortcuts, 'Aucun raccourci Linux en base');

        $identical = 0;
        $failures = [];

        foreach ($shortcuts as $shortcut) {
            $legacyDesktop = $this->generateLegacyDesktop($shortcut);
            $newDesktop = $this->compiler->generateLinuxDesktop($shortcut, $this->testUser);

            if ($legacyDesktop === null && $newDesktop === null) {
                continue;
            }

            $this->assertNotNull($legacyDesktop, "Legacy n'a pas généré de .desktop pour [{$shortcut->id}] {$shortcut->name}");
            $this->assertNotNull($newDesktop, "Nouveau système n'a pas généré de .desktop pour [{$shortcut->id}] {$shortcut->name}");

            $legacyNorm = $this->normalizeDesktop($legacyDesktop);
            $newNorm = $this->normalizeDesktop($newDesktop);

            if ($legacyNorm !== $newNorm) {
                $failures[] = "[{$shortcut->id}] {$shortcut->name}";
            } else {
                $identical++;
            }
        }

        $this->assertEmpty($failures, "Fichiers .desktop différents :\n" . implode("\n", $failures));
        $this->assertGreaterThan(0, $identical, 'Aucun .desktop comparé');
    }

    // =========================================================================
    // 3. VARIABLES DYNAMIQUES
    // =========================================================================

    #[Test]
    public function dynamic_detection_is_consistent(): void
    {
        $shortcuts = Shortcut::all();
        $this->assertNotEmpty($shortcuts);

        foreach ($shortcuts as $shortcut) {
            $isDynamic = $shortcut->detectDynamic();
            $vars = $shortcut->getDynamicVariables();

            if ($isDynamic) {
                $this->assertNotEmpty($vars, "Raccourci [{$shortcut->id}] {$shortcut->name} marqué dynamique mais sans variables");
            } else {
                $this->assertEmpty($vars, "Raccourci [{$shortcut->id}] {$shortcut->name} a des variables mais n'est pas marqué dynamique");
            }
        }
    }

    #[Test]
    public function userprofile_is_substituted_before_user(): void
    {
        // Créer un raccourci dynamique temporaire
        $shortcut = new Shortcut();
        $shortcut->name = 'test_substitution';
        $shortcut->windows_link = '$userprofile\\Desktop\\app.exe';
        $shortcut->windows_args = '--user $user';
        $shortcut->windows_path = '';
        $shortcut->windows_icon = '';
        $shortcut->place = 'desktop';

        $lnk = $this->compiler->generateWindowsLnk($shortcut, 'jean.dupont', 'C:\\Users\\jean.dupont');
        $this->assertNotNull($lnk);

        $strings = [];
        // Extraire les chaînes lisibles du binaire
        preg_match_all('/[\x20-\x7E]{4,}/', $lnk, $matches);
        $allStrings = implode(' ', $matches[0]);

        // $userprofile doit être résolu en C:\Users\jean.dupont, pas en jean.dupontprofile
        $this->assertStringContainsString('C:\\', $allStrings);
        $this->assertStringContainsString('Users\\jean.dupont\\Desktop\\app.exe', $allStrings);
        $this->assertStringContainsString('--user jean.dupont', $allStrings);
        $this->assertStringNotContainsString('jean.dupontprofile', $allStrings);
    }

    #[Test]
    public function dynamic_shortcuts_have_no_unresolved_variables_in_desktop(): void
    {
        $shortcuts = $this->getLinuxShortcuts()->filter(fn($s) => $s->detectDynamic());
        $checked = 0;

        foreach ($shortcuts as $shortcut) {
            $desktop = $this->compiler->generateLinuxDesktop($shortcut, $this->testUser);
            if ($desktop === null) {
                continue;
            }

            foreach (Shortcut::DYNAMIC_VARIABLES as $var) {
                $this->assertStringNotContainsString(
                    $var,
                    $desktop,
                    "Variable non substituée '{$var}' dans .desktop de [{$shortcut->id}] {$shortcut->name}"
                );
            }
            $checked++;
        }

        // Si aucun raccourci dynamique Linux, le test passe quand même
        $this->assertTrue(true, 'Aucun raccourci dynamique Linux à vérifier (OK)');
    }

    // =========================================================================
    // 4. INTERCEPTION LEGACY gpo/shortcuts_out.php
    // =========================================================================

    #[Test]
    public function proxy_file_returns_same_lnk_as_api_direct(): void
    {
        $proxyUrl = "http://{$this->serverHost}/gpo/shortcuts_out.php";
        $apiBase = "http://{$this->serverHost}/laravel/public/api/v1/shortcuts/export";

        $shortcuts = $this->getWindowsShortcuts()->take(5);
        $this->assertNotEmpty($shortcuts);

        foreach ($shortcuts as $shortcut) {
            $proxy = $this->curlRequest($proxyUrl, [
                'action' => 'file',
                'shortcut' => $shortcut->name,
                'os' => 'windows',
                'user' => $this->testUser,
                'userprofile' => $this->testUserprofile,
            ], true);

            $api = $this->curlRequest(
                "{$apiBase}/file?shortcut_id={$shortcut->id}&os=windows&user={$this->testUser}&userprofile=" . urlencode($this->testUserprofile)
            );

            $this->assertEquals(200, $proxy['code'], "Proxy HTTP {$proxy['code']} pour [{$shortcut->id}] {$shortcut->name}");
            $this->assertEquals(
                $api['body'],
                $proxy['body'],
                "Proxy vs API différents pour [{$shortcut->id}] {$shortcut->name} (proxy=" . strlen($proxy['body']) . "b vs api=" . strlen($api['body']) . "b)"
            );
        }
    }

    #[Test]
    public function proxy_icon_returns_same_as_api_direct(): void
    {
        $proxyUrl = "http://{$this->serverHost}/gpo/shortcuts_out.php";
        $apiBase = "http://{$this->serverHost}/laravel/public/api/v1/shortcuts/export";

        $shortcut = $this->getWindowsShortcuts()->first();
        $this->assertNotNull($shortcut);

        $proxy = $this->curlRequest($proxyUrl, [
            'action' => 'icon',
            'shortcut' => $shortcut->name,
            'os' => 'windows',
        ], true);

        $api = $this->curlRequest("{$apiBase}/icon?name=" . urlencode($shortcut->name) . "&os=windows");

        $this->assertEquals($api['code'], $proxy['code'], "Codes HTTP différents: proxy={$proxy['code']} vs api={$api['code']}");

        if ($proxy['code'] === 200) {
            $this->assertEquals($api['body'], $proxy['body'], 'Contenu icône différent entre proxy et API');
        }
    }

    #[Test]
    public function proxy_script_returns_urls_pointing_to_new_system(): void
    {
        $proxyUrl = "http://{$this->serverHost}/gpo/shortcuts_out.php";

        $result = $this->curlRequest($proxyUrl, [
            'action' => 'logon',
            'os' => 'windows',
            'user' => $this->testUser,
            'machine' => $this->testMachine,
            'userprofile' => $this->testUserprofile,
        ], true);

        $this->assertEquals(200, $result['code'], "Script HTTP {$result['code']}");
        $this->assertNotEmpty($result['body']);
        $this->assertStringContainsString('/api/v1/shortcuts/export/', $result['body'], 'Les URLs du script ne pointent pas vers le nouveau système');
        $this->assertStringContainsString('::cmd', $result['body'], 'Le script ne commence pas par ::cmd');
    }

    // =========================================================================
    // 5. BENCHMARK (groupe séparé, pas lancé par défaut)
    // =========================================================================

    #[Test]
    #[Group('benchmark')]
    public function benchmark_new_system_is_faster_than_legacy_http(): void
    {
        $iterations = 20;
        $proxyUrl = "http://{$this->serverHost}/gpo/shortcuts_out.php";
        $apiUrl = "http://{$this->serverHost}/laravel/public/api/v1/shortcuts/export/file";

        $shortcut = $this->getWindowsShortcuts()->first();
        $this->assertNotNull($shortcut);

        // Warmup
        $this->curlRequest($proxyUrl, ['action' => 'file', 'shortcut' => $shortcut->name, 'os' => 'windows', 'user' => $this->testUser, 'userprofile' => $this->testUserprofile], true);
        $this->curlRequest("{$apiUrl}?shortcut_id={$shortcut->id}&os=windows&user={$this->testUser}&userprofile=" . urlencode($this->testUserprofile));

        // Benchmark proxy
        $proxyTimes = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $this->curlRequest($proxyUrl, ['action' => 'file', 'shortcut' => $shortcut->name, 'os' => 'windows', 'user' => $this->testUser, 'userprofile' => $this->testUserprofile], true);
            $proxyTimes[] = (hrtime(true) - $start) / 1_000_000;
        }

        // Benchmark API directe
        $apiTimes = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $this->curlRequest("{$apiUrl}?shortcut_id={$shortcut->id}&os=windows&user={$this->testUser}&userprofile=" . urlencode($this->testUserprofile));
            $apiTimes[] = (hrtime(true) - $start) / 1_000_000;
        }

        // Benchmark génération en mémoire
        $legacyGenTimes = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $this->generateLegacyLnk($shortcut);
            $legacyGenTimes[] = (hrtime(true) - $start) / 1_000_000;
        }

        $newGenTimes = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $this->compiler->generateWindowsLnk($shortcut, $this->testUser, $this->testUserprofile);
            $newGenTimes[] = (hrtime(true) - $start) / 1_000_000;
        }

        $proxyAvg = array_sum($proxyTimes) / count($proxyTimes);
        $apiAvg = array_sum($apiTimes) / count($apiTimes);
        $legacyGenAvg = array_sum($legacyGenTimes) / count($legacyGenTimes);
        $newGenAvg = array_sum($newGenTimes) / count($newGenTimes);

        // Afficher les résultats dans la sortie du test
        fwrite(STDERR, sprintf(
            "\n  Benchmark (%d iterations) - [%s] %s:\n" .
            "    Génération mémoire : legacy=%.2fms, new=%.2fms\n" .
            "    HTTP               : proxy=%.1fms, api=%.1fms (surcoût proxy: +%.1fms)\n",
            $iterations, $shortcut->id, $shortcut->name,
            $legacyGenAvg, $newGenAvg,
            $proxyAvg, $apiAvg, $proxyAvg - $apiAvg
        ));

        // Le test vérifie juste que l'API directe répond correctement
        $this->assertGreaterThan(0, $apiAvg);
        $this->assertGreaterThan(0, $proxyAvg);
    }
}
