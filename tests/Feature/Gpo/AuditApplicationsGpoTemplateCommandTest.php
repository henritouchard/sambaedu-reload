<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 17.3 — Tests Feature de la commande artisan `gpo:applications:audit`.
 *
 * Pattern fixture-directory (mode dégradé sans ext-zip) iso 16.6
 * `scanDirectoryPlaceholders`. Les fixtures vivent dans
 * `tests/Fixtures/Gpo/se4_applications_template*` et reproduisent le contenu
 * réel du template `/usr/share/sambaedu/gpo/sambaedu-gpo/se4_applications/`
 * inspecté en T0.4.
 *
 * @see \App\Console\Commands\AuditApplicationsGpoTemplateCommand
 */
class AuditApplicationsGpoTemplateCommandTest extends TestCase
{
    private string $legacyFixture;
    private string $pristineFixture;
    private string $unknownFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->legacyFixture = base_path('tests/Fixtures/Gpo/se4_applications_template');
        $this->pristineFixture = base_path('tests/Fixtures/Gpo/se4_applications_template_pristine');
        $this->unknownFixture = base_path('tests/Fixtures/Gpo/se4_applications_template_unknown');
    }

    /**
     * AC1.1 — La commande détecte les URLs legacy `gpo/applications.php` dans
     * les `.cmd` orchestrateurs et flag chaque fichier en `legacy_match=true`.
     */
    #[Test]
    public function it_detects_legacy_urls_in_orchestrators(): void
    {
        $exitCode = Artisan::call('gpo:applications:audit', [
            '--path' => $this->legacyFixture,
        ]);

        self::assertSame(2, $exitCode, 'Exit code attendu = 2 (warning) quand URL legacy détectée.');

        $output = Artisan::output();
        self::assertStringContainsString('Legacy match   : 4', $output);
        self::assertStringContainsString('startup.cmd', $output);
        self::assertStringContainsString('shutdown.cmd', $output);
        self::assertStringContainsString('logon.cmd', $output);
        self::assertStringContainsString('logoff.cmd', $output);
        self::assertStringContainsString('substitute_post_extraction', $output);
    }

    /**
     * AC1.2 — Mode `--json` produit une sortie structurée conforme spec story
     * (clés `template_path`, `files[]`, `summary`, `unknown_placeholders`).
     */
    #[Test]
    public function it_outputs_json_with_summary(): void
    {
        $exitCode = Artisan::call('gpo:applications:audit', [
            '--path' => $this->legacyFixture,
            '--json' => true,
        ]);

        self::assertSame(2, $exitCode);

        $raw = trim(Artisan::output());
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($data);
        self::assertArrayHasKey('template_path', $data);
        self::assertArrayHasKey('files', $data);
        self::assertArrayHasKey('summary', $data);
        self::assertArrayHasKey('unknown_placeholders', $data);

        self::assertSame($this->legacyFixture, $data['template_path']);
        self::assertCount(4, $data['files'], '4 fichiers .cmd attendus dans la fixture legacy.');

        self::assertSame(4, $data['summary']['total_files']);
        self::assertSame(4, $data['summary']['legacy_count']);
        self::assertSame(0, $data['summary']['ok_count']);
        self::assertSame(0, $data['summary']['unknown_placeholders_count']);

        // Vérification structurelle d'un fichier (premier après tri).
        $first = $data['files'][0];
        self::assertArrayHasKey('path', $first);
        self::assertArrayHasKey('urls', $first);
        self::assertArrayHasKey('placeholders', $first);
        self::assertArrayHasKey('legacy_match', $first);
        self::assertArrayHasKey('recommendation', $first);
        self::assertTrue($first['legacy_match']);
        self::assertSame('substitute_post_extraction', $first['recommendation']);
    }

    /**
     * AC1.1 chemin erreur fatale — template absent → exit code 1 + message clair.
     */
    #[Test]
    public function it_returns_exit_1_if_template_absent(): void
    {
        $exitCode = Artisan::call('gpo:applications:audit', [
            '--path' => '/tmp/non-existant-' . uniqid('17-3-', true) . '.zip',
        ]);

        self::assertSame(1, $exitCode, 'Exit code attendu = 1 (error) quand template absent.');

        $output = Artisan::output();
        self::assertStringContainsString('Template introuvable', $output);
        self::assertStringContainsString('sambaedu-gpo', $output);
    }

    /**
     * AC1.3 — Détection des placeholders hors whitelist (warning non bloquant,
     * exit code 2). Le placeholder `INVENTE` est volontairement introduit dans
     * la fixture pour vérifier la comparaison avec la whitelist
     * `config('sambaedu.gpo.applications.substitutions.whitelist')`.
     */
    #[Test]
    public function it_detects_unknown_placeholders(): void
    {
        $exitCode = Artisan::call('gpo:applications:audit', [
            '--path' => $this->unknownFixture,
            '--json' => true,
        ]);

        self::assertSame(2, $exitCode, 'Exit code attendu = 2 (warning) quand placeholder hors whitelist.');

        $data = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        self::assertContains('INVENTE', $data['unknown_placeholders']);
        self::assertGreaterThanOrEqual(1, $data['summary']['unknown_placeholders_count']);

        // `APPLICATIONS_SCRIPTS_URL` est dans la whitelist 17.3 et ne doit
        // PAS être rapporté comme inconnu.
        self::assertNotContains('APPLICATIONS_SCRIPTS_URL', $data['unknown_placeholders']);
        // `SE4FS_NAME` est dans la whitelist 16.7.
        self::assertNotContains('SE4FS_NAME', $data['unknown_placeholders']);
    }

    /**
     * AC1.1 — Cas heureux : template pristine (URL via placeholder
     * `###_APPLICATIONS_SCRIPTS_URL_###`, aucun match legacy, aucun placeholder
     * inconnu) → exit code 0.
     */
    #[Test]
    public function it_returns_exit_0_when_template_pristine(): void
    {
        $exitCode = Artisan::call('gpo:applications:audit', [
            '--path' => $this->pristineFixture,
        ]);

        self::assertSame(0, $exitCode, 'Exit code attendu = 0 (ok) quand template propre.');

        $output = Artisan::output();
        self::assertStringContainsString('Legacy match   : 0', $output);
        self::assertStringContainsString('OK             : 1', $output);
    }

    /**
     * AC1.3 — La sortie `--json` inclut les placeholders détectés par fichier
     * (vérification fine du décodage UTF-16LE par opposition au scan brut).
     */
    #[Test]
    public function it_extracts_placeholders_per_file_in_json_mode(): void
    {
        Artisan::call('gpo:applications:audit', [
            '--path' => $this->legacyFixture,
            '--json' => true,
        ]);

        $data = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        foreach ($data['files'] as $file) {
            self::assertContains('SE4FS_NAME', $file['placeholders'],
                sprintf('Fichier %s doit contenir le placeholder SE4FS_NAME.', $file['path']));
            self::assertContains('DOMAIN', $file['placeholders'],
                sprintf('Fichier %s doit contenir le placeholder DOMAIN.', $file['path']));
        }
    }

    /**
     * AC1.1 mode ZIP — post-review 17.3 #5 : couvre la branche `ZipArchive`
     * (sinon non exercée par les fixtures-directory). Crée un `.zip` à la volée
     * via `ZipArchive::open(... CREATE)` avec 1 `.cmd` legacy + 1 placeholder,
     * puis vérifie que la commande détecte l'URL legacy et retourne
     * `substitute_post_extraction`.
     */
    #[Test]
    public function it_scans_zip_template_and_detects_legacy_url(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip non disponible — test ZIP non exécutable.');
        }
        $tmpZip = sys_get_temp_dir() . '/17-3-zip-' . uniqid('', true) . '.zip';
        $cmdContent = <<<'CMD'
