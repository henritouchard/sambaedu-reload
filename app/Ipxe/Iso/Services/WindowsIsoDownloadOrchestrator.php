<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Services;

use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use App\Ipxe\Iso\Exceptions\WindowsIsoLockException;
use App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob;
use App\Models\WindowsIsoDownload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 3.6 — D7 / AC4.1, AC4.2 — Entry-point Livewire.
 *
 * Orchestre la soumission d'un nouveau téléchargement :
 *
 *  1. Valide l'URL (delegate à {@see WindowsIsoUrlValidator}).
 *  2. Tente d'acquérir le {@see Cache::lock} global non-bloquant
 *     `ipxe.iso.download.global` (TTL 7200s = 2h — aligné avec curl 7200 +
 *     extract 1800). Si non obtenu → {@see WindowsIsoLockException}.
 *  3. Crée la row {@see \App\Models\WindowsIsoDownload} (status=pending +
 *     audit complet).
 *  4. Dispatch le {@see DownloadWindowsIsoJob} sur la queue dédiée.
 *  5. Log info `ipxe.iso.download.submitted` (channel `ipxe`).
 *
 * Le lock est **acquis** ici (côté orchestrator/Livewire) et **release**
 * dans le `finally` du Job (D15 — ceinture + bretelles via couche
 * `WithoutOverlapping` middleware aussi).
 */
class WindowsIsoDownloadOrchestrator
{
    public function __construct(
        private readonly WindowsIsoUrlValidator $urlValidator,
    ) {}

    /**
     * @throws \App\Ipxe\Iso\Exceptions\WindowsIsoValidationException si URL invalide.
     * @throws WindowsIsoLockException si lock global indisponible.
     */
    public function submit(string $url, int $initiatedByUserId, string $hostIp): WindowsIsoDownload
    {
        // 1) Validation 2e couche (defense in depth — la 1re est Livewire rules()).
        $validated = $this->urlValidator->validate($url);

        // Opus-D — Validation host_ip via FILTER_VALIDATE_IP : si le header
        // X-Forwarded-For est forgé / contient une chaîne non-IP, on persiste
        // `null` plutôt que de stocker un payload arbitraire (log poisoning,
        // SQL injection mitigée par Eloquent mais defense in depth).
        $validatedHostIp = (filter_var($hostIp, FILTER_VALIDATE_IP) !== false) ? $hostIp : null;

        // 2) Lock global non-bloquant.
        $lockKey = (string) config('ipxe.iso_management.global_lock_key', 'ipxe.iso.download.global');
        $lockTtl = (int) config('ipxe.iso_management.global_lock_ttl', 7200);

        $lock = Cache::lock($lockKey, $lockTtl);
        if (! $lock->get()) {
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.download.rejected_locked', [
                'iso_name'   => $validated['iso_name'],
                'version'    => $validated['version'],
                'user_id'    => $initiatedByUserId,
                'host_ip'    => $validatedHostIp,
            ]);

            throw new WindowsIsoLockException(
                "Un téléchargement est déjà en cours, attendez sa fin ou annulez-le.",
            );
        }

        // 3) Row pending + dispatch Job — Opus-E — encapsulés dans
        // `DB::transaction()` pour garantir l'atomicité « row créée ⇒ Job
        // dispatché ⇒ log audit ». Si une étape échoue (ex. worker queue
        // down + driver `database` qui throw, ou DB write KO), la transaction
        // rollback => pas de row orpheline + on release le lock applicatif.
        try {
            $download = DB::transaction(function () use ($validated, $initiatedByUserId, $validatedHostIp): WindowsIsoDownload {
                $download = WindowsIsoDownload::create([
                    'version'              => $validated['version'],
                    'iso_name'             => $validated['iso_name'],
                    'source_url'           => $validated['url'],
                    'status'               => WindowsIsoDownloadStatus::Pending,
                    'initiated_by_user_id' => $initiatedByUserId,
                    'host_ip'              => $validatedHostIp,
                ]);

                // 4) Dispatch Job — la queue est configurable via .env.
                $queue = (string) config('ipxe.iso_management.queue_name', 'ipxe_iso_downloads');
                DownloadWindowsIsoJob::dispatch($download->id)->onQueue($queue);

                // 5) Audit log (URL en clair — c'est une URL publique Microsoft).
                Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.download.submitted', [
                    'download_id' => $download->id,
                    'iso_name'    => $download->iso_name,
                    'version'     => $download->version,
                    'source_url'  => $download->source_url,
                    'user_id'     => $initiatedByUserId,
                    'host_ip'     => $validatedHostIp,
                ]);

                return $download;
            });

            return $download;
        } catch (\Throwable $e) {
            // Release best-effort si on a réussi à acquérir mais échec
            // transaction (DB ou dispatch). La transaction a déjà rollback.
            try {
                $lock->release();
            } catch (\Throwable) {
                // Ignore.
            }
            throw $e;
        }
    }

    /**
     * Annule un téléchargement non-terminal (idempotent — un cancel sur un
     * download déjà terminal est no-op qui retourne false).
     *
     * Le Process curl / install-win-iso.sh en cours n'est PAS interrompu
     * (parité legacy). Le Job détectera le status `cancelled` à la prochaine
     * `refresh()` et bypassera la suite.
     */
    public function cancel(WindowsIsoDownload $download, int $cancelledByUserId): bool
    {
        if ($download->isTerminal()) {
            return false;
        }

        $stage = $download->status->value;

        $download->update([
            'status'       => WindowsIsoDownloadStatus::Cancelled,
            'completed_at' => now(),
        ]);

        // Release best-effort du lock global. Le Job, s'il était en cours,
        // ré-essaiera de release dans son finally (idempotent).
        $lockKey = (string) config('ipxe.iso_management.global_lock_key', 'ipxe.iso.download.global');
        try {
            Cache::lock($lockKey)->forceRelease();
        } catch (\Throwable) {
            // Ignore — TTL 7200s release naturellement.
        }

        Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.download.cancelled', [
            'download_id'           => $download->id,
            'iso_name'              => $download->iso_name,
            'version'               => $download->version,
            'stage'                 => $stage,
            'cancelled_by_user_id'  => $cancelledByUserId,
        ]);

        return true;
    }
}
