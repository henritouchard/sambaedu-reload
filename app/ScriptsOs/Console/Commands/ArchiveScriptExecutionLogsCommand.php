<?php

declare(strict_types=1);

namespace App\ScriptsOs\Console\Commands;

use App\ScriptsOs\Models\ScriptExecutionLog;
use App\ScriptsOs\Support\Humanize;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Story 16.12 — AC7.1 / D9.
 *
 * Archive les rows `script_execution_logs` antérieures à `--retention-days`
 * (90j par défaut) dans des fichiers mensuels gzip JSONL puis purge la DB.
 *
 * Algorithme :
 *
 *  1. Lit `--retention-days` ou `config('scriptsos.retention_days', 90)`.
 *  2. Garde-fou `< 1` → exit 1.
 *  3. Group by month sur `started_at` (YYYY-MM).
 *  4. Pour chaque mois, ouvre `storage/archives/script-execution-logs-YYYY-MM.jsonl.gz`
 *     en mode `ab` (append-binary, idempotent — la 2ème exécution pour le
 *     même mois append les rows manquantes).
 *  5. Pour chaque row, écrit `json_encode(row->toArray()) . "\n"` via `gzwrite`.
 *  6. Après écriture OK : `DELETE FROM script_execution_logs WHERE started_at < cutoff`.
 *  7. Si `--dry-run` : compte + log, mais n'écrit ni ne supprime.
 *  8. Log info `scriptsos.archive.rotated` (channel `scriptsos`).
 *  9. Sortie console : `[OK] Archivé X rows vers Y fichiers (Z KB)`.
 * 10. Exit 0 (sauf garde-fou retention-days).
 */
final class ArchiveScriptExecutionLogsCommand extends Command
{
    protected $signature = 'script-logs:archive:rotate
                            {--retention-days= : Âge maximum en jours (défaut: config scriptsos.retention_days)}
                            {--dry-run : Simulation sans suppression DB ni écriture archive}';

    protected $description = 'Archive les logs script_execution_logs > N jours dans JSONL gzip mensuels puis purge la DB (Story 16.12).';

