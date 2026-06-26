<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Services;

use App\Ipxe\Iso\Exceptions\WinpeDriverIngestionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Story 3.10 — Service d'ingestion PARTAGÉ des archives de pilotes WinPE (NIC)
 * vers le pack persistant `winpe_drivers_path/<famille>/`.
 *
 * **Point unique de logique (D3, tranché par Henri)** : la commande artisan
 * {@see \App\Console\Commands\IngestWinpeDriversCommand} ET le composant
 * Livewire `iso-windows` appellent ce service — ZÉRO duplication. Le composant
 * passe `getRealPath()` du fichier uploadé (jamais `move()` —
 * [[project_livewire_reserved_upload_method]]) + le nom client d'origine pour
 * que la détection d'extension reste correcte.
 *
 * Dispatch par extension (validé PoC) :
 *  - `.exe` (InnoSetup Lenovo) → `innoextract` (7z ne voit que les sections PE
 *    et rate les fichiers pilote).
 *  - `.zip` (Intel) → `unzip`.
 *
 * Robustesse : extraction dans un tmp jetable, localisation récursive des
 * `.inf`/`.sys`/`.cat`/`.dll`, copie À PLAT dans la famille (triplets côte à
 * côte, ce que `drvload` attend), chown www-admin best-effort. Aucun pack
 * partiel laissé : on n'écrit la famille QUE si au moins un `.inf` est trouvé,
 * et on purge le tmp en `finally`.
 */
final class WinpeDriverIngestor
{
    /** Extensions des fichiers pilote copiés à plat dans la famille. */
    private const DRIVER_EXTENSIONS = ['inf', 'sys', 'cat', 'dll', 'din'];

