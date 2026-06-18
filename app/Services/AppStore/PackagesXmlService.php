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

                // Story 27.6 (Bug B) — IMPORTER LES <package> INTERNES, jamais le wrapper.
                // Un recipe `$app->xml` est soit un document complet
                // `<packages><package/>…</packages>` (racine wrapper, cas courant des
                // dépôts SE4), soit un `<package/>` direct (recipes minimalistes).
                // Importer le wrapper `<packages>` produisait `<packages>` DANS
                // `<packages>` → 0 <package> enfant DIRECT de la racine → l'engine
                // `wpkg-se4.js` (`getPackages().selectNodes("package")`) voyait 0 package.
                // On collecte les <package> à plat, on les importe un par un sous $root.
                $srcRoot = $fragment->documentElement;
                if ($srcRoot->localName === 'package') {
                    // Cas (b) : recipe à racine <package> directe.
                    $packageNodes = [$srcRoot];
                } else {
                    // Cas (a) : racine wrapper (<packages> ou autre). On ne prend QUE les
                    // <package> enfants DIRECTS du wrapper (un recipe SE4 n'imbrique
                    // jamais un <package> dans un <package>). Matérialisé en tableau
                    // (la DOMNodeList enfants est live — on la fige avant d'importer).
                    $packageNodes = [];
                    foreach ($srcRoot->childNodes as $child) {
                        if ($child instanceof \DOMElement && $child->localName === 'package') {
                            $packageNodes[] = $child;
                        }
                    }
                }

                if ($packageNodes === []) {
                    Log::warning('[AppStore] Recipe sans <package>, skip', ['app_id' => $app->app_id]);
                    continue;
                }

                // Supprimer les noeuds SambaEdu non compris par le client WPKG Windows,
                // APPLIQUÉ PAR <package> importé (le strip opère sur le noeud importé,
                // pas sur le DOM source).
                $sambaEduNodes = ['download', 'delete', 'untar', 'unzip'];
                foreach ($packageNodes as $packageNode) {
                    $imported = $dom->importNode($packageNode, true);

                    foreach ($sambaEduNodes as $nodeName) {
                        $nodes = $imported->getElementsByTagName($nodeName);
                        while ($nodes->length > 0) {
                            $nodes->item(0)->parentNode->removeChild($nodes->item(0));
                        }
                    }

                    $root->appendChild($imported);
                }
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
