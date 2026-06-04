<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Services\ApplicationScriptsAssembler;
use App\Gpo\Services\ApplicationTemplatesScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsScriptParity;
use Tests\TestCase;

/**
 * Story 17.4 — Volets 1, 4, 5 (AC1.1-AC1.3, AC4.1, AC4.2, AC5.1).
 *
 * Tests de parité bytes + assertions ciblées par fragment pour les
 * **5 scripts critiques** du package Debian `sambaedu` (audit Section A 17.1) :
 *
 *  1. `wpkg/startup.windows`       — startup ROBOCOPY déploiement SambaEdu
 *  2. `wallpaper/logon.windows`    — logon wallpaper + SE4INSTALL_NAME (risque bloquant)
 *  3. `shortcuts/logon.windows`    — logon raccourcis (curl shortcuts_out.php)
 *  4. `firefox/logon.windows`      — logon profil Firefox Windows (heredoc profiles.ini)
 *  5. `firefox/logon.linux`        — logon profil Firefox Linux (bash, heredoc, UTF-8)
 *
 * ─────────────────────────────────────────────────────────────────────────
 * IMPORTANT — granularité de la parité (post-review P1) :
 *
 * `ApplicationScriptsAssembler::assemble($info, $scripts)` ne sait PAS isoler
 * un script : pour un contexte donné (`action`/`os`) il **concatène tous les
 * fragments applicables** dans le même blob (`$out['cmd']` / `$out['bash']`).
 * Conséquence directe :
 *
 *  - La **parité byte** est donc *globale par contexte distinct*, pas par
 *    script individuel. Les 3 fragments `wallpaper`/`shortcuts`/`firefox`
 *    `logon.windows` produisent le MÊME blob `logon/windows` → un seul test de
 *    parité byte (`it_matches_legacy_bytes_for_windows_logon_context`) couvre
 *    les trois. Capturer 3 fixtures byte-identiques n'aurait prouvé qu'une
 *    seule chose 3 fois (review P1 🔴) — fixtures redondantes supprimées.
 *  - `startup/windows` est byte-identique à la fixture 17.2
 *    `windows_startup_firewall` (même blob startup/system/windows, vérifié
 *    md5 normalisé) → parité couverte par 17.2 ; ici on ne garde que
 *    l'**assertion ROBOCOPY ligne complète** (P2/P4).
 *  - L'**isolation par script** est assurée par des **assertions ciblées de
 *    fragment** : chaque test prouve la présence + substitution du marqueur
 *    distinctif du script (heredoc `profiles.ini`, `taskkill … se4install`,
 *    `shortcuts_out.php`, ligne ROBOCOPY).
 *  - `logon/linux` (`firefox/logon.linux`) est le seul contexte avec une
 *    parité byte *isolée et nouvelle* (distinct de tout contexte 17.2) → fixture
 *    + parité byte conservées.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * **Source des scripts (post-review P3)** : `applicationsScriptsSource()` pointe
 * sur le **snapshot portable** `tests/Fixtures/Gpo/applications/_package_snapshot/`
 * (byte-identique au paquet `sambaedu 4.17.285`). Les tests de parité tournent
 * donc en CI sans dépendre du chemin système `/usr/share/sambaedu/applications/`.
 *
 * **Fixtures** : `tests/Fixtures/Gpo/applications/<scenario>/expected.<ext>`.
 * Procédure de (re)capture : `tests/Fixtures/Gpo/applications/README.md`.
 *
 * @see ApplicationsScriptsByteParityTest Story 17.2 — pattern parité original.
 */
class ApplicationsScriptsCriticalParityTest extends TestCase
{
    use AssertsScriptParity;

    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/Gpo/applications';

    private const LOCAL_PATH = '/etc/sambaedu/applications/';

    /**
     * Contexte `$info` iso-legacy pour `logon/windows` (couvre wallpaper,
     * shortcuts, firefox — même blob assemblé). `id` aligné sur la fixture
     * `windows_logon_wallpaper`.
     *
     * @return array<string,mixed>
     */
    private function windowsLogonInfo(string $idSeed = 'testuserpc-testlogonwallpaper'): array
    {
        return [
            'os'                 => 'windows',
            'action'             => 'logon',
            'interpreter'        => 'cmd',
            'context'            => '',
            'remote'             => false,
            'machine'            => [
                'cn'       => 'pc-test',
                'dn'       => 'cn=pc-test,ou=salle01,ou=parcs,dc=localdev,dc=fr',
                'memberof' => ['cn=salle01,ou=parcs,dc=localdev,dc=fr'],
            ],
            'user'               => ['cn' => 'testuser', 'memberof' => []],
            'userprofile'        => 'C:\\Users\\testuser',
            'salle'              => 'salle01',
            'parcs'              => ['salle01'],
            'list'               => ['testuser', 'salle01', 'pc-test'],
            'liste_applications' => [],
            'admin'              => 0,
            'id'                 => md5($idSeed),
            'speed'              => 0,
        ];
    }

