<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Services;

use App\Ipxe\Iso\Exceptions\WindowsIsoExtractionException;
use Illuminate\Support\Facades\Process;

/**
 * Extraction native d'une ISO Windows vers l'arborescence d'install servie
 * aux postes — **port du legacy `install-win-iso.sh`** (cf. paquet SE4
 * `sambaedu-config`) directement dans le code SE5.
 *
 * Pourquoi natif et plus un `.sh` externe (décision 2026-06-22) :
 *  - Le script legacy n'était dans aucun paquet déployé (loose, absent VM).
 *  - Il codait en dur l'ANCIEN chemin source `/var/sambaedu/.../os/iso/` —
 *    incompatible avec le déplacement des sources sous `storage/install/iso`
 *    [[project_iso_storage_relocated_and_pipeline_gaps]]. Ici la source vient
 *    de la config SE5 (`ipxe.iso_management.iso_storage_path`, passée par le
 *    Job) → plus de chemin codé en dur.
 *  - On retire le `apt-get install --reinstall sambaedu-client-windows` final
 *    (couplage deb SE4, direction SE5-autonome [[project_windows_install_helpers_oem_staging]]).
 *
 * Mécanique (parité legacy) : montage loop read-only de l'ISO → copie du
 * contenu vers `{deployed_os_base_path}/Win{N}` → fichier `version` (lu par
 * {@see WindowsIsoSourcesReader}) → `chmod 666` du `boot.wim`. Le `mount` /
 * `umount` exigent root : le worker tourne en `www-admin` qui a `sudo`
 * (sudoers `www-admin … NOPASSWD`). Aucun extracteur userspace requis (le
 * montage noyau lit l'UDF + les fichiers > 4 Go des ISO Windows nativement).
 */
final class WindowsIsoExtractor
{
    private const ALLOWED_VERSIONS = ['Win10', 'Win11'];

    /**
     * Monte l'ISO et déploie son contenu dans `{deployed_os_base_path}/{version}`.
     *
     * @param  string  $version  `Win10` | `Win11` (interpolé dans des chemins → strictement validé).
     * @param  string  $isoPath  Chemin absolu de l'ISO source (déjà sur disque).
     * @param  int|null  $timeoutSeconds  Timeout par commande (défaut config extract).
     *
     * @throws WindowsIsoExtractionException Si une étape (mount/cleanup/copy) échoue.
     */
    public function extract(string $version, string $isoPath, ?int $timeoutSeconds = null): void
    {
        if (! in_array($version, self::ALLOWED_VERSIONS, true)) {
            throw new WindowsIsoExtractionException("Version Windows non supportée : {$version}");
        }
        if (! is_file($isoPath)) {
            throw new WindowsIsoExtractionException("ISO source introuvable : {$isoPath}");
        }

        $base = rtrim(
            (string) config('ipxe.iso_management.deployed_os_base_path', '/var/sambaedu/unattended/install/os'),
            '/',
        );
        $target  = $base . '/' . $version;
        $timeout = $timeoutSeconds ?? (int) config('ipxe.iso_management.extract_timeout_seconds', 1800);

        $mountDir = rtrim((string) config('sambaedu.windows_iso.extract_mount_dir', sys_get_temp_dir()), '/')
            . '/se5-winiso-' . getmypid() . '-' . uniqid();

        if (! is_dir($mountDir) && ! @mkdir($mountDir, 0700, true) && ! is_dir($mountDir)) {
            throw new WindowsIsoExtractionException("Point de montage non créable : {$mountDir}");
        }

        // Montage loop ro (root). Si ça échoue, rien n'est monté → pas de
        // umount à tenter, on sort directement en exception.
        $this->runOrThrow(
            sprintf('sudo -n mount -o loop,ro %s %s', escapeshellarg($isoPath), escapeshellarg($mountDir)),
            $timeout,
            'mount',
        );

        try {
            // Sauvegarde best-effort de l'ancien boot.wim (peut contenir des
            // drivers réseau/disque injectés par DISM — cf. message legacy).
            $oldBootWim = $target . '/sources/boot.wim';
            if (is_file($oldBootWim)) {
                @copy($oldBootWim, $base . '/boot.wim-' . $version . '-old');
            }

            // Purge de l'ancienne version puis copie fraîche. Tree-mutation via
            // sudo (l'ancien Win{N} peut être root-owned d'un déploiement
            // legacy), puis chown www-admin pour que Samba/Apache servent +
            // que les écritures `version`/chmod suivantes passent en www-admin.
            $this->runOrThrow(sprintf('sudo -n rm -rf %s', escapeshellarg($target)), $timeout, 'cleanup');
            $this->runOrThrow(sprintf('sudo -n mkdir -p %s', escapeshellarg($target)), $timeout, 'mkdir');
            // `/.` (et non `/*`) pour inclure les éventuels fichiers cachés.
            $this->runOrThrow(
                sprintf('sudo -n cp -R %s %s', escapeshellarg($mountDir . '/.'), escapeshellarg($target . '/')),
                $timeout,
                'copy',
            );
            $this->runOrThrow(
                sprintf('sudo -n chown -R www-admin:www-admin %s', escapeshellarg($target)),
                $timeout,
                'chown',
            );

            // Fichier `version` = nom de l'ISO (lu par WindowsIsoSourcesReader
            // pour afficher la version courante). www-admin possède le tree.
            @file_put_contents($target . '/version', basename($isoPath) . "\n");

            // Parité legacy : boot.wim world-readable/writable (servi en SMB).
            @chmod($target . '/sources/boot.wim', 0666);

            // Helpers WinPE (`wimboot` + `winpeshl.ini`) — NON présents dans
            // l'ISO Windows (ce sont des assets iPXE/SambaEdu). Semés depuis le
            // dossier versionné SE5 vers `{base}/<wimboot_base>` à chaque
            // extraction, garantissant leur présence dès qu'un ISO est déployé.
            // Remplace l'ancienne livraison par le paquet SE4
            // `sambaedu-client-windows` (direction SE5-autonome).
            $this->seedWinpeHelpers($base, $timeout);
        } finally {
            // Démontage + nettoyage du point de montage, quel que soit l'issue.
            Process::timeout($timeout)->run(sprintf('sudo -n umount %s', escapeshellarg($mountDir)));
            @rmdir($mountDir);
        }
    }