:: applications startup (legacy URL test fixture)
if [%SE4FS%]==[] set SE4FS=###_SE4FS_NAME_###
curl.exe -o "%temp%\applications-startup.cmd" -F "os=windows" "http://%SE4FS%.###_DOMAIN_###/gpo/applications.php" >NUL
CMD;
        $zip = new \ZipArchive();
        self::assertTrue(
            $zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true,
            'Création ZIP fixture KO.',
        );
        $zip->addFromString('Machine/Scripts/Startup/startup.cmd', $cmdContent);
        $zip->close();

        try {
            $exitCode = Artisan::call('gpo:applications:audit', [
                '--path' => $tmpZip,
                '--json' => true,
            ]);

            self::assertSame(2, $exitCode, 'Exit code attendu = 2 (URL legacy détectée dans ZIP).');

            $data = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $data['summary']['total_files']);
            self::assertSame(1, $data['summary']['legacy_count']);
            self::assertSame('substitute_post_extraction', $data['files'][0]['recommendation']);
            self::assertTrue($data['files'][0]['legacy_match']);
            self::assertSame('Machine/Scripts/Startup/startup.cmd', $data['files'][0]['path']);
            self::assertContains('SE4FS_NAME', $data['files'][0]['placeholders']);
            self::assertContains('DOMAIN', $data['files'][0]['placeholders']);
        } finally {
            @unlink($tmpZip);
        }
    }

    /**
     * AC1.1 — Filtre par extension : seuls les fichiers `.cmd|.bat|.ini|.xml|...`
     * sont scannés. Vérifie qu'un fichier `.png` ou `.exe` factice ne pollue pas
     * la sortie.
     */
    #[Test]
    public function it_filters_non_text_extensions(): void
    {
        // Crée un dossier temporaire avec un fichier .cmd + un fichier binaire.
        $tmpDir = sys_get_temp_dir() . '/17-3-filter-' . uniqid('', true);
        mkdir($tmpDir . '/Machine/Scripts/Startup', 0755, true);
        file_put_contents(
            $tmpDir . '/Machine/Scripts/Startup/startup.cmd',
            'curl.exe "http://example.com/gpo/applications.php"',
        );
        file_put_contents($tmpDir . '/Machine/Scripts/Startup/icon.png', "\x89PNG\r\n\x1A\n");
        file_put_contents($tmpDir . '/Machine/Scripts/Startup/binary.exe', "MZ\x90\x00\x03");

        try {
            Artisan::call('gpo:applications:audit', [
                '--path' => $tmpDir,
                '--json' => true,
            ]);

            $data = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(1, $data['summary']['total_files'],
                'Seul le fichier .cmd doit être scanné — .png et .exe ignorés.');
            self::assertSame('Machine/Scripts/Startup/startup.cmd', $data['files'][0]['path']);
        } finally {
            // Cleanup
            @unlink($tmpDir . '/Machine/Scripts/Startup/startup.cmd');
            @unlink($tmpDir . '/Machine/Scripts/Startup/icon.png');
            @unlink($tmpDir . '/Machine/Scripts/Startup/binary.exe');
            @rmdir($tmpDir . '/Machine/Scripts/Startup');
            @rmdir($tmpDir . '/Machine/Scripts');
            @rmdir($tmpDir . '/Machine');
            @rmdir($tmpDir);
        }
    }
}
