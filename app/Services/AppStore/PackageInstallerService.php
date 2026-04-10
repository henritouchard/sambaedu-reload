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
    private int $downloadTimeout;
    private int $syncTimeout;

    public function __construct(
        private FileManagerService $fileManagerService,
    ) {
        $this->storagePath = config('sambaedu.wpkg.storage_path', '/var/sambaedu/unattended/install');
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
}