    public function handle(): int
    {
        $retentionDays = $this->resolveRetention();
        if ($retentionDays < 1) {
            $this->error('--retention-days doit être >= 1.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subDays($retentionDays);
        $archivePath = (string) config('scriptsos.archive.path', storage_path('archives'));

        if (! $dryRun) {
            // Crée le dossier d'archives si absent.
            if (! is_dir($archivePath)) {
                File::makeDirectory($archivePath, 0775, true, true);
            }
        }

        // Récupère la liste distincte des YYYY-MM pour les rows < cutoff.
        $monthsRows = ScriptExecutionLog::query()
            ->where('started_at', '<', $cutoff)
            ->orderBy('started_at')
            ->get(['started_at']);

        // Post code-review F5 — élimine la sentinelle `'unknown'` qui
        // provoquerait `Carbon::createFromFormat('Y-m', 'unknown')` →
        // InvalidArgumentException. `filter()` jette tous les null silencieux.
        $months = $monthsRows
            ->map(fn ($row): ?string => $row->started_at?->format('Y-m'))
            ->filter()
            ->unique()
            ->values();

        $totalDeleted = 0;
        $totalBytesArchived = 0;
        $filesWritten = 0;

        foreach ($months as $ym) {
            try {
                [$rowsInMonth, $bytesArchived] = $this->processMonth($ym, $cutoff, $archivePath, $dryRun);
            } catch (\Throwable $e) {
                $this->error("Échec archivage mois {$ym} : " . $e->getMessage());
                Log::channel('scriptsos')->error('scriptsos.archive.failed', [
                    'event' => 'scriptsos.archive.failed',
                    'month' => $ym,
                    'error' => $e->getMessage(),
                    'dry_run' => $dryRun,
                ]);

                return self::FAILURE;
            }

            $totalDeleted += $rowsInMonth;
            $totalBytesArchived += $bytesArchived;
            if ($bytesArchived > 0 || $dryRun) {
                $filesWritten++;
            }
        }

        // Post code-review Opus-C — invalide le cache stats 60s du dashboard
        // dès qu'on a effectivement supprimé des rows. Sinon les totaux/échecs
        // affichés dans l'UI continuent à compter les lignes archivées
        // jusqu'à expiration TTL (UX trompeur post-rotation).
        if (! $dryRun && $totalDeleted > 0) {
            app(\App\ScriptsOs\Services\ScriptExecutionLogStatsService::class)->flushCache();
        }

        Log::channel('scriptsos')->info('scriptsos.archive.rotated', [
            'event' => 'scriptsos.archive.rotated',
            'cutoff' => $cutoff->toIso8601String(),
            'deleted_rows' => $totalDeleted,
            'bytes_archived' => $totalBytesArchived,
            'archive_files_written' => $filesWritten,
            'dry_run' => $dryRun,
        ]);

        $this->info(sprintf(
            '%s%s %d rows vers %d fichier(s) (%s).',
            $dryRun ? '[DRY-RUN] ' : '',
            $dryRun ? 'Aurait archivé' : 'Archivé',
            $totalDeleted,
            $filesWritten,
            Humanize::bytes($totalBytesArchived),
        ));

        return self::SUCCESS;
    }

    private function resolveRetention(): int
    {
        $opt = $this->option('retention-days');
        if ($opt !== null && $opt !== '') {
            return (int) $opt;
        }

        return (int) config('scriptsos.retention_days', 90);
    }

    /**
     * Archive un mois donné. Retourne [rowsCount, bytesWritten].
     *
     * @return array{0:int, 1:int}
     */
    private function processMonth(string $ym, Carbon $cutoff, string $archivePath, bool $dryRun): array
    {
        $start = Carbon::createFromFormat('Y-m', $ym)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        // Borne supérieure = min(end_of_month, cutoff) — on n'archive jamais
        // les rows post-cutoff (cas où le cutoff tombe au milieu du mois).
        if ($end->isAfter($cutoff)) {
            $end = $cutoff->copy();
        }

        $filename = sprintf('script-execution-logs-%s.jsonl.gz', $ym);
        $fullPath = rtrim($archivePath, '/') . '/' . $filename;

        $rowCount = 0;
        $bytesWritten = 0;

        if ($dryRun) {
            // Dry-run : on compte seulement, on n'écrit ni ne supprime.
            $rowCount = ScriptExecutionLog::query()
                ->where('started_at', '>=', $start)
                ->where('started_at', '<', $end)
                ->count();

            return [$rowCount, 0];
        }

        // gzopen append-binary — idempotent : si le fichier existe, on
        // append (cas commande tournée 2x pour le même mois après ajout
        // tardif de logs).
        $fp = @gzopen($fullPath, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Impossible d'ouvrir $fullPath en écriture (permissions ?).");
        }

        try {
            ScriptExecutionLog::query()
                ->where('started_at', '>=', $start)
                ->where('started_at', '<', $end)
                ->orderBy('started_at')
                ->chunk(500, function ($chunk) use ($fp, &$rowCount, &$bytesWritten): void {
                    foreach ($chunk as $log) {
                        $json = json_encode($log->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        if ($json === false) {
                            continue;
                        }
                        $line = $json . "\n";
                        $written = gzwrite($fp, $line);
                        if ($written === false || $written === 0) {
                            throw new \RuntimeException('gzwrite failed — disque plein ou IO error ?');
                        }
                        $bytesWritten += strlen($line);
                        $rowCount++;
                    }
                });
        } finally {
            gzclose($fp);
        }

        // Une fois l'écriture archive OK : DELETE des rows correspondantes.
        if ($rowCount > 0) {
            DB::table('script_execution_logs')
                ->where('started_at', '>=', $start)
                ->where('started_at', '<', $end)
                ->delete();
        }

        return [$rowCount, $bytesWritten];
    }
}
