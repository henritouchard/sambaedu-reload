<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Story 15.5 / AC1.3 + T10 — Rotation des archives brutes des rapports WPKG.
 *
 * Supprime les fichiers `.txt` du dossier `config('sambaedu.wpkg.reports_archive')`
 * dont le `mtime` est antérieur à `now - --days` (90 jours par défaut).
 *
 * Schedulée daily à 03:00 (cf. routes/console.php).
 *
 * Sécurité :
 *   - Refuse de tourner si le path archive n'est pas configuré.
 *   - Refuse de tourner si --days < 1 (garde-fou suppression aujourd'hui).
 *   - Affiche le nombre de fichiers + la taille libérée + path de base.
 *   - Mode `--dry-run` pour vérifier sans supprimer.
 *
 * Robustesse off-by-one : un fichier dont l'âge est exactement `--days`
 * est CONSERVÉ (strict greater than). Un fichier de `--days + 1` jours
 * est supprimé.
 */
final class RotateWpkgReportArchivesCommand extends Command
{
    protected $signature = 'wpkg:reports:archive:rotate
                            {--days= : Âge maximum en jours (défaut: config sambaedu.wpkg.reports_archive_retention_days)}
                            {--dry-run : Simulation sans suppression}';

    protected $description = 'Rote les archives brutes des rapports WPKG (Story 15.5).';

    public function handle(): int
    {
        $archiveBase = (string) config('sambaedu.wpkg.reports_archive', '');

        if ($archiveBase === '' || ! is_dir($archiveBase)) {
            $this->warn("Dossier d'archive introuvable ou non configuré : '{$archiveBase}'.");

            return self::SUCCESS;
        }

        $days = $this->option('days');
        $days = $days !== null
            ? (int) $days
            : (int) config('sambaedu.wpkg.reports_archive_retention_days', 90);

        if ($days < 1) {
            $this->error('--days doit être >= 1.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subDays($days);

        $finder = new Finder();
        $finder->files()
            ->in($archiveBase)
            ->name('*.txt')
            // Strict greater-than : un fichier de pile $days jours est conservé
            // (off-by-one safety).
            ->date('< ' . $cutoff->format('Y-m-d H:i:s'));

        $deleted = 0;
        $bytesFreed = 0;

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            $size = $file->getSize();
            $path = $file->getRealPath();

            if (! $dryRun) {
                if (@unlink($path)) {
                    $deleted++;
                    $bytesFreed += $size;
                } else {
                    $this->warn("Échec suppression : {$path}");
                }
            } else {
                $deleted++;
                $bytesFreed += $size;
            }
        }

        Log::channel('wpkg-deploy')->info('[wpkg:reports:archive:rotate] rotation effectuée', [
            'event' => 'wpkg_reports_archive_rotated',
            'archive_base' => $archiveBase,
            'days' => $days,
            'cutoff' => $cutoff->toIso8601String(),
            'deleted' => $deleted,
            'bytes_freed' => $bytesFreed,
            'dry_run' => $dryRun,
        ]);

        $this->info(sprintf(
            '%s%d fichier(s) %s, %s libéré(s).',
            $dryRun ? '[DRY-RUN] ' : '',
            $deleted,
            $dryRun ? 'sélectionné(s)' : 'supprimé(s)',
            $this->humanBytes($bytesFreed),
        ));

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KiB', 'MiB', 'GiB', 'TiB'];
        $value = $bytes / 1024;
        $unit = 'KiB';
        foreach ($units as $u) {
            if ($value < 1024) {
                $unit = $u;
                break;
            }
            $value /= 1024;
            $unit = $u;
        }

        return sprintf('%.2f %s', $value, $unit);
    }
}
