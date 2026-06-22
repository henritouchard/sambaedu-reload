<?php

declare(strict_types=1);

namespace App\Services\AppStore;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\FileManagerService;
use Illuminate\Support\Facades\Log;

/**
 * Importeur du catalogue WPKG legacy (SE4) → table `applications` (SE5).
 *
 * Étape « Importer les applications WPKG » de /admin/sync-from-ad, exécutée
 * AVANT l'import des profils applicatifs (qui en dépend pour peupler le pivot
 * `app_profile_application`).
 *
 * Source = le `packages.xml` consolidé du serveur legacy
 * (`config('sambaedu.wpkg.legacy_packages_xml_path')`). C'est la SEULE source
 * qui contient la recette XML d'installation — la table MySQL legacy
 * `applications` ne stocke que des métadonnées + un SHA, jamais le XML. Ce
 * fichier ne contient par construction que les applis actives (généré par le
 * legacy depuis `active_app=1`).
 *
 * Pour chaque `<package id="id_nom_app">` :
 *  - upsert d'une {@see Application} (clé `app_id` = `id_nom_app`, `status` =
 *    Installed pour que {@see PackagesXmlService::regenerate} la republie) ;
 *  - placement des binaires référencés par `<download saveto="…">` au chemin
 *    attendu par SE5 (`storage_path/saveto`, identique au layout legacy). En
 *    migration in-place (`legacy_install_root == storage_path`) le placement se
 *    réduit à une vérification de présence ; sinon copie depuis la racine SE4.
 *
 * Idempotent : un rejeu fait `updated`, jamais de doublon. La recette XML d'une
 * appli déjà importée n'est PAS réécrite (on préserve le `<package>` original
 * d'origine même si le `packages.xml` source a entre-temps été régénéré par SE5,
 * qui en retire les directives serveur `<download>`/`<delete>`/…).
 */
final class LegacyWpkgImporter
{
    private string $storagePath;

    private string $legacyPackagesXmlPath;

    private string $legacyInstallRoot;

    public function __construct(
        private readonly AppStoreService $appStoreService,
        private readonly FileManagerService $files,
    ) {
        $this->storagePath = rtrim((string) config('sambaedu.wpkg.storage_path', '/var/sambaedu/unattended/install'), '/');
        $this->legacyPackagesXmlPath = (string) config('sambaedu.wpkg.legacy_packages_xml_path', $this->storagePath . '/wpkg/packages.xml');
        $this->legacyInstallRoot = rtrim((string) config('sambaedu.wpkg.legacy_install_root', $this->storagePath), '/');
    }

