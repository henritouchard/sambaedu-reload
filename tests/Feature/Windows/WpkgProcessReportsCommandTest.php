<?php

declare(strict_types=1);

namespace Tests\Feature\Windows;

use App\Services\Windows\IngestionResult;
use App\Services\Windows\WpkgReportIngestionService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Tests Feature : commande artisan wpkg:process-reports
 *
 * Couverture :
 *   - Rapport présent → ingéré → fichier archivé
 *   - Rapport inchangé → fichier archivé sans warning
 *   - Ingestion en échec → fichier PAS archivé, warning loggé
 *   - Répertoire vide → commande termine sans erreur
 *
 * L'ingestion est appelée EN PROCESS : c'est le service qu'on double, pas une
 * réponse HTTP. Le worker a POSTé sur sa propre API jusqu'à ce que `APP_URL` le
 * fasse sortir par le réseau et revenir avec une IP non locale — 403 à chaque
 * relevé. Doubler le transport laissait ce défaut invisible.
 */
class WpkgProcessReportsCommandTest extends TestCase
{
    /** Répertoire temporaire pour les rapports */
    private string $tmpDir;

    /** Répertoire temporaire pour les archives */
    private string $archiveDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Créer des répertoires temporaires pour les tests
        $this->tmpDir    = sys_get_temp_dir() . '/wpkg_reports_test_' . uniqid();
        $this->archiveDir = $this->tmpDir . '/processed';

        mkdir($this->tmpDir, 0755, true);

        Config::set('sambaedu.wpkg.reports_inbox', $this->tmpDir);
        Config::set('sambaedu.wpkg.reports_archive', $this->archiveDir);
    }

    protected function tearDown(): void
    {
        // Nettoyage des fichiers temporaires
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob("{$dir}/*") as $item) {
            if (is_dir($item)) {
                $this->removeDirectory($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }

    private function createReportFile(string $hostname, ?string $content = null): string
    {
        if ($content === null) {
            $content = "2024-01-15 08:30:00 {$hostname} AA:BB:CC:DD:EE:FF [10.0.0.50]\nID: firefox\nRevision: 125.0\nReboot: false\nStatus: Installed\n---\n";
        }

        $path = "{$this->tmpDir}/{$hostname}.txt";
        file_put_contents($path, $content);

        // Par défaut : fichier "ancien" (30s dans le passé) pour ne pas être skippé par le filtre de fraîcheur
        touch($path, time() - 30);

        return $path;
    }

    /**
     * Double l'ingestion : le verdict rendu par le service est le seul signal que
     * la commande lit pour décider d'archiver ou de laisser le fichier en place.
     */
    private function fakeIngestion(IngestionResult $result): void
    {
        $this->mock(
            WpkgReportIngestionService::class,
            fn ($mock) => $mock->shouldReceive('ingest')->andReturn($result),
        );
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    /**
     * AC #6 : Fichier rapport présent → ingéré → fichier archivé.
     */
    public function test_valid_report_is_processed_and_archived(): void
    {
        $filePath = $this->createReportFile('PC-SALLE-01');

        $this->fakeIngestion(IngestionResult::processed('PC-SALLE-01', 1));

        $this->artisan('wpkg:process-reports')
            ->assertExitCode(0);

        // Fichier original supprimé (archivé)
        $this->assertFileDoesNotExist($filePath);

        // Archive créée
        $archived = glob("{$this->archiveDir}/PC-SALLE-01_*.txt");
        $this->assertNotEmpty($archived, 'Le fichier nà pas été archivé.');
    }

    /**
     * AC #6 : verdict « inchangé » → fichier archivé sans warning.
     */
    public function test_unchanged_report_is_archived(): void
    {
        $filePath = $this->createReportFile('PC-SALLE-02');

        $this->fakeIngestion(IngestionResult::unchanged('PC-SALLE-02'));

        $this->artisan('wpkg:process-reports')
            ->assertExitCode(0);

        // Fichier original supprimé (archivé)
        $this->assertFileDoesNotExist($filePath);

        // Archive créée
        $archived = glob("{$this->archiveDir}/PC-SALLE-02_*.txt");
        $this->assertNotEmpty($archived, 'Le fichier unchanged na pas été archivé.');
    }

    /**
     * AC #6 : ingestion en échec → fichier PAS archivé, warning loggé.
     */
    public function test_api_error_does_not_archive_file(): void
    {
        $filePath = $this->createReportFile('PC-SALLE-03');

        $this->fakeIngestion(IngestionResult::parseFailed('PC-SALLE-03'));

        $this->artisan('wpkg:process-reports')
            ->assertExitCode(1); // FAILURE car erreur

        // Fichier original doit rester en place
        $this->assertFileExists($filePath);
    }

    /**
     * AC #6 : Répertoire vide → commande termine sans erreur.
     */
    public function test_empty_directory_exits_successfully(): void
    {
        // Aucun fichier dans tmpDir
        $this->artisan('wpkg:process-reports')
            ->expectsOutput('Aucun rapport à traiter.')
            ->assertExitCode(0);
    }

    /**
     * AC #6 : Option --path override le chemin par défaut.
     */
    public function test_path_option_overrides_default(): void
    {
        $altDir = sys_get_temp_dir() . '/wpkg_alt_' . uniqid();
        mkdir($altDir, 0755, true);

        try {
            // Répertoire alternatif vide → succès
            $this->artisan("wpkg:process-reports --path={$altDir}")
                ->expectsOutput('Aucun rapport à traiter.')
                ->assertExitCode(0);
        } finally {
            @rmdir($altDir);
        }
    }

    /**
     * AC #6 : Chemin introuvable → erreur propre.
     */
    public function test_nonexistent_path_returns_failure(): void
    {
        $this->artisan('wpkg:process-reports --path=/chemin/inexistant/vraiment')
            ->assertExitCode(1);
    }

    /**
     * Fix #6 : Fichier modifié il y a moins de 10s → ignoré par le worker.
     */
    public function test_recent_file_is_skipped(): void
    {
        $filePath = $this->createReportFile('PC-RECENT');

        // Forcer un mtime très récent (dans le futur proche = clairement < 10s)
        touch($filePath, time() + 5);

        // Un fichier encore en cours d'écriture ne doit atteindre l'ingestion
        // sous aucun prétexte : on la rend explosive pour le prouver.
        $this->mock(
            WpkgReportIngestionService::class,
            fn ($mock) => $mock->shouldNotReceive('ingest'),
        );

        $this->artisan('wpkg:process-reports')
            ->assertExitCode(0);

        // Fichier toujours en place (non archivé)
        $this->assertFileExists($filePath);
    }

    /**
     * AC #6 : Plusieurs fichiers — un succès, une erreur — compteurs corrects.
     */
    public function test_multiple_files_with_mixed_results(): void
    {
        $this->createReportFile('PC-OK');
        $this->createReportFile('PC-FAIL');

        $this->mock(
            WpkgReportIngestionService::class,
            fn ($mock) => $mock->shouldReceive('ingest')->andReturnUsing(
                fn (string $hostname): IngestionResult => $hostname === 'PC-OK'
                    ? IngestionResult::processed($hostname, 2)
                    : IngestionResult::parseFailed($hostname),
            ),
        );

        $this->artisan('wpkg:process-reports')
            ->assertExitCode(1); // Au moins une erreur → FAILURE

        // PC-OK archivé
        $archivedOk = glob("{$this->archiveDir}/PC-OK_*.txt");
        $this->assertNotEmpty($archivedOk);

        // PC-FAIL non archivé
        $this->assertFileExists("{$this->tmpDir}/PC-FAIL.txt");
    }
}
