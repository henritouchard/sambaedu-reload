<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Services;

use App\Support\AtomicFileWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Story 15.5 / AC1.3 — Archivage best-effort des rapports WPKG bruts avant
 * parsing.
 *
 * Architecture :
 *   - Path : `{config('sambaedu.wpkg.reports_archive')}/Y/m/d/{hostname}_{YmdHis}_{sha8}.txt`
 *   - Écriture atomique via `App\Support\AtomicFileWriter::write()`.
 *   - Best-effort : si l'écriture disque échoue → log warning sur `wpkg-deploy`,
 *     on ne lance PAS d'exception (la BDD reste source de vérité pour le
 *     dashboard ; l'archive est un audit forensic).
 *
 * Idempotence : si un fichier au même path existe (cas où le SHA8 + date
 * coïncident), on n'écrase pas — `AtomicFileWriter` gère le rename atomique.
 * En pratique, le suffixe SHA8 + l'horodatage seconde rendent les collisions
 * extrêmement improbables.
 */
final class WpkgReportArchiver
{
    /**
     * Archive un rapport brut. Retourne le path d'archive sur succès,
     * `null` sur échec (best-effort).
     */
    public function archive(string $hostname, string $rawContent, string $sha256): ?string
    {
        $archiveBase = (string) config('sambaedu.wpkg.reports_archive', '');

        if ($archiveBase === '') {
            Log::channel('wpkg-deploy')->warning('[WpkgReportArchiver] reports_archive non configuré', [
                'event' => 'wpkg_report_archive_failed',
                'reason' => 'config_missing',
                'hostname' => $hostname,
            ]);

            return null;
        }

        $now = Carbon::now();
        $relativeDir = $now->format('Y/m/d');
        $sha8 = substr($sha256, 0, 8);
        $filename = sprintf(
            '%s_%s_%s.txt',
            $this->sanitizeHostname($hostname),
            $now->format('YmdHis'),
            $sha8,
        );

        $absolutePath = rtrim($archiveBase, '/') . '/' . $relativeDir . '/' . $filename;

        $written = AtomicFileWriter::write($absolutePath, $rawContent);

        if (! $written) {
            Log::channel('wpkg-deploy')->warning('[WpkgReportArchiver] écriture archive échouée', [
                'event' => 'wpkg_report_archive_failed',
                'reason' => 'atomic_write_failed',
                'hostname' => $hostname,
                'path' => $absolutePath,
            ]);

            return null;
        }

        return $absolutePath;
    }

    /**
     * Limite les caractères du hostname dans le nom de fichier pour éviter
     * une path traversal (`../`) ou des caractères non-portables.
     * Les hostnames Windows respectent normalement [A-Za-z0-9-] mais on
     * reste défensif.
     */
    private function sanitizeHostname(string $hostname): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.-]/', '_', $hostname);

        return $clean === null || $clean === '' ? 'unknown' : $clean;
    }
}