    /**
     * Importe le catalogue WPKG legacy.
     *
     * @param  callable|null  $logCallback  fn(string $level, string $message): void
     * @return array{
     *   created:int, updated:int, files_present:int, files_copied:int,
     *   files_missing:int, catalog_regenerated:bool, errors:array<int,string>
     * }
     */
    public function importFromLegacy(?callable $logCallback = null): array
    {
        // Fallback sans UI : 'success' est un niveau d'affichage (callback
        // Livewire), pas un niveau PSR-3 → on le ramène à 'info' pour le logger.
        $log = $logCallback ?? function (string $level, string $message): void {
            Log::log($level === 'success' ? 'info' : $level, $message);
        };

        $stats = [
            'created' => 0,
            'updated' => 0,
            'files_present' => 0,
            'files_copied' => 0,
            'files_missing' => 0,
            'catalog_regenerated' => false,
            'errors' => [],
        ];

        if (! is_file($this->legacyPackagesXmlPath)) {
            $log('warning', "Fichier packages.xml legacy introuvable : {$this->legacyPackagesXmlPath} — aucune application importée.");

            return $stats;
        }

        $log('info', "Lecture du catalogue legacy : {$this->legacyPackagesXmlPath}");

        $content = @file_get_contents($this->legacyPackagesXmlPath);
        if ($content === false || trim($content) === '') {
            $stats['errors'][] = 'Lecture du packages.xml legacy impossible ou fichier vide.';
            $log('error', 'Lecture du packages.xml legacy impossible ou fichier vide.');

            return $stats;
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $parsed = $dom->loadXML($content);
        libxml_use_internal_errors($prev);

        if (! $parsed) {
            $stats['errors'][] = 'packages.xml legacy invalide (parsing XML échoué).';
            $log('error', 'packages.xml legacy invalide (parsing XML échoué).');

            return $stats;
        }

        $packages = $dom->getElementsByTagName('package');
        $log('info', $packages->length . ' application(s) trouvée(s) dans le catalogue legacy.');

        if ($this->legacyInstallRoot === $this->storagePath) {
            $log('info', 'Racine legacy identique au stockage SE5 — placement des fichiers en mode vérification.');
        }

        foreach (iterator_to_array($packages) as $package) {
            /** @var \DOMElement $package */
            try {
                $appId = trim($package->getAttribute('id'));
                if ($appId === '') {
                    continue;
                }

                $application = $this->upsertApplication($package, $appId, $stats, $log);
                // F1 : on place les fichiers depuis la recette PERSISTÉE (préservée
                // en DB), pas depuis le nœud DOM live. Au re-run, `packages.xml`
                // source peut avoir été régénéré par SE5 (directives `<download>`
                // strippées) ; lire la recette en base garde le placement opérant.
                $this->placeFiles($application, $stats, $log);
            } catch (\Throwable $e) {
                $stats['errors'][] = "Erreur pour {$package->getAttribute('id')} : " . $e->getMessage();
                $log('error', "Erreur pour {$package->getAttribute('id')} : " . $e->getMessage());
            }
        }

        // Régénère le catalogue module (packages.xml SE5) + le bundle WPKG depuis
        // les Application::installed() fraîchement importées. C'est ce qui rend le
        // catalogue migré effectivement servi au poste. Un échec ici (FS/bundle)
        // ne doit PAS annuler l'import en base : on log et on continue.
        try {
            $this->appStoreService->updateLocalPackagesXml();
            $stats['catalog_regenerated'] = true;
            $log('info', 'Catalogue SE5 (packages.xml + bundle WPKG) régénéré.');
        } catch (\Throwable $e) {
            $stats['errors'][] = 'Régénération du catalogue SE5 échouée : ' . $e->getMessage();
            $log('warning', 'Régénération du catalogue SE5 échouée (import conservé) : ' . $e->getMessage());
        }

        $log('success', sprintf(
            'Bilan applications : %d créée(s), %d mise(s) à jour — fichiers : %d présent(s), %d copié(s), %d manquant(s).',
            $stats['created'],
            $stats['updated'],
            $stats['files_present'],
            $stats['files_copied'],
            $stats['files_missing'],
        ));

        return $stats;
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    private function upsertApplication(\DOMElement $package, string $appId, array &$stats, callable $log): Application
    {
        $doc = $package->ownerDocument;
        $recipeXml = $doc !== null ? (string) $doc->saveXML($package) : '';

        $payload = [
            'name' => $this->attr($package, 'name') ?? $appId,
            'version' => $this->attr($package, 'revision'),
            'category' => $this->attr($package, 'category2') ?? $this->attr($package, 'category'),
            'compatibility' => $this->attr($package, 'compatibilite') ?? $this->attr($package, 'compatibility'),
            'status' => ApplicationStatus::Installed,
            'installed_version' => $this->attr($package, 'revision'),
            'installed_at' => now(),
        ];

        $existing = Application::where('app_id', $appId)->first();

        if ($existing !== null) {
            // Préserve la recette d'origine : ne (ré)écrit `xml` que si absente.
            if (empty($existing->xml) && $recipeXml !== '') {
                $payload['xml'] = $recipeXml;
            }
            $existing->update($payload);
            $stats['updated']++;
            $log('info', "Mise à jour : {$appId}");

            return $existing;
        }

        $application = Application::create(array_merge($payload, [
            'app_id' => $appId,
            'depot_id' => null,
            'xml' => $recipeXml,
        ]));
        $stats['created']++;
        $log('success', "Créée : {$appId}");

        return $application;
    }

    /**
     * Place (ou vérifie) les binaires référencés par les `<download saveto="…">`
     * de la recette PERSISTÉE de l'application, au chemin attendu par SE5
     * (`storage_path/saveto`, identique au layout legacy).
     *
     * F6 — ⚠ En mode hôte distinct (`legacy_install_root != storage_path`), la
     * copie de binaires potentiellement volumineux (MSI/EXE) est SYNCHRONE dans
     * le cycle Livewire `runStep` : risque de timeout PHP/proxy si le parc a de
     * gros installeurs. Le cas in-place par défaut ne fait que des `file_exists`
     * (rapide). À job-ifier si ce mode devient courant.
     *
     * @param  array<string,mixed>  $stats
     */
    private function placeFiles(Application $application, array &$stats, callable $log): void
    {
        $appId = $application->app_id;
        $recipe = (string) $application->xml;
        if ($recipe === '') {
            return;
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($recipe);
        libxml_use_internal_errors($prev);
        if (! $loaded) {
            return;
        }

        foreach (iterator_to_array($dom->getElementsByTagName('download')) as $download) {
            /** @var \DOMElement $download */
            $saveto = trim($download->getAttribute('saveto'));
            if ($saveto === '') {
                continue;
            }

            // Garde-fou path traversal (F5) : `saveto` provient d'un fichier de
            // confiance (legacy) mais on refuse tout chemin absolu / remontant /
            // séparateur Windows, et on revérifie le confinement canonique sous
            // storage_path (défense contre un symlink échappant).
            $relative = ltrim($saveto, '/');
            if ($relative === '' || str_contains($relative, '..') || str_contains($relative, '\\')) {
                $stats['errors'][] = "saveto suspect ignoré pour {$appId} : {$saveto}";
                $log('warning', "saveto suspect ignoré pour {$appId} : {$saveto}");
                continue;
            }

            $dest = $this->storagePath . '/' . $relative;

            if (! $this->isWithinStorage($dest)) {
                $stats['errors'][] = "saveto hors stockage ignoré pour {$appId} : {$saveto}";
                $log('warning', "saveto hors stockage ignoré pour {$appId} : {$saveto}");
                continue;
            }

            if ($this->files->exists($dest)) {
                $stats['files_present']++;
                continue;
            }

            $src = $this->legacyInstallRoot . '/' . $relative;
            if ($src !== $dest && $this->files->exists($src)) {
                if ($this->files->copy($src, $dest, createDir: true)) {
                    $stats['files_copied']++;
                } else {
                    $stats['files_missing']++;
                    $log('warning', "Copie échouée pour {$appId} : {$src} → {$dest}");
                }
                continue;
            }

            $stats['files_missing']++;
            $log('warning', "Binaire manquant pour {$appId} : {$dest}");
        }
    }

    private function attr(\DOMElement $el, string $name): ?string
    {
        $value = trim($el->getAttribute($name));

        return $value === '' ? null : $value;
    }

    /**
     * F5 — Confinement canonique : le chemin de destination doit rester sous
     * storage_path une fois les symlinks résolus. Si le dossier parent n'existe
     * pas encore (rien à résoudre), les rejets amont `..`/`\`/absolu suffisent.
     */
    private function isWithinStorage(string $path): bool
    {
        $realParent = realpath(dirname($path));
        if ($realParent === false) {
            return true;
        }

        $realStorage = rtrim((string) (realpath($this->storagePath) ?: $this->storagePath), '/');

        return $realParent === $realStorage || str_starts_with($realParent . '/', $realStorage . '/');
    }
}