    /**
     * Copie les helpers WinPE versionnés (`wimboot` + `winpeshl.ini`) vers
     * `{base}/<wimboot_base>` (par défaut `winpe`), confiné sous le
     * `deployed_os_base_path` (donc servi par `/ipxe/os/{path}` + X-Sendfile).
     *
     * Partagé Win10/Win11 (le `wimboot` est version-agnostique) : un seul
     * dossier neutre, pas un sous-dossier par version.
     *
     * @throws WindowsIsoExtractionException Si la source est absente ou si une
     *                                       commande sudo échoue.
     */
    private function seedWinpeHelpers(string $base, int $timeout): void
    {
        $subdir = trim((string) config('ipxe.windows.assets_paths.wimboot_base', 'winpe'), '/');
        $source = rtrim(
            (string) config('ipxe.windows.assets_paths.winpe_source_path', resource_path('ipxe/winpe')),
            '/',
        );

        if ($subdir === '' || ! is_dir($source)) {
            throw new WindowsIsoExtractionException(
                "[winpe] helpers source introuvables : {$source} (wimboot_base='{$subdir}')",
            );
        }

        $dest = $base . '/' . $subdir;

        // mkdir + copie du CONTENU (`/.`) + réappropriation www-admin, en sudo
        // (le tree `/os` peut être root-owned d'un déploiement legacy).
        $this->runOrThrow(sprintf('sudo -n mkdir -p %s', escapeshellarg($dest)), $timeout, 'winpe-mkdir');
        $this->runOrThrow(
            sprintf('sudo -n cp -R %s %s', escapeshellarg($source . '/.'), escapeshellarg($dest . '/')),
            $timeout,
            'winpe-copy',
        );
        $this->runOrThrow(
            sprintf('sudo -n chown -R www-admin:www-admin %s', escapeshellarg($dest)),
            $timeout,
            'winpe-chown',
        );
    }

    /**
     * Exécute une commande et lève {@see WindowsIsoExtractionException} (avec
     * l'exit code + stderr) si elle échoue.
     */
    private function runOrThrow(string $command, int $timeout, string $stage): void
    {
        $result = Process::timeout($timeout)->run($command);

        if (! $result->successful()) {
            $err = trim($result->errorOutput() ?: $result->output());

            throw new WindowsIsoExtractionException(
                '[' . $stage . '] ' . ($err !== '' ? $err : 'commande en échec'),
                $result->exitCode() ?? -1,
            );
        }
    }
}