    /**
     * Contexte `$info` iso-legacy pour `startup/system/windows` (couvre wpkg
     * ROBOCOPY + firewall — même blob assemblé que 17.2).
     *
     * @return array<string,mixed>
     */
    private function windowsStartupInfo(): array
    {
        return [
            'os'                 => 'windows',
            'action'             => 'startup',
            'interpreter'        => 'cmd',
            'context'            => 'system',
            'remote'             => false,
            'machine'            => [
                'cn'       => 'pc-test',
                'dn'       => 'cn=pc-test,ou=salle01,ou=parcs,dc=localdev,dc=fr',
                'memberof' => ['cn=salle01,ou=parcs,dc=localdev,dc=fr'],
            ],
            'user'               => ['cn' => 'pc-test', 'memberof' => []],
            'userprofile'        => 'C:\\Users\\Default',
            'salle'              => 'salle01',
            'parcs'              => ['salle01'],
            'list'               => ['pc-test', 'salle01'],
            'liste_applications' => [],
            'admin'              => 0,
            'id'                 => md5('pc-testpc-teststartupwpkg'),
            'speed'              => 0,
        ];
    }

    /**
     * Contexte `$info` iso-legacy pour `logon/linux` (firefox).
     *
     * @return array<string,mixed>
     */
    private function linuxLogonInfo(): array
    {
        return [
            'os'                 => 'linux',
            'action'             => 'logon',
            'interpreter'        => 'bash',
            'context'            => '',
            'remote'             => false,
            'machine'            => [
                'cn'       => 'pc-test',
                'dn'       => 'cn=pc-test,ou=salle01,dc=localdev,dc=fr',
                'memberof' => [],
            ],
            'user'               => ['cn' => 'testuser', 'memberof' => []],
            'userprofile'        => '',
            'salle'              => 'salle01',
            'parcs'              => [],
            'list'               => ['testuser', 'salle01', 'pc-test'],
            'liste_applications' => [],
            'admin'              => 0,
            'id'                 => md5('testuserpc-testlogonlinuxfirefox'),
            'speed'              => 0,
        ];
    }

    /**
     * Assemble le contexte donné depuis le snapshot portable (ou skip si absent).
     *
     * @param  array<string,mixed>  $info
     * @return array<string,string>
     */
    private function assembleFromSnapshot(array $info): array
    {
        $source = $this->applicationsScriptsSource();
        if ($source === null) {
            self::markTestSkipped(
                'Aucune source de scripts disponible (snapshot _package_snapshot/ ni /usr/share/sambaedu/applications/). '
                . 'Le snapshot portable devrait être versionné — voir README.md.'
            );
        }

        $this->configureForFixtures();
        $assembler = $this->makeAssembler();
        $scanner   = new ApplicationTemplatesScanner();
        $scripts   = $scanner->scan($source, self::LOCAL_PATH);

        return $assembler->assemble($info, $scripts);
    }

    private function fixture(string $relative): string
    {
        return self::FIXTURES_DIR . '/' . $relative;
    }

    // =========================================================================
    // Volet 1 — Parité bytes par CONTEXTE distinct (AC1.2)
    // =========================================================================

    /**
     * AC1.2 — Parité byte du contexte `logon/windows` (post-review P1).
     *
     * Couvre simultanément `wallpaper/logon.windows`, `shortcuts/logon.windows`
     * et `firefox/logon.windows` : `assemble()` produit un blob unique pour ce
     * contexte. La fixture `windows_logon_wallpaper` est la référence byte du
     * blob complet. L'isolation par script est assurée par les assertions
     * ciblées (heredoc firefox, taskkill wallpaper, shortcuts_out shortcuts).
     */
    #[Test]
    public function it_matches_legacy_bytes_for_windows_logon_context(): void
    {
        $fixture = $this->fixture('windows_logon_wallpaper/expected.cmd');
        if (! is_file($fixture)) {
            self::markTestSkipped('Fixture non capturée : ' . $fixture . '. Voir README.md.');
        }

        $out      = $this->assembleFromSnapshot($this->windowsLogonInfo());
        $actual   = $out['cmd'];
        $expected = (string) file_get_contents($fixture);

        $this->assertScriptParity($expected, $actual);
    }

