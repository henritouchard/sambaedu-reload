<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Windows\WpkgReportIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Worker local : relève les rapports WPKG (.txt) déposés par les postes et les ingère.
 *
 * Signature : wpkg:process-reports {--path= : Override du chemin rapports}
 *
 * Comportement :
 *   1. Scan du répertoire (config('sambaedu.wpkg.reports_inbox') ou --path)
 *   2. Pour chaque fichier .txt → extrait hostname → ingestion
 *   3. Traité ou inchangé → fichier archivé dans reports_archive/
 *   4. Erreur → log + fichier laissé en place (retry au prochain run)
 *   5. Log des compteurs : traités, inchangés, erreurs
 *
 * **L'ingestion est appelée EN PROCESS**, jamais par un aller-retour HTTP sur sa
 * propre API. Le POST vers `/api/wpkg/reports/{hostname}` partait sur `APP_URL`,
 * donc sortait par l'interface réseau et revenait avec l'IP de la machine comme
 * `REMOTE_ADDR` : `EnsureLocalRequest` n'autorisant que la boucle locale, chaque
 * relevé finissait en 403 et les rapports s'accumulaient sans qu'aucun poste ne
 * remonte plus rien. Le détour ne servait à rien — la commande tourne dans la même
 * application que le service.
 *
 * La route HTTP reste en place : c'est la surface prévue pour que les postes
 * POSTent un jour directement, sans passer par le partage.
 */
class WpkgProcessReportsCommand extends Command
{
    /** Borne dure d'un rapport relevé, iso la garde que portait le contrôleur HTTP. */
    private const MAX_REPORT_BYTES = 2_000_000;

    protected $signature = 'wpkg:process-reports
        {--path= : Override du chemin du répertoire des rapports}';

    protected $description = 'Traite les rapports WPKG (.txt) du partage SMB et les ingère.';

    protected $help = <<<'HELP'
    Relève les comptes rendus d'installation déposés par les postes sur le partage,
    et les injecte dans SE5.

      <info>php artisan wpkg:process-reports</info>
      <info>php artisan wpkg:process-reports --path=/chemin/des/rapports</info>

    Pour chaque fichier : le poste concerné est identifié, le rapport est transmis,
    puis le fichier est ARCHIVÉ. En cas d'échec, <comment>le fichier est laissé en place</comment>
    et sera retenté au passage suivant — rien n'est perdu si le serveur est
    momentanément indisponible.

    Le compte-rendu final donne le nombre de rapports traités, inchangés et en erreur.

    <comment>Conséquence pratique :</comment> si l'état d'installation des postes n'avance plus,
    regardez d'abord si les fichiers s'accumulent dans le répertoire de dépôt — c'est
    le signe que ce relevé ne tourne pas.
    HELP;

    public function __construct(
        private readonly WpkgReportIngestionService $ingestionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $reportsPath = $this->option('path')
            ?? config('sambaedu.wpkg.reports_inbox', '/var/sambaedu/unattended/install/wpkg/rapports');

        $archivePath = config(
            'sambaedu.wpkg.reports_archive',
            $reportsPath . '/processed'
        );

        if (!is_dir($reportsPath)) {
            $this->error("Répertoire introuvable : {$reportsPath}");
            return Command::FAILURE;
        }

        // Sécurité : évite une boucle infinie si reports_archive = reports_inbox
        if (realpath($archivePath) !== false && realpath($archivePath) === realpath($reportsPath)) {
            $this->error("Le répertoire d'archive ne peut pas être identique au répertoire des rapports : {$archivePath}");
            return Command::FAILURE;
        }

        // Verrou global : empêche deux exécutions simultanées de la commande
        return Cache::lock('wpkg:process-reports', 120)->block(1, function () use ($reportsPath, $archivePath): int {
            return $this->doProcessReports($reportsPath, $archivePath);
        });
    }

