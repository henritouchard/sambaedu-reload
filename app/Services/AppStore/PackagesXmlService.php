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
     * Story 27.19 — Nom DNS/NetBIOS du serveur SE5, utilisé pour construire les
     * URLs HTTP de livraison des payloads (`http://<se4fs>/wpkg/files/...`). Source
     * = conf serveur (`sambaedu.se4fs_name`, iso `packages_xml_out.php` / cf.
     * {@see \App\Wpkg\Deployment\Services\WpkgBundleGenerator} qui substitue la même
     * clé), JAMAIS l'AD. Fallback `se4fs` si la clé est vide (parité avec
     * `SambaEduConfig::se4fsName` et `config('app-customizations.se4fs_name')`).
     */
    private function se4fsName(): string
    {
        $name = trim((string) config('sambaedu.se4fs_name', ''));

        return $name !== '' ? $name : 'se4fs';
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

                // Story 27.19 — LIVRAISON FULL HTTP des payloads. Auparavant on
                // strippait TOUS les <download> : le poste devait recopier le binaire
                // depuis le partage SMB %SOFTWARE% (débranché en SE5) → install en
                // échec silencieux. Désormais on RÉÉCRIT chirurgicalement les recettes
                // qui dépendent de %SOFTWARE% pour que le moteur télécharge le payload
                // en HTTP (download natif, target=%TEMP%) puis copie depuis %TEMP%.
                //
                // <delete>/<untar>/<unzip> restent strippés : ce sont des directives
                // de POST-TRAITEMENT SERVEUR (extraction d'archive côté SE5), jamais
                // exécutées sur le poste. <download> n'est plus strippé d'office mais
                // traité au cas par cas par transformPackageForHttpDelivery().
                $serverOnlyNodes = ['delete', 'untar', 'unzip'];
                foreach ($packageNodes as $packageNode) {
                    $imported = $dom->importNode($packageNode, true);

                    $this->transformPackageForHttpDelivery($imported);

                    foreach ($serverOnlyNodes as $nodeName) {
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

    /**
     * Story 27.19 — Réécrit CHIRURGICALEMENT un <package> importé pour la livraison
     * FULL HTTP des payloads, en place dans le DOM cible.
     *
     * Invariant central (AC6) : on ne transforme QUE les recettes qui dépendent du
     * partage SMB legacy %SOFTWARE% (marqueur = un <install cmd> référençant
     * %SOFTWARE%). Les recettes sans %SOFTWARE% ne sont pas touchées (leur
     * <download> est strippé comme avant — inerte sans config.xml côté poste).
     *
     * EXCLUSION DURE des archives extraites SERVEUR : un <download> dont le `saveto`
     * est la source d'un <untar>/<unzip> (tarfile/zipfile) ne doit JAMAIS être
     * réactivé — son contenu n'existe sur le poste qu'APRÈS extraction, qui ne se
     * fait que côté SE5. On le strippe.
     *
     * Pour les <download> conservés (payloads directs d'une recette %SOFTWARE%) :
     *  - `url` ← URL HTTP SE5 dérivée du `saveto` (`http://<se4fs>/wpkg/files/<rel>`,
     *    `rel` = saveto sans le préfixe `packages/`) ;
     *  - `target` ← chemin relatif Windows (le moteur télécharge dans %TEMP%\<target>) ;
     *  - `saveto`/`sha256sum`/`md5sum` retirés (non lus par le moteur — cf. Dev Notes).
     * Et chaque <install cmd> voit `%SOFTWARE%` réécrit en `%TEMP%` (même sous-chemin).
     *
     * Le <check> n'est JAMAIS touché (idempotence — AC8).
     */
    private function transformPackageForHttpDelivery(\DOMElement $package): void
    {
        $doc = $package->ownerDocument;
        if ($doc === null) {
            return;
        }

        // 1. Archives extraites serveur : recenser les `saveto` consommés par un
        //    <untar tarfile>/<unzip zipfile> de CE package (normalisés). Ces
        //    payloads ne doivent jamais voir leur <download> réactivé.
        $serverExtractedSources = [];
        foreach (['untar' => 'tarfile', 'unzip' => 'zipfile'] as $tag => $attr) {
            foreach ($this->directChildArchiveNodes($package, $tag) as $node) {
                $src = $this->normalizeSaveto($node->getAttribute($attr));
                if ($src !== '') {
                    $serverExtractedSources[$src] = true;
                }
            }
        }

        // 2. La recette dépend-elle de %SOFTWARE% ? Marqueur = un <install cmd>
        //    référençant %SOFTWARE% (insensible à la casse, comme une var d'env
        //    Windows). Si non, on ne réécrit RIEN (on strippe les <download> pour
        //    rester iso-comportement legacy : inertes sans config.xml).
        $installNodes = $this->collectByTag($package, 'install');
        $dependsOnSoftware = false;
        foreach ($installNodes as $install) {
            if ($this->referencesSoftwareVar($install->getAttribute('cmd'))) {
                $dependsOnSoftware = true;
                break;
            }
        }

        // 3. Traiter chaque <download>. On compte les payloads effectivement
        //    réécrits en HTTP : une recette %SOFTWARE% sans aucun payload livrable
        //    est un trou silencieux (l'install %TEMP% n'aura aucune source) → warn.
        $httpDownloads = 0;
        $rewrittenTargets = [];
        foreach ($this->collectByTag($package, 'download') as $download) {
            $saveto = $this->normalizeSaveto($download->getAttribute('saveto'));

            // Archive extraite serveur → strip inconditionnel (jamais sur le poste).
            if ($saveto !== '' && isset($serverExtractedSources[$saveto])) {
                $download->parentNode?->removeChild($download);
                continue;
            }

            // Recette sans dépendance %SOFTWARE%, ou <download> sans saveto → strip
            // (comportement legacy : pas de réécriture, payload non livrable en HTTP).
            if (! $dependsOnSoftware || $saveto === '') {
                $download->parentNode?->removeChild($download);
                continue;
            }

            // Story 27.19 (review #3) — l'alias Apache /wpkg/files ne sert QUE
            // l'arbre `.../install/packages`. Un `saveto` hors `packages/`
            // (ex. `softwares/...`, `wpkg/packages/...`, déposés verbatim par
            // PackageInstallerService/LegacyWpkgImporter) n'est PAS atteignable par
            // l'alias → l'URL réécrite renverrait un 404 silencieux (exactement la
            // classe de bug que cette story corrige). On strippe + on logue, pour
            // échouer de façon DIAGNOSTICABLE plutôt que de produire une URL morte.
            if (! str_starts_with($saveto, 'packages/')) {
                Log::warning('[AppStore] payload %SOFTWARE% hors arbre /wpkg/files (non livrable HTTP), <download> retiré', [
                    'package' => $package->getAttribute('id'),
                    'saveto' => $saveto,
                ]);
                $download->parentNode?->removeChild($download);
                continue;
            }

            $rewrittenTargets[] = $this->rewriteDownloadToHttp($download, $saveto);
            $httpDownloads++;
        }

        // 4. Réécrire %SOFTWARE% → %TEMP% dans les <install cmd> (uniquement si la
        //    recette dépend de %SOFTWARE% — sinon il n'y a rien à réécrire).
        if ($dependsOnSoftware) {
            // Garde-fou cohérence (review #1/#3) : si la recette dépend de %SOFTWARE%
            // mais qu'aucun payload n'a été réécrit en HTTP (tous strippés : sans
            // saveto, hors `packages/`, ou archive extraite serveur), l'install
            // réécrit en %TEMP% n'aura aucune source → échec silencieux sur le poste.
            if ($httpDownloads === 0) {
                Log::warning('[AppStore] recette %SOFTWARE% sans payload HTTP livrable — l\'install échouera sur le poste', [
                    'package' => $package->getAttribute('id'),
                ]);
            }

            foreach ($installNodes as $install) {
                $cmd = $install->getAttribute('cmd');
                if ($cmd !== '') {
                    $install->setAttribute('cmd', $this->rewriteSoftwareToTemp($cmd));
                }
            }
        }

        // 5. Purge du payload dans %TEMP% APRÈS install réussie (review #M2). Une
        //    <install> de suppression APPENDUE en fin de package : le moteur exécute
        //    les <install> dans l'ordre du document et AVORTE le package dès qu'une
        //    commande renvoie un code ≠ 0 (wpkg-se4.js:5886). La purge ne s'exécute
        //    donc QUE si tous les installs réels ont réussi (= « si succès, on
        //    supprime »). Si l'install échoue, le payload reste pour diagnostic.
        //    `cmd /c … & exit /b 0` : `del` est un builtin (exec = CreateProcess, pas
        //    de shell → cmd.exe obligatoire ; cmd.exe expanse %TEMP% lui-même) et le
        //    `exit /b 0` final garantit que la purge ne fait JAMAIS échouer le package
        //    (un del en erreur — fichier déjà absent — ne doit pas marquer l'install KO).
        foreach ($rewrittenTargets as $target) {
            $purge = $doc->createElement('install');
            $purge->setAttribute('cmd', sprintf('cmd /c del /F /Q "%%TEMP%%\\%s" 2>nul & exit /b 0', $target));
            $package->appendChild($purge);
        }
    }

    /**
     * Réécrit un <download> conservé vers la livraison HTTP SE5.
     *
     * @param  string  $saveto  saveto normalisé (séparateurs `/`, sans `./`)
     * @return string le `target` relatif Windows posé (pour la purge %TEMP% post-install)
     */
    private function rewriteDownloadToHttp(\DOMElement $download, string $saveto): string
    {
        // L'alias Apache /wpkg/files mappe `.../install/packages` sur sa racine ;
        // le saveto est `packages/<rel>` → l'URL publique est /wpkg/files/<rel>.
        $relative = $this->stripPackagesPrefix($saveto);
        $url = sprintf('http://%s/wpkg/files/%s', $this->se4fsName(), $relative);

        // `target` est relatif à %TEMP% (downloadDir du moteur). On garde la même
        // arborescence relative que `rel` mais en séparateurs Windows : le payload
        // arrive dans %TEMP%\<rel windows>, là où l'<install> réécrit ira le relire.
        $target = str_replace('/', '\\', $relative);

        $download->setAttribute('url', $url);
        $download->setAttribute('target', $target);

        // Attributs serveur non lus par le moteur (et qui désormais induiraient en
        // erreur — le hash n'est plus vérifié sur le poste en v1, cf. T4 différée).
        foreach (['saveto', 'sha256sum', 'md5sum', 'sha1sum', 'md5'] as $attr) {
            if ($download->hasAttribute($attr)) {
                $download->removeAttribute($attr);
            }
        }

        return $target;
    }

    /**
     * Réécrit toutes les occurrences de `%SOFTWARE%` en `%TEMP%` dans une commande
     * d'install (insensible à la casse, comme l'expansion des vars Windows). Le
     * sous-chemin suivant la variable est conservé tel quel → le payload téléchargé
     * dans %TEMP%\<même sous-chemin> est relu au bon endroit.
     */
    private function rewriteSoftwareToTemp(string $cmd): string
    {
        return (string) preg_replace('/%SOFTWARE%/i', '%TEMP%', $cmd);
    }

    /**
     * Un attribut contient-il une référence à la variable WPKG `%SOFTWARE%` ?
     */
    private function referencesSoftwareVar(string $value): bool
    {
        return stripos($value, '%SOFTWARE%') !== false;
    }

    /**
     * Retire le préfixe `packages/` d'un saveto normalisé (l'alias Apache mappe ce
     * dossier sur sa racine). Les appelants GARANTISSENT que le saveto commence par
     * `packages/` (un saveto hors de cet arbre n'est pas servable par l'alias et est
     * écarté en amont, cf. {@see transformPackageForHttpDelivery} review #3) ; le
     * fallback `return $saveto` n'est donc qu'une sécurité défensive.
     */
    private function stripPackagesPrefix(string $saveto): string
    {
        if (str_starts_with($saveto, 'packages/')) {
            return substr($saveto, strlen('packages/'));
        }

        return $saveto;
    }

    /**
     * Normalise un chemin saveto/tarfile/zipfile : séparateurs `\`→`/`, retrait des
     * `./` de tête et des `/` de tête, pour une comparaison fiable (la même archive
     * peut être référencée `packages\x.tgz` dans un <download saveto> et
     * `packages/x.tgz` dans un <untar tarfile>).
     */
    private function normalizeSaveto(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#^(\./)+#', '', $path) ?? $path;

        return ltrim($path, '/');
    }

    /**
     * Collecte les descendants directs ou imbriqués portant un tag donné, sous forme
     * de tableau matérialisé (la DOMNodeList est live ; on la fige avant mutation).
     *
     * @return list<\DOMElement>
     */
    private function collectByTag(\DOMElement $package, string $tag): array
    {
        $out = [];
        foreach ($package->getElementsByTagName($tag) as $node) {
            if ($node instanceof \DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * Alias sémantique de {@see collectByTag} pour les nœuds d'archive (untar/unzip),
     * lus AVANT leur strip pour identifier les payloads extraits serveur.
     *
     * @return list<\DOMElement>
     */
    private function directChildArchiveNodes(\DOMElement $package, string $tag): array
    {
        return $this->collectByTag($package, $tag);
    }
}
