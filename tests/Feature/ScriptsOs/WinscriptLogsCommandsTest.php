<?php

declare(strict_types=1);

namespace Tests\Feature\ScriptsOs;

use App\Console\Commands\WinscriptLogsDisableCommand;
use App\Console\Commands\WinscriptLogsEnableCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Story 17.5 / AC3.1 — Tests Feature des commandes `winscript-logs:*`.
 *
 * **Isolation `.env` (D5)** : chaque test écrit dans un `.env` de fixture
 * temporaire (`sys_get_temp_dir()`) injecté via `setEnvPath()`. Le `.env`
 * réel du repo n'est JAMAIS touché. Le fixture est nettoyé en `tearDown`.
 *
 * Les commandes enable/disable sont exécutées en instanciant directement la
 * commande (pour injecter le chemin `.env` de fixture avant `run()`), ce que
 * `$this->artisan()` ne permet pas. La commande `status` (lecture seule via
 * config) est testée via `$this->artisan()`.
 */
class WinscriptLogsCommandsTest extends TestCase
{
    private string $envFixturePath;

    /** Sortie console capturée de la dernière commande enable/disable exécutée. */
    private string $lastOutput = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->envFixturePath = sys_get_temp_dir()
            .'/winscript-logs-test-'.uniqid('', true).'.env';
    }

    protected function tearDown(): void
    {
        if (is_file($this->envFixturePath)) {
            unlink($this->envFixturePath);
        }

        parent::tearDown();
    }

    /**
     * Écrit un `.env` de fixture avec le contenu donné.
     */
    private function writeFixture(string $contents): void
    {
        file_put_contents($this->envFixturePath, $contents);
    }

    /**
     * Exécute une commande enable/disable en injectant le `.env` de fixture.
     *
     * @param  class-string  $commandClass
     */
    private function runCommandWithFixture(string $commandClass): int
    {
        /** @var \App\Console\Commands\WinscriptLogsEnableCommand|\App\Console\Commands\WinscriptLogsDisableCommand $command */
        $command = $this->app->make($commandClass);
        $command->setEnvPath($this->envFixturePath);
        $command->setLaravel($this->app);

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);
        $this->lastOutput = $output->fetch();

        return $exit;
    }

    #[Test]
    public function enable_sets_flag_true(): void
    {
        $this->writeFixture("APP_NAME=SambaEdu\nAPP_KEY=base64:abc\n");

        $exit = $this->runCommandWithFixture(WinscriptLogsEnableCommand::class);

        self::assertSame(0, $exit);
        $contents = file_get_contents($this->envFixturePath);
        self::assertStringContainsString('SAMBAEDU_SCRIPTS_LOGGING_ENABLED=true', $contents);
        self::assertStringNotContainsString('SAMBAEDU_SCRIPTS_LOGGING_ENABLED=false', $contents);
    }

    #[Test]
    public function disable_sets_flag_false(): void
    {
        $this->writeFixture("APP_NAME=SambaEdu\nSAMBAEDU_SCRIPTS_LOGGING_ENABLED=true\n");

        $exit = $this->runCommandWithFixture(WinscriptLogsDisableCommand::class);

        self::assertSame(0, $exit);
        $contents = file_get_contents($this->envFixturePath);
        self::assertStringContainsString('SAMBAEDU_SCRIPTS_LOGGING_ENABLED=false', $contents);
        self::assertStringNotContainsString('SAMBAEDU_SCRIPTS_LOGGING_ENABLED=true', $contents);
    }

    #[Test]
    public function enable_appends_flag_when_absent_with_clean_newline(): void
    {
        // Fichier ne finissant PAS par \n → l'append doit insérer une nouvelle ligne propre.
        $this->writeFixture('APP_KEY=base64:xyz');

        $this->runCommandWithFixture(WinscriptLogsEnableCommand::class);

        $contents = file_get_contents($this->envFixturePath);
        // La variable APP_KEY ne doit pas être concaténée à la ligne du flag.
        self::assertStringContainsString("APP_KEY=base64:xyz\nSAMBAEDU_SCRIPTS_LOGGING_ENABLED=true\n", $contents);
    }

    #[Test]
    public function enable_is_idempotent(): void
    {
        $this->writeFixture("APP_NAME=SambaEdu\nSAMBAEDU_SCRIPTS_LOGGING_ENABLED=true\n");

        $this->runCommandWithFixture(WinscriptLogsEnableCommand::class);
        $this->runCommandWithFixture(WinscriptLogsEnableCommand::class);

        $contents = file_get_contents($this->envFixturePath);
        // Une seule ligne du flag (pas de duplication).
        self::assertSame(
            1,
            preg_match_all('/^SAMBAEDU_SCRIPTS_LOGGING_ENABLED=/m', $contents),
            'Double enable doit produire exactement une ligne du flag (idempotence).',
        );
        self::assertStringContainsString('SAMBAEDU_SCRIPTS_LOGGING_ENABLED=true', $contents);
    }

    #[Test]
    public function disable_is_idempotent(): void
    {
        $this->writeFixture("APP_NAME=SambaEdu\nSAMBAEDU_SCRIPTS_LOGGING_ENABLED=false\n");

        $this->runCommandWithFixture(WinscriptLogsDisableCommand::class);
        $this->runCommandWithFixture(WinscriptLogsDisableCommand::class);

        $contents = file_get_contents($this->envFixturePath);
        self::assertSame(
            1,
            preg_match_all('/^SAMBAEDU_SCRIPTS_LOGGING_ENABLED=/m', $contents),
        );
        self::assertStringContainsString('SAMBAEDU_SCRIPTS_LOGGING_ENABLED=false', $contents);
    }

    #[Test]
    public function preserves_other_env_lines(): void
    {
        $original = <<<'ENV'
        APP_NAME=SambaEdu
        APP_KEY=base64:abcdef==
        # Commentaire de configuration GLPI
        SAMBAEDU_GLPI_URL=https://glpi.example.org

        DB_CONNECTION=mysql
        ENV;
        $this->writeFixture($original."\n");

        $this->runCommandWithFixture(WinscriptLogsEnableCommand::class);

        $contents = file_get_contents($this->envFixturePath);

        // Toutes les lignes d'origine sont préservées.
        self::assertStringContainsString('APP_NAME=SambaEdu', $contents);
        self::assertStringContainsString('APP_KEY=base64:abcdef==', $contents);
        self::assertStringContainsString('# Commentaire de configuration GLPI', $contents);
        self::assertStringContainsString('SAMBAEDU_GLPI_URL=https://glpi.example.org', $contents);
        self::assertStringContainsString('DB_CONNECTION=mysql', $contents);
        // Préservation de la ligne vide.
        self::assertStringContainsString("\n\nDB_CONNECTION=mysql", $contents);
        // Le flag a bien été appendé.
        self::assertStringContainsString('SAMBAEDU_SCRIPTS_LOGGING_ENABLED=true', $contents);
    }

    #[Test]
    public function preserves_other_lines_byte_for_byte_on_replacement(): void
    {
        // Le flag est DÉJÀ présent → exerce la branche `preg_replace` (remplacement),
        // distincte de l'append testé ci-dessus. Voisinage riche (commentaire, ligne vide).
        $original = "APP_NAME=SambaEdu\n"
            ."# Logging des scripts\n"
            ."SAMBAEDU_SCRIPTS_LOGGING_ENABLED=false\n"
            ."\n"
            ."DB_CONNECTION=mysql\n";
        $this->writeFixture($original);

        $this->runCommandWithFixture(WinscriptLogsEnableCommand::class);

        $contents = file_get_contents($this->envFixturePath);

        // Seule la ligne du flag a changé : tout le reste est identique byte-pour-byte,
        // et le nombre total de lignes est inchangé (pas de duplication ni de perte).
        $expected = str_replace(
            'SAMBAEDU_SCRIPTS_LOGGING_ENABLED=false',
            'SAMBAEDU_SCRIPTS_LOGGING_ENABLED=true',
            $original,
        );
        self::assertSame($expected, $contents);
        self::assertSame(
            substr_count($original, "\n"),
            substr_count($contents, "\n"),
            'Le nombre de lignes doit rester inchangé lors d\'un remplacement.',
        );
    }

    #[Test]
    public function preserves_crlf_line_endings_on_replacement(): void
    {
        // .env en CRLF (ex. édité sous Windows) : le terminateur \r\n de la ligne
        // modifiée doit être préservé byte-pour-byte (AC2.2).
        $original = "APP_NAME=SambaEdu\r\nSAMBAEDU_SCRIPTS_LOGGING_ENABLED=false\r\nDB_CONNECTION=mysql\r\n";
        $this->writeFixture($original);

        $this->runCommandWithFixture(WinscriptLogsEnableCommand::class);

        $contents = file_get_contents($this->envFixturePath);

        $expected = "APP_NAME=SambaEdu\r\nSAMBAEDU_SCRIPTS_LOGGING_ENABLED=true\r\nDB_CONNECTION=mysql\r\n";
        self::assertSame($expected, $contents);
    }

    #[Test]
    public function enable_reports_activation_message_with_ingest_url(): void
    {
        $this->writeFixture("APP_NAME=SambaEdu\n");
        config(['sambaedu.scripts.logging.enabled' => false]);

        $this->runCommandWithFixture(WinscriptLogsEnableCommand::class);

        self::assertStringContainsString('ACTIVÉ', $this->lastOutput);
        // Rappel opérateur : les scripts seront wrappés et POSTeront vers l'endpoint d'ingestion.
        self::assertStringContainsString('script-execution-logs', $this->lastOutput);
    }

    #[Test]
    public function enable_reports_idempotent_message_when_already_enabled(): void
    {
        $this->writeFixture("SAMBAEDU_SCRIPTS_LOGGING_ENABLED=true\n");
        // L'état effectif vu par le process (config) est déjà activé.
        config(['sambaedu.scripts.logging.enabled' => true]);

        $this->runCommandWithFixture(WinscriptLogsEnableCommand::class);

        self::assertStringContainsString('déjà activé', $this->lastOutput);
    }

    #[Test]
    public function disable_reports_iso_legacy_message(): void
    {
        $this->writeFixture("SAMBAEDU_SCRIPTS_LOGGING_ENABLED=true\n");
        config(['sambaedu.scripts.logging.enabled' => true]);

        $this->runCommandWithFixture(WinscriptLogsDisableCommand::class);

        self::assertStringContainsString('DÉSACTIVÉ', $this->lastOutput);
        self::assertStringContainsString('iso-legacy', $this->lastOutput);
    }

    #[Test]
    public function enable_fails_when_env_file_missing(): void
    {
        // Aucun fichier écrit → le chemin de fixture n'existe pas.
        self::assertFileDoesNotExist($this->envFixturePath);

        $exit = $this->runCommandWithFixture(WinscriptLogsEnableCommand::class);

        self::assertSame(1, $exit, 'enable doit retourner FAILURE si le .env est introuvable.');
    }

    #[Test]
    public function status_reports_enabled_state(): void
    {
        config(['sambaedu.scripts.logging.enabled' => true]);

        $this->artisan('winscript-logs:status')
            ->expectsOutputToContain('ACTIVÉ')
            ->assertSuccessful();
    }

    #[Test]
    public function status_reports_disabled_state(): void
    {
        config(['sambaedu.scripts.logging.enabled' => false]);

        $this->artisan('winscript-logs:status')
            ->expectsOutputToContain('DÉSACTIVÉ')
            ->assertSuccessful();
    }
}