    /**
     * Logique principale (appelée sous Cache::lock).
     */
    private function doProcessReports(string $reportsPath, string $archivePath): int
    {
        // Créer le répertoire d'archive si besoin
        if (!is_dir($archivePath)) {
            mkdir($archivePath, 0755, true);
        }

        $files = glob("{$reportsPath}/*.txt");

        if (empty($files)) {
            $this->info('Aucun rapport à traiter.');
            return Command::SUCCESS;
        }

        $counters = [
            'processed' => 0,
            'unchanged' => 0,
            'error'     => 0,
        ];

        $cutoff = time() - 10;

        foreach ($files as $file) {
            // Skip les fichiers récents (< 10s) potentiellement en cours d'écriture
            if (filemtime($file) >= $cutoff) {
                Log::debug('wpkg:process-reports — fichier ignoré (trop récent)', ['file' => $file]);
                continue;
            }

            $hostname = pathinfo($file, PATHINFO_FILENAME);
            $result   = $this->processFile($file, $hostname);

            match ($result) {
                'processed' => $counters['processed']++,
                'unchanged' => $counters['unchanged']++,
                default     => $counters['error']++,
            };

            if ($result === 'processed' || $result === 'unchanged') {
                $this->archiveFile($file, $archivePath, $hostname);
            }
        }

        $this->info(sprintf(
            'Terminé : %d traités, %d inchangés, %d erreurs.',
            $counters['processed'],
            $counters['unchanged'],
            $counters['error']
        ));

        return $counters['error'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Traite un fichier rapport : ingestion en process.
     *
     * Reprend les gardes que portait le contrôleur HTTP et qui n'appartenaient pas
     * au transport : rapport vide, taille bornée. Le poste inconnu, lui, est déjà
     * le verdict `notFound` du service — le contrôleur ne le pré-testait que pour
     * rendre un 404.
     *
     * @return string 'processed'|'unchanged'|'error'
     */
    private function processFile(string $filePath, string $hostname): string
    {
        $size = @filesize($filePath);
        if ($size !== false && $size > self::MAX_REPORT_BYTES) {
            $this->warn("  ✗ {$hostname} — rapport trop volumineux ({$size} octets)");
            Log::warning('wpkg:process-reports — rapport trop volumineux', [
                'hostname' => $hostname,
                'bytes' => $size,
            ]);

            return 'error';
        }

        $content = @file_get_contents($filePath);

        if ($content === false) {
            $this->warn("Impossible de lire le fichier : {$filePath}");
            Log::warning('wpkg:process-reports — lecture impossible', ['file' => $filePath]);

            return 'error';
        }

        if (trim($content) === '') {
            $this->warn("  ✗ {$hostname} — rapport vide");
            Log::warning('wpkg:process-reports — rapport vide', ['hostname' => $hostname]);

            return 'error';
        }

        try {
            $result = $this->ingestionService->ingest($hostname, $content);
        } catch (\Throwable $e) {
            $this->warn("  ✗ {$hostname} — exception : {$e->getMessage()}");
            Log::error('wpkg:process-reports — exception', [
                'hostname' => $hostname,
                'error' => $e->getMessage(),
            ]);

            return 'error';
        }

        if ($result->isUnchanged()) {
            $this->line("  = {$hostname} — inchangé (SHA identique)");
            Log::info('wpkg:process-reports — rapport inchangé', ['hostname' => $hostname]);

            return 'unchanged';
        }

        if ($result->isProcessed()) {
            $this->line("  ✓ {$hostname} — traité ({$result->packagesCount} packages)");
            Log::info('wpkg:process-reports — rapport traité', ['hostname' => $hostname]);

            return 'processed';
        }

        $this->warn("  ✗ {$hostname} — " . ($result->error ?? 'ingestion refusée'));
        Log::warning('wpkg:process-reports — ingestion refusée', [
            'hostname' => $hostname,
            'error' => $result->error,
        ]);

        return 'error';
    }

    /**
     * Archive le fichier traité en le déplaçant dans le répertoire d'archive.
     *
     * - Suffixe aléatoire pour éviter les collisions timestamp (même seconde).
     * - Fallback copy+unlink si rename() échoue (cross-FS, SMB, etc.).
     */
    private function archiveFile(string $filePath, string $archivePath, string $hostname): void
    {
        $suffix      = date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4));
        $destination = "{$archivePath}/{$hostname}_{$suffix}.txt";

        if (rename($filePath, $destination)) {
            return;
        }

        // Fallback cross-FS : copy + unlink
        try {
            if (copy($filePath, $destination)) {
                @unlink($filePath);
            } else {
                throw new \RuntimeException("copy() a retourné false");
            }
        } catch (\Throwable $e) {
            Log::warning('wpkg:process-reports — impossible d\'archiver', [
                'source'      => $filePath,
                'destination' => $destination,
                'error'       => $e->getMessage(),
            ]);
            $this->warn("  Attention : impossible d'archiver {$filePath}");
        }
    }
}
