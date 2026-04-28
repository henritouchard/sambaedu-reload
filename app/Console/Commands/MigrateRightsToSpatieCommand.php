<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Permissions\RightsMigrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Commande artisan one-shot — migre les assignations de droits legacy (groupes
 * LDAP dans `rights_rdn` + délégations scopées dans `delegations_rdn`) vers
 * les rôles/délégations Spatie (Story 7.3).
 *
 * Usage :
 *   php artisan sambaedu:migrate-rights-to-spatie --dry-run    # Simulation
 *   php artisan sambaedu:migrate-rights-to-spatie              # Run effectif
 *
 * Idempotente : un re-run n'ajoute aucun doublon (assignRole idempotent,
 * délégations protégées par clé unique composite).
 *
 * Sortie : rapport tabulé sur stdout + persistance dans
 *   `storage/logs/migrate-rights-to-spatie-<timestamp>.log` (run effectif uniquement).
 *
 * Exit codes : 0 si OK, 1 si erreur bloquante (DB indisponible, exception).
 */
class MigrateRightsToSpatieCommand extends Command
{
    /**
     * Signature de la commande.
     *
     * @var string
     */
    protected $signature = 'sambaedu:migrate-rights-to-spatie
                            {--dry-run : N\'applique rien, affiche le plan de migration}';

    /**
     * Description de la commande.
     *
     * @var string
     */
    protected $description = 'Migre les assignations de droits bitmask legacy (branche rights_rdn + délégations scopées delegations_rdn) vers rôles et délégations Spatie (one-shot, idempotente).';

    public function __construct(
        private readonly RightsMigrationService $migrationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('==============================================================');
        $this->info('  Migration bitmask legacy → Spatie');
        $this->info('  Mode : ' . ($dryRun ? 'DRY-RUN (aucune écriture)' : 'RUN (écriture DB effective)'));
        $this->info('==============================================================');
        $this->newLine();

        // Rapport partiel par défaut : si la migration plante avant retour, on
        // veut quand même persister un log diagnostiquable (Review #5 — try/finally
        // garantit la trace même en cas d'exception, sans transaction DB englobante
        // qui interagirait mal avec les observers Spatie / cache permissions).
        $report = [
            'users_scanned'        => 0,
            'roles_assigned'       => 0,
            'delegations_created'  => 0,
            'negatives_created'    => 0,
            'fallbacks_ignored'    => 0,
            'unmappable'           => [],
            'warnings'             => ['Migration interrompue — rapport partiel.'],
        ];
        $exitCode = self::SUCCESS;

        try {
            $report = $this->migrationService->migrate(dryRun: $dryRun);
            $this->renderReport($report, $dryRun);
        } catch (Throwable $e) {
            $this->error('Erreur bloquante durant la migration : ' . $e->getMessage());
            Log::error('[MigrateRightsToSpatieCommand] Migration interrompue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $report['warnings'][] = "Exception : {$e->getMessage()}";
            $exitCode = self::FAILURE;
        } finally {
            // Persistance systématique du rapport (succès OU exception) en run
            // effectif uniquement. Le finally garantit l'écriture du log même
            // si la migration explose mid-run.
            if (! $dryRun) {
                $this->persistReportLog($report);
            }
        }

        return $exitCode;
    }

    /**
     * Affiche le rapport tabulé sur stdout.
     *
     * @param  array<string,mixed>  $report
     */
    private function renderReport(array $report, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info("{$prefix}Synthèse de la migration :");
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Users scannés',                   $report['users_scanned']],
                ['Rôles attribués',                 $report['roles_assigned']],
                ['Délégations créées (positives)',  $report['delegations_created']],
                ['Délégations créées (négatives)',  $report['negatives_created']],
                ['Fallbacks buggés ignorés',        $report['fallbacks_ignored']],
                ['Cas non mappables',               count($report['unmappable'])],
                ['Warnings',                        count($report['warnings'])],
            ]
        );

        if (! empty($report['warnings'])) {
            $this->newLine();
            $this->warn("{$prefix}Warnings :");
            foreach ($report['warnings'] as $warning) {
                $this->line("  - {$warning}");
            }
        }

        if (! empty($report['unmappable'])) {
            $this->newLine();
            $this->warn("{$prefix}Cas non mappables (détail) :");
            $rows = [];
            foreach ($report['unmappable'] as $entry) {
                $rows[] = [
                    $entry['kind'] ?? 'unknown',
                    $entry['reason'] ?? '',
                ];
            }
            $this->table(['Type', 'Raison'], $rows);
        }

        $this->newLine();
        if ($dryRun) {
            $this->info('Dry-run terminé — aucune écriture effectuée. Rejouer sans --dry-run pour appliquer.');
        } else {
            $this->info('Migration terminée avec succès.');
        }
    }

    /**
     * Persiste le rapport final dans `storage/logs/` pour audit post-migration.
     *
     * Écriture atomique (temp + rename) pour garantir qu'un lecteur externe
     * ne lira jamais un fichier partiel.
     *
     * @param  array<string,mixed>  $report
     */
    private function persistReportLog(array $report): void
    {
        $timestamp = date('Y-m-d-His');
        $logDir = storage_path('logs');
        $finalPath = "{$logDir}/migrate-rights-to-spatie-{$timestamp}.log";
        $tempPath = $finalPath . '.tmp';

        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $payload = sprintf(
            "[%s] Migration bitmask legacy → Spatie (run effectif)\n\n%s\n",
            date('Y-m-d H:i:s'),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        try {
            file_put_contents($tempPath, $payload);
            rename($tempPath, $finalPath);
            $this->info("Rapport persisté : {$finalPath}");
        } catch (Throwable $e) {
            $this->warn("Impossible de persister le rapport : {$e->getMessage()}");
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