    /**
     * Ingest une archive de pilotes vers `winpe_drivers_path/<famille>/`.
     *
     * @param  string  $famille  Nom de famille (ex. `intel-i219`) — strictement
     *                          validé (anti path-traversal).
     * @param  string  $archivePath  Chemin disque de l'archive (peut être un
     *                               `getRealPath()` Livewire sans extension).
     * @param  string|null  $originalFilename  Nom d'origine (ex. `u1etn.exe`)
     *                                        utilisé pour détecter le type quand
     *                                        `$archivePath` n'a pas d'extension
     *                                        (cas upload Livewire).
     *
     * @return list<string> Noms de fichiers `.inf` ingérés (récap CLI + toast).
     *
     * @throws WinpeDriverIngestionException
     */
    public function ingest(string $famille, string $archivePath, ?string $originalFilename = null): array
    {
        $channel = (string) config('ipxe.log.channel', 'ipxe');

        $famille = $this->validateFamily($famille);

        if (! is_file($archivePath)) {
            throw new WinpeDriverIngestionException("Archive introuvable : {$archivePath}");
        }

        $extension = strtolower(pathinfo($originalFilename ?? $archivePath, PATHINFO_EXTENSION));

        $tmpDir = rtrim(sys_get_temp_dir(), '/') . '/se5-winpe-ingest-' . getmypid() . '-' . uniqid();
        if (! @mkdir($tmpDir, 0700, true) && ! is_dir($tmpDir)) {
            throw new WinpeDriverIngestionException("Dossier temporaire non créable : {$tmpDir}");
        }

        try {
            $this->extractArchive($extension, $archivePath, $tmpDir);

            $infFiles = $this->findFilesByExtension($tmpDir, ['inf']);
            if ($infFiles === []) {
                throw new WinpeDriverIngestionException(
                    "Aucun fichier .inf trouvé dans l'archive — pack non ingéré (vérifiez qu'il s'agit bien d'un pack de pilotes Windows).",
                );
            }

            $packPath = rtrim(
                (string) config('ipxe.iso_management.winpe_drivers_path', storage_path('install/winpe-drivers')),
                '/',
            );
            $familyDir = $packPath . '/' . $famille;

            // Remplacement complet de la famille (idempotent : ré-ingérer
            // écrase proprement). `removeDirectory()` est best-effort silencieuse :
            // si d'anciens fichiers sont root-owned (ex. un précédent
            // `sudo artisan ingest`), la purge échoue sans bruit et d'anciens
            // pilotes coexisteraient avec les nouveaux → mélange injecté. On
            // VÉRIFIE donc que la famille est bien partie avant de la recréer.
            $this->removeDirectory($familyDir);
            if (is_dir($familyDir) && $this->directoryHasFiles($familyDir)) {
                throw new WinpeDriverIngestionException(
                    "La famille « {$famille} » n'a pas pu être purgée avant ré-ingestion "
                    . "(fichiers résiduels dans {$familyDir} — vérifiez les droits/propriétaire).",
                );
            }
            if (! @mkdir($familyDir, 0755, true) && ! is_dir($familyDir)) {
                throw new WinpeDriverIngestionException("Dossier de famille non créable : {$familyDir}");
            }

            // Copie À PLAT des fichiers pilote (triplets .inf/.sys/.cat + .dll).
            $copied = $this->findFilesByExtension($tmpDir, self::DRIVER_EXTENSIONS);
            $ingestedInf = [];
            foreach ($copied as $src) {
                $dest = $familyDir . '/' . basename($src);
                if (! @copy($src, $dest)) {
                    throw new WinpeDriverIngestionException(
                        'Échec de copie du fichier pilote : ' . basename($src),
                    );
                }
                @chmod($dest, 0644);
                if (strtolower(pathinfo($src, PATHINFO_EXTENSION)) === 'inf') {
                    $ingestedInf[] = basename($src);
                }
            }

            // www-admin owner (best-effort : sans effet/échec silencieux si le
            // process ne tourne pas en root et n'est pas déjà www-admin).
            $this->chownWwwAdmin($familyDir);

            sort($ingestedInf);

            Log::channel($channel)->info('ipxe.winpe.drivers.ingested', [
                'family'      => $famille,
                'archive'     => $originalFilename ?? basename($archivePath),
                'extension'   => $extension,
                'inf_count'   => count($ingestedInf),
                'inf_files'   => $ingestedInf,
                'family_dir'  => $familyDir,
            ]);

            return $ingestedInf;
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    /**
     * Valide/normalise un nom de famille (anti path-traversal).
     */
    private function validateFamily(string $famille): string
    {
        $famille = trim($famille);

        // Anti path-traversal : la regex `[A-Za-z0-9._-]+` bloque le `/` mais
        // accepte `.` et `..` — or `<pack>/..` = le PARENT du pack
        // (`storage/install`), et `removeDirectory()` l'effacerait RÉCURSIVEMENT
        // (perte des ISO sources). On exige donc AU MOINS un caractère
        // alphanumérique (rejette `.`, `..`, `...`, `--`, `__`, …) en plus de la
        // liste blanche de caractères.
        if (
            $famille === ''
            || preg_match('/^[A-Za-z0-9._-]+$/', $famille) !== 1
            || preg_match('/[A-Za-z0-9]/', $famille) !== 1
        ) {
            throw new WinpeDriverIngestionException(
                "Nom de famille invalide : « {$famille} » (autorisé : lettres, chiffres, point, tiret, underscore ; "
                . 'au moins un caractère alphanumérique requis).',
            );
        }

        return $famille;
    }

    /**
     * Dispatch d'extraction par extension. Vérifie la présence du binaire
     * AVANT extraction pour un message clair (AC4.2).
     */
    private function extractArchive(string $extension, string $archivePath, string $tmpDir): void
    {
        $timeout = (int) config('ipxe.iso_management.extract_timeout_seconds', 1800);

        switch ($extension) {
            case 'exe':
                $this->assertBinaryAvailable('innoextract', 'innoextract');
                // innoextract : l'extraction est le comportement par défaut.
                // `-s`/`--silent` et `-q`/`--quiet` réduisent la sortie ;
                // `-d` = répertoire de destination. (Combinaison validée au PoC —
                // à reconfirmer au smoke e2e sur VM provisionnée, cf. runbook QA.)
                $command = sprintf(
                    'innoextract -s -q -d %s %s',
                    escapeshellarg($tmpDir),
                    escapeshellarg($archivePath),
                );
                $stage = 'innoextract';
                break;

            case 'zip':
                $this->assertBinaryAvailable('unzip', 'unzip');
                $command = sprintf(
                    'unzip -o -q %s -d %s',
                    escapeshellarg($archivePath),
                    escapeshellarg($tmpDir),
                );
                $stage = 'unzip';
                break;

            default:
                throw new WinpeDriverIngestionException(
                    "Archive non reconnue (extension « {$extension} ») — seuls les `.exe` (InnoSetup Lenovo) "
                    . 'et `.zip` (Intel) sont supportés.',
                );
        }

        $result = Process::timeout($timeout)->run($command);

        if (! $result->successful()) {
            $err = trim($result->errorOutput() ?: $result->output());

            throw new WinpeDriverIngestionException(
                '[' . $stage . '] extraction en échec : ' . ($err !== '' ? $err : 'commande non-zéro'),
            );
        }
    }

    /**
     * Vérifie qu'un binaire externe est disponible (`command -v`). Message
     * clair indiquant le paquet à installer si absent (AC4.2 / AC5.1).
     */
    private function assertBinaryAvailable(string $binary, string $package): void
    {
        $result = Process::run(sprintf('command -v %s', escapeshellarg($binary)));

        if (! $result->successful()) {
            throw new WinpeDriverIngestionException(
                "Le binaire « {$binary} » est introuvable sur le serveur. Installez le paquet `{$package}` "
                . '(prérequis de provisioning SE5).',
            );
        }
    }

    /**
     * Localise récursivement les fichiers d'une ou plusieurs extensions.
     *
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function findFilesByExtension(string $dir, array $extensions): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $wanted = array_map('strtolower', $extensions);
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $wanted, true)) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Indique si un dossier contient encore au moins un fichier (récursif).
     * Sert à détecter un résidu non purgé avant ré-ingestion (#5).
     */
    private function directoryHasFiles(string $dir): bool
    {
        if (! is_dir($dir)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Suppression récursive best-effort d'un dossier (purge tmp / remplacement
     * famille). N'utilise PAS `rm -rf` shell (reste en PHP natif, www-admin).
     */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    /**
     * chown www-admin:www-admin récursif best-effort (sans sudo). Échec/no-op
     * silencieux si le process n'est pas privilégié (CLI root → applique ;
     * www-admin → déjà correct ; test hôte sans user www-admin → ignoré).
     */
    private function chownWwwAdmin(string $dir): void
    {
        Process::run(sprintf('chown -R www-admin:www-admin %s', escapeshellarg($dir)));
    }
}
