<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Services;

use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use App\Ipxe\Iso\Exceptions\WindowsIsoLockException;
use App\Ipxe\Iso\Exceptions\WindowsIsoValidationException;
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
     * Store du lock global. Le cache par défaut (APCu) ne supporte PAS
     * `Cache::lock()` (« undefined method ApcStore::lock() ») et est per-process
     * (invisible entre PHP-FPM et le worker qui release). On force donc un store
     * partagé lock-capable — convention {@see \App\SystemStatus\DistroInstallTracker}.
     */
    private function lockStore(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store((string) config('ipxe.iso_management.lock_store', 'file'));
    }

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

        $lock = $this->lockStore()->lock($lockKey, $lockTtl);
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
     * Dépôt manuel d'une ISO (uploader chunké). Le fichier est déjà
     * réassemblé sur disque (`$assembledPath`, un `.part` produit par le
     * {@see \App\Http\Controllers\Ipxe\WindowsIsoUploadController}). On le
     * valide, acquiert le même lock global que le flux URL (upload et
     * download sont mutuellement exclusifs — tous deux touchent
     * `Win{10,11}/`), puis on renomme atomiquement le `.part` vers
     * `{iso_storage_path}/{iso_name}` avant de créer la row (`source=upload`,
     * `source_url=null`) et de dispatcher le Job (qui sautera la phase curl).
     *
     * Le rename est atomique car `upload_tmp_path` et `iso_storage_path` sont
     * sur le même filesystem (cf. config) — pas de copie 2× l'espace disque.
     *
     * @param  string  $assembledPath  Chemin du `.part` réassemblé (déjà complet).
     * @param  string  $filename       Nom de fichier brut (validé ici).
     * @param  string  $version        'Win10' | 'Win11' (select admin).
     *
     * @throws \App\Ipxe\Iso\Exceptions\WindowsIsoValidationException si nom/version invalide ou fichier absent.
     * @throws WindowsIsoLockException si lock global indisponible.
     */
    public function submitUpload(
        string $assembledPath,
        string $filename,
        string $version,
        int $initiatedByUserId,
        string $hostIp,
    ): WindowsIsoDownload {
        // 1) Validation nom de fichier + version (defense in depth — déjà
        //    validés côté controller au 1er chunk + côté Livewire).
        $validated = $this->urlValidator->validateUploadFilename($filename, $version);

        // Le fichier réassemblé doit exister et être non-vide.
        if (! is_file($assembledPath) || (int) @filesize($assembledPath) <= 0) {
            throw new \App\Ipxe\Iso\Exceptions\WindowsIsoValidationException(
                "Fichier déposé introuvable ou vide — relancez le dépôt.",
            );
        }

        $validatedHostIp = (filter_var($hostIp, FILTER_VALIDATE_IP) !== false) ? $hostIp : null;

        // 2) Lock global non-bloquant (partagé avec le flux URL).
        $lockKey = (string) config('ipxe.iso_management.global_lock_key', 'ipxe.iso.download.global');
        $lockTtl = (int) config('ipxe.iso_management.global_lock_ttl', 7200);

        $lock = $this->lockStore()->lock($lockKey, $lockTtl);
        if (! $lock->get()) {
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.upload.rejected_locked', [
                'iso_name' => $validated['iso_name'],
                'version'  => $validated['version'],
                'user_id'  => $initiatedByUserId,
                'host_ip'  => $validatedHostIp,
            ]);

            // On NE supprime PAS le `.part` : l'admin pourra relancer le dépôt
            // (finalize) une fois le download/upload en cours terminé, sans
            // re-téléverser plusieurs Go.
            throw new WindowsIsoLockException(
                "Une opération ISO est déjà en cours, attendez sa fin ou annulez-la.",
            );
        }

        try {
            // 3) Rename atomique `.part` → destination finale, AVANT la
            //    transaction DB (un échec FS ne doit pas laisser de row).
            $isoStoragePath = (string) config('ipxe.iso_management.iso_storage_path', storage_path('install/iso'));
            $finalPath = rtrim($isoStoragePath, '/') . '/' . $validated['iso_name'];

            if (! @rename($assembledPath, $finalPath)) {
                throw new \App\Ipxe\Iso\Exceptions\WindowsIsoValidationException(
                    "Impossible de déposer le fichier dans le dossier des ISO "
                    . "(vérifiez les droits filesystem de `www-admin`).",
                );
            }

            // 4) Row + dispatch dans une transaction (atomicité — iso submit()).
            $download = DB::transaction(function () use ($validated, $initiatedByUserId, $validatedHostIp): WindowsIsoDownload {
                $download = WindowsIsoDownload::create([
                    'version'              => $validated['version'],
                    'iso_name'             => $validated['iso_name'],
                    'source_url'           => null,
                    'source'               => WindowsIsoDownload::SOURCE_UPLOAD,
                    'status'               => WindowsIsoDownloadStatus::Pending,
                    'initiated_by_user_id' => $initiatedByUserId,
                    'host_ip'              => $validatedHostIp,
                ]);

                $queue = (string) config('ipxe.iso_management.queue_name', 'ipxe_iso_downloads');
                DownloadWindowsIsoJob::dispatch($download->id)->onQueue($queue);

                Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.upload.submitted', [
                    'download_id' => $download->id,
                    'iso_name'    => $download->iso_name,
                    'version'     => $download->version,
                    'user_id'     => $initiatedByUserId,
                    'host_ip'     => $validatedHostIp,
                ]);

                return $download;
            });

            return $download;
        } catch (\Throwable $e) {
            try {
                $lock->release();
            } catch (\Throwable) {
                // Ignore.
            }
            throw $e;
        }
    }

    /**
     * Story 3.10 — Ré-injecte les pilotes NIC dans une ISO **déjà déployée**,
     * sans re-télécharger. L'ISO source est conservée sur disque après un
     * déploiement réussi ; on relance simplement l'extraction, qui re-copie un
     * `boot.wim` frais (pristine) puis y ré-injecte le pack de pilotes courant
     * (idempotence par construction — cf. {@see WinpeDriverInjector}).
     *
     * Réutilise intégralement {@see DownloadWindowsIsoJob} : la row `source =
     * reinject` fait sauter la phase curl (le fichier est déjà là), exactement
     * comme un dépôt manuel. Même lock global que download/upload (les trois
     * touchent `Win{N}/` — mutuellement exclusifs).
     *
     * @param  string  $version  'Win10' | 'Win11'.
     *
     * @throws WindowsIsoValidationException si version invalide, aucune ISO
     *                                       déployée pour cette version, ou ISO
     *                                       source absente du disque.
     * @throws WindowsIsoLockException si lock global indisponible.
     */
    public function resubmitExtraction(
        string $version,
        int $initiatedByUserId,
        string $hostIp,
        ?WindowsIsoSourcesReader $sourcesReader = null,
    ): WindowsIsoDownload {
        // 1) Whitelist version (interpolée dans des chemins côté extracteur).
        if (! in_array($version, WindowsIsoDownload::VERSIONS, true)) {
            throw new WindowsIsoValidationException("Version Windows non supportée : {$version}");
        }

        // 2) ISO actuellement déployée pour cette version — source de vérité =
        //    le fichier `version` lu par le reader (= ce que l'UI affiche).
        $sourcesReader ??= app(WindowsIsoSourcesReader::class);
        $sources = $sourcesReader->list();
        $isoName = $sources[strtolower($version)]['current'] ?? null;

        if ($isoName === null) {
            throw new WindowsIsoValidationException(
                "Aucune version {$version} déployée — rien à réinjecter.",
            );
        }

        // 3) L'ISO source doit être encore présente (conservée au succès, mais
        //    peut avoir été purgée manuellement). Sinon : re-téléchargement requis.
        $isoStoragePath = (string) config('ipxe.iso_management.iso_storage_path', storage_path('install/iso'));
        $isoPath = rtrim($isoStoragePath, '/') . '/' . $isoName;
        if (! is_file($isoPath)) {
            throw new WindowsIsoValidationException(
                "L'ISO source « {$isoName} » n'est plus présente sur le serveur — "
                . "re-téléchargez-la pour réappliquer les pilotes.",
            );
        }

        $validatedHostIp = (filter_var($hostIp, FILTER_VALIDATE_IP) !== false) ? $hostIp : null;

        // 4) Lock global non-bloquant (partagé avec les flux URL + upload).
        $lockKey = (string) config('ipxe.iso_management.global_lock_key', 'ipxe.iso.download.global');
        $lockTtl = (int) config('ipxe.iso_management.global_lock_ttl', 7200);

        $lock = $this->lockStore()->lock($lockKey, $lockTtl);
        if (! $lock->get()) {
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.reinject.rejected_locked', [
                'iso_name' => $isoName,
                'version'  => $version,
                'user_id'  => $initiatedByUserId,
                'host_ip'  => $validatedHostIp,
            ]);

            throw new WindowsIsoLockException(
                "Une opération ISO est déjà en cours, attendez sa fin ou annulez-la.",
            );
        }

        try {
            $download = DB::transaction(function () use ($version, $isoName, $initiatedByUserId, $validatedHostIp): WindowsIsoDownload {
                $download = WindowsIsoDownload::create([
                    'version'              => $version,
                    'iso_name'             => $isoName,
                    'source_url'           => null,
                    'source'               => WindowsIsoDownload::SOURCE_REINJECT,
                    'status'               => WindowsIsoDownloadStatus::Pending,
                    'initiated_by_user_id' => $initiatedByUserId,
                    'host_ip'              => $validatedHostIp,
                ]);

                $queue = (string) config('ipxe.iso_management.queue_name', 'ipxe_iso_downloads');
                DownloadWindowsIsoJob::dispatch($download->id)->onQueue($queue);

                Log::channel((string) config('ipxe.log.channel', 'ipxe'))->info('ipxe.iso.reinject.submitted', [
                    'download_id' => $download->id,
                    'iso_name'    => $download->iso_name,
                    'version'     => $download->version,
                    'user_id'     => $initiatedByUserId,
                    'host_ip'     => $validatedHostIp,
                ]);

                return $download;
            });

            return $download;
        } catch (\Throwable $e) {
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
     * Le Process curl / l'extraction en cours n'est PAS interrompu
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
            $this->lockStore()->lock($lockKey)->forceRelease();
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
