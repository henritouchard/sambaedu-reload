<?php

declare(strict_types=1);

namespace App\Services\AppStore;

use App\Models\Application;
use App\Models\DepotApplication;
use App\Models\InstallationLog;
use App\Services\FileManagerService;
use Illuminate\Support\Facades\Log;

/**
 * Service d'installation des packages
 *
 * Gere le telechargement du XML recipe, la verification d'integrite,
 * et le parsing des directives d'installation.
 */
class PackageInstallerService
{
    private string $storagePath;
    private string $tmpPath;
    private int $downloadTimeout;
    private int $syncTimeout;

    public function __construct(
        private FileManagerService $fileManagerService,
    ) {
        $this->storagePath = config('sambaedu.wpkg.storage_path', '/var/sambaedu/unattended/install');
        $this->tmpPath = $this->storagePath . '/wpkg/tmp2';
        $this->downloadTimeout = (int) config('sambaedu.wpkg.download_timeout', 300);
        $this->syncTimeout = (int) config('sambaedu.wpkg.sync_timeout', 30);
    }

    /**
     * Installe une application (placeholder — sera implemente en 8.2.6)
     */
    public function install(Application $application, InstallationLog $log): void
    {
        // Placeholder — le flow d'installation reste dans AppStoreService pour l'instant
    }

    /**
     * Telecharge tous les fichiers d'un package avec verification de hash et skip intelligent
     *
     * Pour chaque fichier : verifie si le fichier final existe deja avec le bon hash (skip),
     * sinon telecharge dans tmp2/ puis deplace vers le chemin final.
     * En cas d'echec (hash mismatch, erreur HTTP), l'exception remonte — les fichiers
     * deja dans tmp2/ restent (nettoyage en 8.2.6).
     *
     * @param array $downloads Tableau de directives download (url, saveto, sha256sum, md5sum)
     * @param InstallationLog $log Log d'installation pour la progression
     * @throws \RuntimeException Si un telechargement ou un deplacement echoue
     */
    public function downloadFiles(array $downloads, InstallationLog $log): void
    {
        $total = count($downloads);
        if ($total === 0) {
            return;
        }

        $cumulativeBytes = 0;

        foreach ($downloads as $i => $download) {
            $finalPath = $this->storagePath . '/' . $download['saveto'];
            $filename = basename($download['saveto']);

            // Determiner l'algo et le hash attendu (SHA-256 prioritaire, fallback MD5)
            $expectedHash = !empty($download['sha256sum']) ? $download['sha256sum'] : null;
            $algo = 'sha256';
            if ($expectedHash === null) {
                $expectedHash = !empty($download['md5sum']) ? $download['md5sum'] : null;
                $algo = 'md5';
            }

            // Skip intelligent : fichier existant avec le bon hash
            if (file_exists($finalPath) && $expectedHash !== null) {
                $actualHash = $this->fileManagerService->hashFile($finalPath, $algo);
                if (strtolower($actualHash) === strtolower($expectedHash)) {
                    Log::info('[AppStore] Skip fichier existant avec hash correct', ['path' => $finalPath]);
                    $cumulativeBytes += filesize($finalPath);
                    $log->update([
                        'progress' => 20 + (int) round(50 * ($i + 1) / $total),
                        'downloaded_bytes' => $cumulativeBytes,
                        'message' => "Telechargement " . ($i + 1) . "/{$total} : {$filename} (skip)",
                    ]);
                    continue;
                }
            }

            // Telecharger vers tmp2/ (prefixe unique pour eviter les collisions de basename)
            $tmpTarget = $this->tmpPath . '/' . str_replace('/', '_', $download['saveto']);
            $this->fileManagerService->downloadWithHash(
                url: $download['url'],
                targetPath: $tmpTarget,
                sha256: $expectedHash !== null && $algo === 'sha256' ? $expectedHash : null,
                md5: $expectedHash !== null && $algo === 'md5' ? $expectedHash : null,
                timeout: $this->downloadTimeout,
            );

            // Deplacer vers le chemin final
            if (!$this->fileManagerService->move($tmpTarget, $finalPath)) {
                throw new \RuntimeException("Echec deplacement de {$tmpTarget} vers {$finalPath}");
            }

            $cumulativeBytes += filesize($finalPath);
            $log->update([
                'progress' => 20 + (int) round(50 * ($i + 1) / $total),
                'downloaded_bytes' => $cumulativeBytes,
                'message' => "Telechargement " . ($i + 1) . "/{$total} : {$filename}...",
            ]);
        }
    }

    /**
     * Telecharge le XML recipe d'un package dans un fichier temporaire
     *
     * @return string Chemin du fichier XML telecharge
     * @throws \RuntimeException Si l'URL est absente ou le telechargement echoue
     */
    public function downloadXmlRecipe(DepotApplication $depotApp): string
    {
        if (empty($depotApp->xml_url)) {
            throw new \RuntimeException('URL du XML non definie pour ' . $depotApp->app_id);
        }

        $safeAppId = basename($depotApp->app_id);
        $targetPath = $this->storagePath . '/wpkg/tmp2/' . $safeAppId . '_' . date('Ymd_His') . '_' . uniqid() . '.xml';

        $this->fileManagerService->downloadWithHash(
            url: $depotApp->xml_url,
            targetPath: $targetPath,
            timeout: $this->syncTimeout,
        );

        return $targetPath;
    }