    /**
     * AC1.2 / AC5.1 — Parité byte du contexte `logon/linux` (firefox).
     *
     * Seule parité byte *isolée et nouvelle* de 17.4 (distinct de tout contexte
     * 17.2). Valide aussi charset UTF-8 + absence de CRLF Windows.
     */
    #[Test]
    public function it_matches_legacy_bytes_for_linux_logon_firefox(): void
    {
        $fixture = $this->fixture('linux_logon_firefox/expected.sh');
        if (! is_file($fixture)) {
            self::markTestSkipped('Fixture non capturée : ' . $fixture . '. Voir README.md.');
        }

        $out      = $this->assembleFromSnapshot($this->linuxLogonInfo());
        $actual   = $out['bash'];
        $expected = (string) file_get_contents($fixture);

        $this->assertScriptParity($expected, $actual);

        // AC5.1 — charset UTF-8 + absence de CRLF Windows.
        self::assertFalse(
            str_contains($actual, "\r\n"),
            'Le script Linux ne doit pas contenir de CRLF Windows (\\r\\n).'
        );
        self::assertNotFalse(
            mb_detect_encoding($actual, ['UTF-8'], strict: true),
            'Le script Linux doit être encodé en UTF-8.'
        );
    }

    // =========================================================================
    // Volet 1 (bis) — Assertions ciblées par fragment (isolation par script)
    // =========================================================================

    /**
     * AC1.3 / P4 — Fragment `wpkg/startup.windows` : ligne ROBOCOPY complète.
     *
     * Le blob `startup/windows` est byte-identique à la fixture 17.2
     * `windows_startup_firewall` (parité couverte là-bas, P2) ; ici on isole le
     * fragment wpkg via la **ligne ROBOCOPY complète** (source `SambaEdu` +
     * destination `%ProgramFiles%\SambaEdu`).
     *
     * Historique source : en 4.17.285 le script utilisait `install\os\netinst`
     * (VM = référence légitime, validé Henri P4 — l'audit H.3 mentionnait déjà
     * `SambaEdu`). Le paquet 4.17.695 (recapture 2026-06-04) est passé à
     * `install\os\SambaEdu` — l'assertion (qui verrouille la ligne entière via
     * regex pour détecter tout changement de source OU destination) a détecté
     * le changement et a été alignée.
     */
    #[Test]
    public function it_includes_robocopy_deploy_fragment_for_wpkg_startup(): void
    {
        $cmd = $this->assembleFromSnapshot($this->windowsStartupInfo())['cmd'];

        // Ligne complète : ROBOCOPY "%WinDir%\install\os\SambaEdu" "%ProgramFiles%\SambaEdu"
        // Backslashes et % échappés pour la regex.
        self::assertMatchesRegularExpression(
            '/ROBOCOPY "%WinDir%\\\\install\\\\os\\\\SambaEdu" "%ProgramFiles%\\\\SambaEdu"/',
            $cmd,
            'La ligne ROBOCOPY complète (source install\\os\\SambaEdu + destination %ProgramFiles%\\SambaEdu) '
            . 'doit être présente dans le blob startup (fragment wpkg/startup.windows, audit H.3 / P4).'
        );
    }

    /**
     * AC4.1 / P8 — Fragment `wallpaper/logon.windows` : substitution
     * `SE4INSTALL_NAME` + ligne `taskkill` complète.
     *
     * Risque bloquant audit Section A ligne 645 : si `###_SE4INSTALL_NAME_###`
     * n'est pas substitué, `taskkill … /FI "USERNAME ne ###_SE4INSTALL_NAME_###"`
     * ne filtre rien et tue explorer.exe pour TOUS les users. On verrouille la
     * **ligne taskkill complète** avec la valeur substituée (P8 — pas juste
     * `str_contains('se4install')` qui pourrait matcher ailleurs).
     */
    #[Test]
    public function it_substitutes_se4install_name_in_wallpaper_logon(): void
    {
        $cmd = $this->assembleFromSnapshot($this->windowsLogonInfo())['cmd'];

        // Aucun placeholder SE4INSTALL_NAME résiduel.
        self::assertStringNotContainsString(
            '###_SE4INSTALL_NAME_###',
            $cmd,
            'Le placeholder ###_SE4INSTALL_NAME_### ne doit pas subsister (risque taskkill explorer.exe pour tous).'
        );

        // P8 — ligne taskkill COMPLÈTE avec la valeur substituée (se4install).
        self::assertStringContainsString(
            'taskkill /F /IM explorer.exe /FI "USERNAME ne se4install"',
            $cmd,
            'La ligne taskkill complète doit contenir la valeur substituée se4install '
            . '(et non le placeholder littéral) — fragment wallpaper/logon.windows.'
        );
    }

