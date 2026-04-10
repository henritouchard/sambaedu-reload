<?php

declare(strict_types=1);

namespace App\Services\AppStore;

use App\Models\Application;
use Illuminate\Support\Facades\Log;

/**
 * Service de generation/maintenance du packages.xml local
 */
class PackagesXmlService
{
    private string $storagePath;
    private string $packagesXmlPath;

    public function __construct()
    {
        $this->storagePath = config('sambaedu.wpkg.storage_path', '/var/se4fs/wpkg');
        $this->packagesXmlPath = config('sambaedu.wpkg.packages_xml_path', $this->storagePath . '/packages.xml');
    }

    /**
     * Regenere le fichier packages.xml local a partir des applications installees
     */
    public function regenerate(): void
    {
        $installedApps = Application::installed()->orderBy('app_id')->get();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('packages');
        $dom->appendChild($root);

        foreach ($installedApps as $app) {
            if (!empty($app->xml)) {
                $fragment = new \DOMDocument();
                $prev = libxml_use_internal_errors(true);
                $parsed = $fragment->loadXML($app->xml);
                libxml_use_internal_errors($prev);

                if (!$parsed || !$fragment->documentElement) {
                    Log::warning('[AppStore] XML invalide pour l\'application, skip', ['app_id' => $app->app_id]);
                    continue;
                }

                $imported = $dom->importNode($fragment->documentElement, true);

                // Supprimer les noeuds SambaEdu non compris par le client WPKG Windows
                $sambaEduNodes = ['download', 'delete', 'untar', 'unzip'];
                foreach ($sambaEduNodes as $nodeName) {
                    $nodes = $imported->getElementsByTagName($nodeName);
                    while ($nodes->length > 0) {
                        $nodes->item(0)->parentNode->removeChild($nodes->item(0));
                    }
                }

                $root->appendChild($imported);
            }
        }

        $packagesXmlPath = $this->packagesXmlPath;
        $dir = dirname($packagesXmlPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmpPath = $packagesXmlPath . '.tmp';
        if ($dom->save($tmpPath) === false) {
            throw new \RuntimeException("Impossible d'écrire packages.xml : {$packagesXmlPath}");
        }
        rename($tmpPath, $packagesXmlPath);

        Log::info('[AppStore] packages.xml local mis a jour', [
            'path' => $packagesXmlPath,
            'applications_count' => $installedApps->count(),
        ]);
    }
}
