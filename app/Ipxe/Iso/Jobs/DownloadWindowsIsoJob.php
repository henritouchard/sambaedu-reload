<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Jobs;

use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use App\Ipxe\Iso\Exceptions\WindowsIsoExtractionException;
use App\Ipxe\Iso\Services\WindowsIsoExtractor;
use App\Models\WindowsIsoDownload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Story 3.6 — D8 / D15 / AC4.3-AC4.7 — Job Laravel Queue qui exécute :
 *
 *  1. `curl -fSL --max-time {timeout} -o '<iso_path>' '<url>'`
 *     → status pending → downloading.
 *  2. Extraction native via {@see \App\Ipxe\Iso\Services\WindowsIsoExtractor}
 *     (montage loop + copie vers `{deployed_os_base_path}/Win{N}`, port du
 *     legacy `install-win-iso.sh`) → status downloading → extracting.
 *  3. Soit `success` (exit_code=0), soit `failed` (exit_code ≠ 0 + stderr abrégé).
 *
 * Sécurité (D5 + D8) :
 *  - **`escapeshellarg()` systématique** sur tous les arguments shell.
 *  - **`Process::run(string)` mode shellline** uniquement quand on a besoin du
 *    sudo + redirect stdout vers fichier. Pour le sudo on construit la
 *    commande complète avec `escapeshellarg()` puis on passe en shell mode.
 *  - **`tries=1`** — un échec curl ou extraction est terminal.
 *  - **`WithoutOverlapping`** Job middleware (defense in depth couche 2 vs
 *    Cache::lock applicatif couche 1).
 *  - **`timeout=9300s`** (Q1 Henri 2026-05-21 = curl 7200 + extract 1800 +
 *    marge globale 300s ; configurable via env `IPXE_ISO_DOWNLOAD_TIMEOUT` +
 *    `IPXE_ISO_EXTRACT_TIMEOUT`).
 *  - **Capture stdout/stderr ≤ 2000 chars** dans `error` (DB).
 *  - **Tail stdout/stderr 200 chars** dans le channel log `ipxe`.
 *
 * Concurrence (D15) :
 *  - `Cache::lock('ipxe.iso.download.global')->release()` dans `finally`
 *    quel que soit le terminus (success, failed, cancelled, exception).
 *  - Le lock est posé côté orchestrator (couche 1). Le Job le release.
 *
 * Cas spéciaux :
 *  - Si admin annule entre dispatch et pickup → Job log skip + release lock.
 *  - Si admin annule entre curl et extract → Job log skip + bypass extract.
 *  - Si exception Throwable → status=failed + log error + release lock.
 */
class DownloadWindowsIsoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Pas de retry — un échec est terminal (un retry curl/extract n'a aucun sens).
     */
    public int $tries = 1;

    /**
     * Timeout global du Job — Q1 Henri post-review (2026-05-21).
     *
     * Calculé dynamiquement comme `curl + extract + marge globale 300s` —
     * la marge couvre l'overhead Symfony Process + signal handling +
     * release lock dans `finally`. Plus de marge `+60s` sur les Process
     * individuels : la marge vit ici, au niveau Job.
     *
     * Par défaut : 7200 + 1800 + 300 = 9300s = 2h35.
     */
    public int $timeout;

    public function __construct(
        public readonly int $downloadId,
    ) {
        $this->timeout = (int) config('ipxe.iso_management.download_timeout_seconds', 7200)
            + (int) config('ipxe.iso_management.extract_timeout_seconds', 1800)
            + 300;
    }

    /** Tronquage stderr/stdout en DB (≤ 2000 chars applicatif). */
    private const STDERR_MAX = 2000;

    /** Tronquage tail logs channel ipxe (anti-pollution). */
    private const STDERR_LOG_TAIL = 200;

    /**
     * Middleware Laravel Queue — defense in depth couche 2 vs Cache::lock
     * applicatif couche 1.
     *
     * Le key est global (pas par-download_id) — c'est le point clé : il
     * empêche 2 Jobs concurrents de manipuler simultanément
     * `/var/sambaedu/unattended/install/os/Win{10,11}/*` (corruption potentielle
     * par l'extraction native qui rotate Win{N} → Win{N}-old).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // `WithoutOverlapping` RETIRÉ (2026-06-22) : ce middleware s'appuie sur
        // le cache PAR DÉFAUT (APCu) qui n'implémente pas lock() → lève
        // « undefined method ApcStore::lock() » au pickup du Job, et il n'expose
        // aucune API pour lui passer un store lock-capable. Le mutex global reste
        // porté par le lock file de l'orchestrator (couche 1, store `file`) + les
        // transitions sous lockForUpdate dans handle(). En conditions normales un
        // seul Job ISO tourne à la fois (lock applicatif + worker unique).
        return [];
    }

    public function handle(): void
    {
        /** @var WindowsIsoDownload|null $download */
        $download = WindowsIsoDownload::find($this->downloadId);
        $lockKey = (string) config('ipxe.iso_management.global_lock_key', 'ipxe.iso.download.global');

        if ($download === null) {
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->warning('ipxe.iso.download.row_missing', [
                'download_id' => $this->downloadId,
            ]);
            $this->releaseLock($lockKey);

            return;
        }

        // Pre-flight : annulé entre dispatch et pickup.
        if ($download->status === WindowsIsoDownloadStatus::Cancelled) {
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.download.skipped_cancelled', [
                'download_id' => $download->id,
                'iso_name'    => $download->iso_name,
            ]);
            $this->releaseLock($lockKey);

            return;
        }

        $isoStoragePath = (string) config('ipxe.iso_management.iso_storage_path', storage_path('install/iso'));
        $isoPath        = rtrim($isoStoragePath, '/') . '/' . $download->iso_name;

        // Dépôt manuel : le fichier est déjà sur disque (renommé par
        // l'orchestrator). On saute toute la phase curl et on passe directement
        // à l'extraction.
        $isUpload = $download->isUpload();

        try {
            // === Phase 1 : Downloading (curl) — flux URL uniquement ===========
            //
            // #14 — Transition pending → downloading sous lockForUpdate + check
            // status pour éviter écrasement d'un cancel concurrent (race
            // PostgreSQL). Si annulé entre dispatch et pickup => log + return.
            if (! $isUpload) {
                $shouldContinue = DB::transaction(function () use ($download): bool {
                    /** @var WindowsIsoDownload|null $fresh */
                    $fresh = WindowsIsoDownload::query()
                        ->where('id', $download->id)
                        ->lockForUpdate()
                        ->first();
                    if ($fresh === null || $fresh->status === WindowsIsoDownloadStatus::Cancelled) {
                        return false;
                    }
                    $fresh->update([
                        'status'     => WindowsIsoDownloadStatus::Downloading,
                        'started_at' => now(),
                    ]);

                    return true;
                });

                if (! $shouldContinue) {
                    Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.download.skipped_cancelled', [
                        'download_id' => $download->id,
                        'iso_name'    => $download->iso_name,
                        'phase'       => 'pre-download',
                    ]);

                    return;
                }

                $curlTimeout = (int) config('ipxe.iso_management.download_timeout_seconds', 7200);

                // escapeshellarg systématique : `iso_path` (chemin construit
                // côté serveur, mais defense in depth) + `source_url`
                // (saisie utilisateur — validée mais on protège).
                $curlCmd = sprintf(
                    'curl -fSL --max-time %d -o %s %s',
                    $curlTimeout,
                    escapeshellarg($isoPath),
                    escapeshellarg((string) $download->source_url),
                );

                // Q1 Henri 2026-05-21 : Process::timeout strict (pas de marge +60s
                // — la marge globale vit dans `$this->timeout` au niveau Job).
                $curlResult = Process::timeout($curlTimeout)->run($curlCmd);

                if (! $curlResult->successful()) {
                    // Opus-A : cleanup best-effort du fichier ISO partiel.
                    $this->cleanupPartialIso($isoPath, 'curl-failed');
                    $this->markFailed($download, $curlResult->exitCode() ?? -1, $curlResult->errorOutput() ?: $curlResult->output(), 'curl-failed');

                    return;
                }
            } elseif (! is_file($isoPath)) {
                // Dépôt manuel mais fichier absent (rename KO / purge externe) —
                // échec terminal explicite plutôt que de lancer un extract sur rien.
                $this->markFailed($download, -1, 'Fichier déposé introuvable : ' . $isoPath, 'upload-missing');

                return;
            }

            // === Phase 2 : Extracting (extraction native, WindowsIsoExtractor) =
            //
            // #14 + Q3 — Transition (downloading|pending) → extracting sous
            // lockForUpdate + check status cancelled. Si annulé avant extract
            // => bypass + return sans écraser le status `cancelled`.
            //
            // `started_at` est posé ici si absent (cas upload : pas de phase
            // download qui l'aurait déjà renseigné).
            $shouldExtract = DB::transaction(function () use ($download): bool {
                /** @var WindowsIsoDownload|null $fresh */
                $fresh = WindowsIsoDownload::query()
                    ->where('id', $download->id)
                    ->lockForUpdate()
                    ->first();
                if ($fresh === null || $fresh->status === WindowsIsoDownloadStatus::Cancelled) {
                    return false;
                }
                $update = ['status' => WindowsIsoDownloadStatus::Extracting];
                if ($fresh->started_at === null) {
                    $update['started_at'] = now();
                }
                $fresh->update($update);

                return true;
            });

            if (! $shouldExtract) {
                Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.download.cancelled_after_curl', [
                    'download_id' => $download->id,
                    'iso_name'    => $download->iso_name,
                ]);

                return;
            }

            $extractTimeout = (int) config('ipxe.iso_management.extract_timeout_seconds', 1800);

            // Extraction native (port du legacy install-win-iso.sh) : montage
            // loop de l'ISO + copie vers {deployed_os_base_path}/Win{N}. La
            // source est lue depuis la config SE5 (plus de chemin codé en dur),
            // et le `apt --reinstall sambaedu-client-windows` legacy est retiré.
            // Un échec lève WindowsIsoExtractionException (exit code + stderr).
            try {
                app(WindowsIsoExtractor::class)->extract($download->version, $isoPath, $extractTimeout);
            } catch (WindowsIsoExtractionException $e) {
                $this->markFailed($download, $e->exitCode, $e->getMessage(), 'extract-failed');

                return;
            }

            // === Phase 3 : Success ============================================
            //
            // Q3 Henri 2026-05-21 : 2e `refresh()` AVANT la transition vers
            // `success`. AC4.6 étendu : si l'admin a annulé EN COURS d'extract
            // (rare mais possible si extract est court ou si le polling 60s
            // décide tardivement), on détecte le cancel et on ne l'écrase
            // PAS avec `success`. Le contrat reste : un status `cancelled`
            // n'est jamais écrasé silencieusement.
            //
            // #14 — Transition extracting → success sous lockForUpdate +
            // check status. Si annulé entre extract et success => log + return
            // sans écrasement.
            $transitionedToSuccess = DB::transaction(function () use ($download): bool {
                /** @var WindowsIsoDownload|null $fresh */
                $fresh = WindowsIsoDownload::query()
                    ->where('id', $download->id)
                    ->lockForUpdate()
                    ->first();
                if ($fresh === null || $fresh->status === WindowsIsoDownloadStatus::Cancelled) {
                    return false;
                }
                $fresh->update([
                    'status'       => WindowsIsoDownloadStatus::Success,
                    'completed_at' => now(),
                    'exit_code'    => 0,
                ]);

                return true;
            });

            if (! $transitionedToSuccess) {
                Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.download.cancelled_after_extract', [
                    'download_id' => $download->id,
                    'iso_name'    => $download->iso_name,
                ]);

                return;
            }

            $download->refresh();
            $duration = optional($download->started_at)->diffInSeconds(now()) ?? 0;
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.download.success', [
                'download_id'       => $download->id,
                'iso_name'          => $download->iso_name,
                'version'           => $download->version,
                'duration_seconds'  => $duration,
            ]);
        } catch (Throwable $e) {
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->error('ipxe.iso.download.exception', [
                'download_id' => $download->id,
                'iso_name'    => $download->iso_name,
                'exception'   => $e::class,
                'message'     => $e->getMessage(),
            ]);

            // Opus-A : best-effort cleanup ISO partiel si on a déjà téléchargé
            // (l'exception peut arriver après le curl OK).
            $this->cleanupPartialIso($isoPath, 'exception');

            $download->update([
                'status'       => WindowsIsoDownloadStatus::Failed,
                'completed_at' => now(),
                'exit_code'    => -1,
                'error'        => substr('[exception] ' . $e->getMessage(), 0, self::STDERR_MAX),
            ]);
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    /**
     * Handler de défaillance globale Laravel (appelé si `handle()` throw
     * sans être catché — garde-fou pour ne pas laisser une row coincée).
     *
     * Opus-C : release du lock AVANT les guards `if ($download === null || ...)`
     * — sinon un row supprimé manuellement (admin DB) laisse le lock zombi
     * 7200s. Toujours release puis return.
     */
    public function failed(?Throwable $exception): void
    {
        $this->releaseLock(
            (string) config('ipxe.iso_management.global_lock_key', 'ipxe.iso.download.global'),
        );

        $download = WindowsIsoDownload::find($this->downloadId);
        if ($download === null || $download->isTerminal()) {
            return;
        }

        $download->update([
            'status'       => WindowsIsoDownloadStatus::Failed,
            'completed_at' => now(),
            'exit_code'    => -1,
            'error'        => substr(
                '[job-failed] ' . ($exception?->getMessage() ?? 'Job en échec sans exception.'),
                0,
                self::STDERR_MAX,
            ),
        ]);
    }

    /**
     * Marque la row failed + log error channel ipxe avec stderr tail.
     */
    private function markFailed(WindowsIsoDownload $download, int $exitCode, string $rawStderr, string $stage): void
    {
        $stderrTail   = substr($rawStderr, -self::STDERR_MAX);
        $stderrLogTail = substr($rawStderr, -self::STDERR_LOG_TAIL);

        $download->update([
            'status'       => WindowsIsoDownloadStatus::Failed,
            'completed_at' => now(),
            'exit_code'    => $exitCode,
            'error'        => '[' . $stage . '] ' . $stderrTail,
        ]);

        Log::channel((string) config('ipxe.log.channel', 'ipxe'))->error('ipxe.iso.download.' . $stage, [
            'download_id'    => $download->id,
            'iso_name'       => $download->iso_name,
            'version'        => $download->version,
            'exit_code'      => $exitCode,
            'stderr_prefix'  => $stderrLogTail,
        ]);
    }

    /**
     * Release best-effort du lock global. Idempotent — un release sur un
     * lock déjà libre est no-op. Sur `forceRelease` côté cancel, on tolère
     * également l'absence.
     */
    private function releaseLock(string $lockKey): void
    {
        try {
            // Même store que l'orchestrator (file par défaut) : le cache APCu
            // par défaut ne supporte pas lock() et est per-process. Cf.
            // App\SystemStatus\DistroInstallTracker.
            Cache::store((string) config('ipxe.iso_management.lock_store', 'file'))
                ->lock($lockKey)
                ->forceRelease();
        } catch (Throwable) {
            // Ignore — TTL release naturellement.
        }
    }

    /**
     * Opus-A — Cleanup best-effort du fichier ISO partiel après échec curl
     * (ou exception). Évite que 5 retries successifs accumulent ~30 Go
     * d'ISO partielles sur une VM 100 Go.
     *
     * Idempotent : si le fichier n'existe pas (curl n'a même pas démarré),
     * no-op silencieux. Les erreurs `@unlink()` sont absorbées (best-effort).
     */
    private function cleanupPartialIso(string $isoPath, string $reason): void
    {
        try {
            if (! is_file($isoPath)) {
                return;
            }
            $size = @filesize($isoPath) ?: 0;
            if (@unlink($isoPath)) {
                Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.cleanup.partial_removed', [
                    'download_id' => $this->downloadId,
                    'iso_path'    => $isoPath,
                    'size_bytes'  => $size,
                    'reason'      => $reason,
                ]);
            }
        } catch (Throwable $e) {
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->warning('ipxe.iso.cleanup.partial_failed', [
                'download_id' => $this->downloadId,
                'iso_path'    => $isoPath,
                'reason'      => $reason,
                'message'     => $e->getMessage(),
            ]);
        }
    }
}