    /**
     * Verifie le hash SHA-512 d'un fichier XML recipe
     *
     * @throws \RuntimeException Si le hash ne correspond pas
     */
    public function verifyXmlHash(string $xmlPath, ?string $expectedHash): void
    {
        if (empty($expectedHash)) {
            Log::warning('[AppStore] Hash XML absent, verification sautee');
            return;
        }

        $computedHash = hash_file('sha512', $xmlPath);

        if ($computedHash === false) {
            throw new \RuntimeException("Impossible de calculer le hash SHA-512 pour: {$xmlPath}");
        }

        if (strtolower($computedHash) !== strtolower($expectedHash)) {
            @unlink($xmlPath);
            throw new \RuntimeException(
                "Hash SHA-512 XML invalide. Attendu: {$expectedHash}, Calcule: {$computedHash}"
            );
        }
    }

    /**
     * Parse les directives d'installation depuis un XML recipe
     *
     * @return array{packages: array, downloads: array, deletes: array, untars: array, unzips: array}
     * @throws \RuntimeException Si le XML est invalide
     */
    public function parseDirectives(string $xmlPath): array
    {
        $content = file_get_contents($xmlPath);
        if ($content === false) {
            throw new \RuntimeException("Impossible de lire le fichier XML: {$xmlPath}");
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $parsed = $dom->loadXML($content);
        libxml_use_internal_errors($prev);

        if (!$parsed) {
            throw new \RuntimeException("XML recipe invalide: parsing echoue pour {$xmlPath}");
        }

        $result = [
            'packages' => [],
            'downloads' => [],
            'deletes' => [],
            'untars' => [],
            'unzips' => [],
        ];

        $packages = $dom->getElementsByTagName('package');
        foreach ($packages as $package) {
            $result['packages'][] = [
                'id' => $package->getAttribute('id'),
                'name' => $package->getAttribute('name'),
                'revision' => $package->getAttribute('revision'),
                'compatibilite' => $package->getAttribute('compatibilite'),
                'category2' => $package->getAttribute('category2'),
                'priority' => $package->getAttribute('priority'),
                'reboot' => $package->getAttribute('reboot'),
            ];

            $downloads = $package->getElementsByTagName('download');
            foreach ($downloads as $download) {
                $result['downloads'][] = [
                    'url' => $download->getAttribute('url'),
                    'saveto' => $download->getAttribute('saveto'),
                    'md5sum' => $download->getAttribute('md5sum') ?: null,
                    'sha256sum' => $download->getAttribute('sha256sum') ?: null,
                ];
            }

            $deletes = $package->getElementsByTagName('delete');
            foreach ($deletes as $delete) {
                $result['deletes'][] = [
                    'file' => $delete->getAttribute('file'),
                ];
            }

            $untars = $package->getElementsByTagName('untar');
            foreach ($untars as $untar) {
                $result['untars'][] = [
                    'tarfile' => $untar->getAttribute('tarfile'),
                    'target' => $untar->getAttribute('target'),
                ];
            }

            $unzips = $package->getElementsByTagName('unzip');
            foreach ($unzips as $unzip) {
                $result['unzips'][] = [
                    'zipfile' => $unzip->getAttribute('zipfile'),
                    'target' => $unzip->getAttribute('target'),
                ];
            }
        }

        return $result;
    }

    public function processDeletes(array $deletes): void
    {
        foreach ($deletes as $delete) {
            $filePath = $this->resolveAndValidatePath($delete['file']);
            if (!$this->fileManagerService->exists($filePath)) {
                Log::debug('[AppStore] Fichier deja absent, skip', ['path' => $filePath]);
                continue;
            }
            if (!$this->fileManagerService->delete($filePath)) {
                Log::warning('[AppStore] Echec suppression fichier', ['path' => $filePath]);
            } else {
                Log::debug('[AppStore] Fichier supprime', ['path' => $filePath]);
            }
        }
    }

    public function processUntars(array $untars): void
    {
        foreach ($untars as $untar) {
            $archivePath = $this->resolveAndValidatePath($untar['tarfile']);
            $targetPath = $this->resolveAndValidatePath($untar['target']);
            $this->fileManagerService->extractTarGz($archivePath, $targetPath);
            Log::debug('[AppStore] Archive tar.gz extraite', [
                'archive' => $untar['tarfile'],
                'target' => $untar['target'],
            ]);
        }
    }

    public function processUnzips(array $unzips): void
    {
        foreach ($unzips as $unzip) {
            $archivePath = $this->resolveAndValidatePath($unzip['zipfile']);
            $targetPath = $this->resolveAndValidatePath($unzip['target']);
            $this->fileManagerService->extractZip($archivePath, $targetPath);
            Log::debug('[AppStore] Archive zip extraite', [
                'archive' => $unzip['zipfile'],
                'target' => $unzip['target'],
            ]);
        }
    }

    /**
     * Resout un chemin relatif et verifie qu'il reste sous storagePath
     *
     * @throws \RuntimeException Si le chemin resolu sort du storagePath (path traversal)
     */
    private function resolveAndValidatePath(string $relativePath): string
    {
        $fullPath = $this->storagePath . '/' . $relativePath;
        $resolved = realpath(dirname($fullPath));

        if ($resolved === false) {
            // Le repertoire parent n'existe pas encore — valider le chemin canonique
            $normalized = str_replace(['/../', '/./'], '/', $fullPath);
            if (str_contains($normalized, '/../') || str_contains($normalized, '/..')) {
                throw new \RuntimeException("Path traversal detecte: {$relativePath}");
            }
            return $fullPath;
        }

        $resolvedFull = $resolved . '/' . basename($fullPath);
        $storageReal = realpath($this->storagePath) ?: $this->storagePath;

        if (!str_starts_with($resolvedFull, $storageReal)) {
            throw new \RuntimeException("Path traversal detecte: {$relativePath}");
        }

        return $resolvedFull;
    }
}