    /**
     * P1 — Fragment `firefox/logon.windows` : présence du heredoc `profiles.ini`.
     *
     * Marqueur distinctif Firefox (capturé sur VM) : la section `profiles.ini`
     * écrite via `(ECHO …)>…\Firefox\profiles.ini`, contenant l'identifiant
     * `[Install308046B0AF4A39CB]`. Prouve que le fragment firefox est bien
     * présent + assemblé dans le blob logon/windows.
     */
    #[Test]
    public function it_includes_firefox_profiles_ini_fragment_in_windows_logon(): void
    {
        $cmd = $this->assembleFromSnapshot($this->windowsLogonInfo())['cmd'];

        // Marqueur distinctif firefox/logon.windows (heredoc profiles.ini).
        self::assertStringContainsString(
            '[Install308046B0AF4A39CB]',
            $cmd,
            'Le heredoc profiles.ini de firefox/logon.windows doit être présent dans le blob logon/windows.'
        );
        self::assertMatchesRegularExpression(
            '/\)>%userprofile%\\\\AppData\\\\Roaming\\\\Mozilla\\\\Firefox\\\\profiles\.ini/',
            $cmd,
            'La redirection du heredoc vers profiles.ini doit être présente (fragment firefox/logon.windows).'
        );
    }

    /**
     * P1 — Fragment `shortcuts/logon.windows` : appel curl `shortcuts_out.php`.
     *
     * Marqueur distinctif shortcuts (capturé sur VM) : le fragment télécharge
     * le `.cmd` de raccourcis via `curl … shortcuts_out.php` (et NON un mklink
     * direct — l'audit P1 supposait `mklink`/`.lnk` mais le contenu réel de la
     * fixture est un appel à l'endpoint `shortcuts_out.php`). On vérifie aussi
     * la substitution de `SE4FS_NAME` dans l'URL (placeholder → `se4fs`).
     */
    #[Test]
    public function it_includes_shortcuts_out_fragment_in_windows_logon(): void
    {
        $cmd = $this->assembleFromSnapshot($this->windowsLogonInfo())['cmd'];

        // Marqueur distinctif shortcuts/logon.windows.
        self::assertStringContainsString(
            'shortcuts_out.php',
            $cmd,
            'Le fragment shortcuts/logon.windows (curl shortcuts_out.php) doit être présent dans le blob logon/windows.'
        );
        // SE4FS_NAME substitué dans l'URL (pas de placeholder résiduel).
        self::assertStringContainsString(
            'http://se4fs/gpo/shortcuts_out.php',
            $cmd,
            'SE4FS_NAME doit être substitué (se4fs) dans l\'URL shortcuts_out.php — fragment shortcuts/logon.windows.'
        );
    }

    // =========================================================================
    // Volet 4 — Aucun placeholder résiduel dans les contextes critiques (AC4.2)
    // =========================================================================

    /**
     * @return array<string, array{label: string, info: array<string,mixed>, interpreter: string}>
     */
    public static function criticalContextsProvider(): array
    {
        return [
            'startup_windows' => [
                'label'       => 'startup/windows (wpkg + firewall + folders…)',
                'interpreter' => 'cmd',
                'info'        => null, // résolu dans le test (méthode d'instance).
            ],
            'logon_windows' => [
                'label'       => 'logon/windows (wallpaper + shortcuts + firefox…)',
                'interpreter' => 'cmd',
                'info'        => null,
            ],
            'logon_linux' => [
                'label'       => 'logon/linux (firefox)',
                'interpreter' => 'bash',
                'info'        => null,
            ],
        ];
    }

    /**
     * AC4.2 — Aucun placeholder `###_…_###` résiduel dans les blobs des
     * contextes critiques (startup/windows, logon/windows, logon/linux).
     *
     * Un placeholder résiduel indique un trou dans la whitelist 17.2 (→ escalade D1).
     */
    #[Test]
    #[DataProvider('criticalContextsProvider')]
    public function it_has_no_residual_placeholder_in_critical_context(
        string $label,
        string $interpreter,
        mixed $info,
    ): void {
        // Résolution du contexte par label (info=null dans le provider car les
        // helpers `$info` sont des méthodes d'instance, non statiques).
        $resolvedInfo = match (true) {
            str_starts_with($label, 'startup/windows') => $this->windowsStartupInfo(),
            str_starts_with($label, 'logon/windows')   => $this->windowsLogonInfo(),
            str_starts_with($label, 'logon/linux')     => $this->linuxLogonInfo(),
            default                                     => $this->windowsLogonInfo(),
        };

        $out  = $this->assembleFromSnapshot($resolvedInfo);
        $body = (string) ($out[$interpreter] ?? '');

        $matches = [];
        preg_match_all('/###_[A-Z0-9_]+_###/', $body, $matches);

        self::assertEmpty(
            $matches[0],
            sprintf(
                'Placeholders résiduels détectés dans contexte %s (%s) : %s — trou whitelist 17.2 (escalade D1 requise).',
                $label,
                $interpreter,
                implode(', ', array_unique($matches[0]))
            )
        );
    }
}
