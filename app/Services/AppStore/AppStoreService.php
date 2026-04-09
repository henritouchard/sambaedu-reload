<?php

declare(strict_types=1);

namespace App\Services\AppStore;

use App\Enums\ApplicationStatus;
use App\Enums\InstallationStatus;
use App\Models\Application;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Models\InstallationLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Service principal du magasin d'applications
 * 
 * Gère le cycle de vie complet :
 * 1. Synchronisation du catalogue depuis les dépôts distants
 * 2. Téléchargement des packages (XML + installeur)
 * 3. Vérification d'intégrité SHA256
 * 4. Installation locale (ajout en base + mise à jour packages.xml)
 * 5. Détection des mises à jour
 */
class AppStoreService
{
    private string $storagePath;
    private int $downloadTimeout;
    private int $syncTimeout;

    public function __construct()
    {
        $this->storagePath = config('sambaedu.wpkg.storage_path', '/var/se4fs/wpkg');
        $this->downloadTimeout = (int) config('sambaedu.wpkg.download_timeout', 300);
        $this->syncTimeout = (int) config('sambaedu.wpkg.sync_timeout', 30);
    }

    // ========================================
    // SYNCHRONISATION DU CATALOGUE
    // ========================================

    /**
     * Synchronise le catalogue d'applications depuis tous les dépôts actifs
     * 
     * @return array{synced: int, new: int, updated: int, errors: array}
     */
    public function syncAllDepots(): array
    {
        $stats = ['synced' => 0, 'new' => 0, 'updated' => 0, 'errors' => []];

        $depots = Depot::active()->get();

        foreach ($depots as $depot) {
            try {
                $result = $this->syncDepot($depot);
                $stats['synced']++;
                $stats['new'] += $result['new'];
                $stats['updated'] += $result['updated'];
            } catch (\Exception $e) {
                $stats['errors'][] = "Dépôt '{$depot->name}': " . $e->getMessage();
                Log::error('[AppStore] Erreur sync dépôt', [
                    'depot_id' => $depot->id,
                    'depot_name' => $depot->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[AppStore] Synchronisation terminée', $stats);
        return $stats;
    }

    /**
     * Synchronise un dépôt spécifique en récupérant son XML distant
     * 
     * @return array{new: int, updated: int}
     */
    public function syncDepot(Depot $depot): array
    {
        Log::info('[AppStore] Synchronisation du dépôt', ['depot' => $depot->name, 'url' => $depot->url]);

        $xmlUrl = str_ends_with($depot->url, '/packages.xml')
            ? $depot->url
            : rtrim($depot->url, '/') . '/packages.xml';
        $response = Http::timeout($this->syncTimeout)->get($xmlUrl);

        if (!$response->successful()) {
            throw new \RuntimeException("Impossible de récupérer {$xmlUrl} (HTTP {$response->status()})");
        }

        $xmlContent = $response->body();
        $newHash = hash('sha256', $xmlContent);

        // Vérifier si le XML a changé
        if ($depot->xml_hash === $newHash) {
            Log::debug('[AppStore] Dépôt inchangé', ['depot' => $depot->name]);
            return ['new' => 0, 'updated' => 0];
        }

        $stats = $this->parseAndUpsertApplications($depot, $xmlContent);

        // Mettre à jour le hash
        $depot->update(['xml_hash' => $newHash]);

        return $stats;
    }

    /**
     * Parse le XML du dépôt et met à jour la table depot_applications
     * 
     * Les applications sont stockées dans depot_applications (catalogue distant).
     * Elles ne sont copiées dans applications que lors de l'installation.
     * 
     * @return array{new: int, updated: int}
     */
    private function parseAndUpsertApplications(Depot $depot, string $xmlContent): array
    {
        $stats = ['new' => 0, 'updated' => 0];

        $xml = new \DOMDocument();
        $xml->loadXML($xmlContent);

        // Parcourir les branches pour extraire la branche parente de chaque package
        $branches = $xml->getElementsByTagName('branch');

        DB::beginTransaction();
        try {
            foreach ($branches as $branch) {
                $branchId = $branch->getAttribute('id') ?: 'stable';
                $packages = $branch->getElementsByTagName('package');

                foreach ($packages as $package) {
                    $appId = $package->getAttribute('id');
                    $name = $package->getAttribute('name');
                    $version = $package->getAttribute('revision') ?: $package->getAttribute('version');

                    if (empty($appId) || empty($name)) {
                        continue;
                    }

                    $data = [
                        'name' => $name,
                        'version' => $version ?: null,
                        'category' => $package->getAttribute('category') ?: null,
                        'compatibility' => $package->getAttribute('compatibilite') ?: null,
                        'branch' => $branchId,
                        'description' => $this->extractDescription($package),
                        'author' => $package->getAttribute('author') ?: null,
                        'icon_url' => $this->extractIconUrl($depot, $package),
                        'xml_url' => $package->getAttribute('url') ?: null,
                        'xml_sha' => $package->getAttribute('hash') ?: null,
                        'log_url' => $package->getAttribute('log') ?: null,
                        'last_checked_at' => now(),
                    ];

                    // Clé unique : depot_id + app_id + branch
                    $existing = DepotApplication::where('depot_id', $depot->id)
                        ->where('app_id', $appId)
                        ->where('branch', $branchId)
                        ->first();

                    if ($existing) {
                        $existing->update($data);
                        $stats['updated']++;
                    } else {
                        DepotApplication::create(array_merge($data, [
                            'depot_id' => $depot->id,
                            'app_id' => $appId,
                        ]));
                        $stats['new']++;
                    }
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $stats;
    }

    // ========================================
    // INSTALLATION D'APPLICATION
    // ========================================

    /**
     * Installe une application depuis le dépôt distant vers le catalogue local
     * 
     * Flow complet :
     * 1. Copie les infos de depot_applications vers applications
     * 2. Télécharge package.xml + installeur
     * 3. Vérifie l'intégrité SHA256
     * 4. Met à jour packages.xml local
     * 
     * @param DepotApplication $depotApp Application du catalogue distant
     * @param string $initiatedBy Utilisateur ayant initié l'installation
     * @return InstallationLog Le log de l'installation
     */
    public function installApplication(DepotApplication $depotApp, string $initiatedBy = 'system'): InstallationLog
    {
        Log::info('[AppStore] Début installation', [
            'app_id' => $depotApp->app_id,
            'name' => $depotApp->name,
            'version' => $depotApp->version,
        ]);

        // Créer ou récupérer l'application locale
        $application = Application::firstOrCreate(
            ['app_id' => $depotApp->app_id],
            [
                'depot_id' => $depotApp->depot_id,
                'name' => $depotApp->name,
                'version' => $depotApp->version,
                'category' => $depotApp->category,
                'compatibility' => $depotApp->compatibility,
                'branch' => $depotApp->branch,
                'xml' => $depotApp->xml,
                'xml_url' => $depotApp->xml_url,
                'xml_sha' => $depotApp->xml_sha,
                'log_url' => $depotApp->log_url,
                'description' => $depotApp->description,
                'author' => $depotApp->author,
                'icon_url' => $depotApp->icon_url,
                'status' => ApplicationStatus::Downloading,
            ]
        );

        // Créer le log d'installation
        $log = InstallationLog::create([
            'application_id' => $application->id,
            'status' => InstallationStatus::Pending,
            'version' => $depotApp->version,
            'initiated_by' => $initiatedBy,
            'started_at' => now(),
        ]);

        try {
            // Marquer l'application en téléchargement
            $application->update(['status' => ApplicationStatus::Downloading]);

            // Étape 1 : Télécharger le XML
            $log->update(['status' => InstallationStatus::Downloading, 'message' => 'Téléchargement du package XML...', 'progress' => 10]);
            $xmlPath = $this->downloadPackageXml($application);

            // Étape 2 : Télécharger l'installeur
            $log->update(['message' => 'Téléchargement de l\'installeur...', 'progress' => 30]);
            $installerPath = $this->downloadInstaller($application, $log);

            // Étape 3 : Vérifier l'intégrité SHA256
            $log->update(['status' => InstallationStatus::Verifying, 'message' => 'Vérification de l\'intégrité SHA256...', 'progress' => 70]);
            $this->verifyIntegrity($application, $installerPath, $log);

            // Étape 4 : Installer (mettre à jour la base + packages.xml local)
            $log->update(['status' => InstallationStatus::Installing, 'message' => 'Installation en cours...', 'progress' => 85]);
            $this->finalizeInstallation($application, $xmlPath, $installerPath);

            // Succès
            $log->update([
                'status' => InstallationStatus::Success,
                'message' => 'Installation terminée avec succès',
                'progress' => 100,
                'completed_at' => now(),
            ]);

            Log::info('[AppStore] Installation réussie', [
                'app_id' => $application->app_id,
                'version' => $application->version,
            ]);

            return $log;

        } catch (\Exception $e) {
            $log->update([
                'status' => InstallationStatus::Failed,
                'message' => 'Erreur: ' . $e->getMessage(),
                'completed_at' => now(),
            ]);

            $application->update(['status' => ApplicationStatus::Error]);

            Log::error('[AppStore] Erreur installation', [
                'app_id' => $application->app_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $log;
        }
    }

    /**
     * Télécharge le fichier package.xml de l'application
     */
    private function downloadPackageXml(Application $application): string
    {
        if (empty($application->xml_url)) {
            throw new \RuntimeException('URL du XML non définie pour ' . $application->app_id);
        }

        $response = Http::timeout($this->syncTimeout)->get($application->xml_url);

        if (!$response->successful()) {
            throw new \RuntimeException("Échec téléchargement XML: HTTP {$response->status()}");
        }

        $dir = $this->getAppStoragePath($application);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $xmlPath = $dir . '/package.xml';
        file_put_contents($xmlPath, $response->body());

        // Stocker le contenu XML dans le modèle
        $application->update([
            'xml' => $response->body(),
            'local_xml_path' => $xmlPath,
        ]);

        return $xmlPath;
    }

    /**
     * Télécharge l'installeur de l'application
     */
    private function downloadInstaller(Application $application, InstallationLog $log): ?string
    {
        if (empty($application->installer_url)) {
            Log::debug('[AppStore] Pas d\'installeur à télécharger', ['app_id' => $application->app_id]);
            return null;
        }

        $dir = $this->getAppStoragePath($application);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = $application->installer_filename ?: basename(parse_url($application->installer_url, PHP_URL_PATH));
        $installerPath = $dir . '/' . $filename;

        // Téléchargement avec suivi de progression
        $response = Http::timeout($this->downloadTimeout)
            ->withOptions(['sink' => $installerPath])
            ->get($application->installer_url);

        if (!file_exists($installerPath)) {
            throw new \RuntimeException("Échec téléchargement installeur: fichier non créé");
        }

        $fileSize = filesize($installerPath);
        $log->update([
            'downloaded_bytes' => $fileSize,
            'total_bytes' => $application->installer_size ?: $fileSize,
        ]);

        $application->update([
            'local_installer_path' => $installerPath,
        ]);

        return $installerPath;
    }

    /**
     * Vérifie l'intégrité SHA256 de l'installeur téléchargé
     */
    private function verifyIntegrity(Application $application, ?string $installerPath, InstallationLog $log): void
    {
        if (!$installerPath || empty($application->installer_sha256)) {
            Log::debug('[AppStore] Vérification SHA256 ignorée (pas d\'installeur ou pas de hash)', [
                'app_id' => $application->app_id,
            ]);
            $log->update(['sha256_verified' => true]);
            return;
        }

        $computedHash = hash_file('sha256', $installerPath);
        $log->update(['sha256_computed' => $computedHash]);

        if (strtolower($computedHash) !== strtolower($application->installer_sha256)) {
            $log->update(['sha256_verified' => false]);
            // Supprimer le fichier corrompu
            @unlink($installerPath);
            throw new \RuntimeException(
                "Intégrité SHA256 invalide. Attendu: {$application->installer_sha256}, Calculé: {$computedHash}"
            );
        }

        $log->update(['sha256_verified' => true]);
        Log::info('[AppStore] Intégrité SHA256 vérifiée', ['app_id' => $application->app_id]);
    }

    /**
     * Finalise l'installation : met à jour la base et le packages.xml local
     */
    private function finalizeInstallation(Application $application, string $xmlPath, ?string $installerPath): void
    {
        $application->update([
            'status' => ApplicationStatus::Installed,
            'installed_version' => $application->version,
            'installed_at' => now(),
            'local_xml_path' => $xmlPath,
            'local_installer_path' => $installerPath,
        ]);

        // Mettre à jour le packages.xml local
        $this->updateLocalPackagesXml();
    }

    /**
     * Régénère le fichier packages.xml local à partir des applications installées
     */
    public function updateLocalPackagesXml(): void
    {
        $installedApps = Application::installed()->get();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('packages');
        $dom->appendChild($root);

        foreach ($installedApps as $app) {
            if (!empty($app->xml)) {
                $fragment = new \DOMDocument();
                $fragment->loadXML($app->xml);
                $imported = $dom->importNode($fragment->documentElement, true);
                $root->appendChild($imported);
            }
        }

        $packagesXmlPath = $this->storagePath . '/packages.xml';
        $dir = dirname($packagesXmlPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $dom->save($packagesXmlPath);

        Log::info('[AppStore] packages.xml local mis à jour', [
            'path' => $packagesXmlPath,
            'applications_count' => $installedApps->count(),
        ]);
    }

    // ========================================
    // DÉSINSTALLATION
    // ========================================

    /**
     * Désinstalle une application (supprime les fichiers locaux, remet le statut à available)
     */
    public function uninstallApplication(Application $application): void
    {
        Log::info('[AppStore] Désinstallation', ['app_id' => $application->app_id]);

        // Supprimer les fichiers locaux
        $dir = $this->getAppStoragePath($application);
        if (is_dir($dir)) {
            $this->removeDirectory($dir);
        }

        $application->update([
            'status' => ApplicationStatus::Available,
            'installed_version' => null,
            'installed_at' => null,
            'local_xml_path' => null,
            'local_installer_path' => null,
        ]);

        $this->updateLocalPackagesXml();
    }

    // ========================================
    // CONSULTATION DU CATALOGUE DISTANT
    // ========================================

    /**
     * Retourne la liste des dépôts, triés par principal d'abord puis par nom
     */
    public function listDepots(): Collection
    {
        return Depot::orderByDesc('is_primary')->orderBy('name')->get();
    }

    /**
     * Retourne le dépôt par défaut (principal ou premier disponible)
     */
    public function getDefaultDepot(): ?Depot
    {
        return Depot::primary()->first() ?? Depot::first();
    }

    /**
     * Retourne les applications d'un dépôt avec statut d'installation, paginées
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function listDepotApplications(
        int $depotId,
        int $perPage = 20,
        ?string $search = null,
        ?string $category = null,
        ?string $branch = null,
    ) {
        $installedApps = Application::pluck('version', 'app_id')->toArray();

        $query = DepotApplication::query()
            ->where('depot_id', $depotId)
            ->when($search, fn ($q) => $q->search($search))
            ->when($category, fn ($q) => $q->byCategory($category))
            ->when($branch, fn ($q) => $q->byBranch($branch))
            ->orderBy('name');

        $paginated = $query->paginate($perPage);

        $paginated->getCollection()->transform(function ($app) use ($installedApps) {
            $app->is_installed = array_key_exists($app->app_id, $installedApps);
            if ($app->is_installed) {
                $app->local_version = $installedApps[$app->app_id];
                $app->has_update = $installedApps[$app->app_id] !== $app->version;
            }

            return $app;
        });

        return $paginated;
    }

    /**
     * Retourne les statistiques d'un dépôt spécifique
     */
    public function getDepotStats(int $depotId): array
    {
        $total = DepotApplication::where('depot_id', $depotId)->count();

        $stats = DepotApplication::query()
            ->where('depot_applications.depot_id', $depotId)
            ->join('applications', 'depot_applications.app_id', '=', 'applications.app_id')
            ->selectRaw('COUNT(*) as installed')
            ->selectRaw('SUM(CASE WHEN depot_applications.version != applications.version THEN 1 ELSE 0 END) as updatable')
            ->first();

        return [
            'total' => $total,
            'installed' => (int) ($stats->installed ?? 0),
            'updatable' => (int) ($stats->updatable ?? 0),
        ];
    }

    /**
     * Retourne les catégories distinctes d'un dépôt
     */
    public function getDepotCategories(int $depotId): array
    {
        return DepotApplication::where('depot_id', $depotId)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->toArray();
    }

    /**
     * Retourne les branches distinctes d'un dépôt
     */
    public function getDepotBranches(int $depotId): array
    {
        return DepotApplication::where('depot_id', $depotId)
            ->whereNotNull('branch')
            ->where('branch', '!=', '')
            ->distinct()
            ->orderBy('branch')
            ->pluck('branch')
            ->toArray();
    }

    // ========================================
    // STATISTIQUES
    // ========================================

    /**
     * Retourne les statistiques du magasin d'applications
     */
    public function getStats(): array
    {
        return [
            'total' => Application::count(),
            'available' => Application::available()->count(),
            'installed' => Application::installed()->count(),
            'updatable' => Application::updatable()->count(),
            'downloading' => Application::where('status', ApplicationStatus::Downloading)->count(),
            'error' => Application::where('status', ApplicationStatus::Error)->count(),
            'depots_count' => Depot::active()->count(),
            'categories' => Application::whereNotNull('category')
                ->where('category', '!=', '')
                ->distinct('category')
                ->count(),
        ];
    }

    // ========================================
    // UTILITAIRES
    // ========================================

    /**
     * Retourne le chemin de stockage local pour une application
     */
    private function getAppStoragePath(Application $application): string
    {
        return $this->storagePath . '/apps/' . $application->app_id;
    }

    /**
     * Supprime récursivement un répertoire
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Extrait la description depuis un noeud XML package
     */
    private function extractDescription(\DOMElement $package): ?string
    {
        $desc = $package->getAttribute('description');
        if (!empty($desc)) {
            return $desc;
        }

        // Chercher un sous-élément <description>
        $descNodes = $package->getElementsByTagName('description');
        if ($descNodes->length > 0) {
            return $descNodes->item(0)->textContent;
        }

        return null;
    }

    /**
     * Extrait l'URL de l'installeur depuis le XML
     */
    private function extractInstallerUrl(Depot $depot, \DOMElement $package): ?string
    {
        // Chercher dans les éléments <install> ou <download>
        foreach (['install', 'download'] as $tag) {
            $nodes = $package->getElementsByTagName($tag);
            for ($i = 0; $i < $nodes->length; $i++) {
                $cmd = $nodes->item($i)->getAttribute('cmd');
                if (!empty($cmd) && (str_starts_with($cmd, 'http') || str_starts_with($cmd, '//'))) {
                    return $cmd;
                }
            }
        }

        // Construire l'URL depuis le dépôt
        $appId = $package->getAttribute('id');
        if (!empty($appId)) {
            return rtrim($depot->url, '/') . '/' . $appId . '/';
        }

        return null;
    }

    /**
     * Extrait le hash SHA256 de l'installeur
     */
    private function extractInstallerSha256(\DOMElement $package): ?string
    {
        $sha = $package->getAttribute('sha256');
        if (!empty($sha)) {
            return $sha;
        }

        // Chercher dans les sous-éléments
        $checksumNodes = $package->getElementsByTagName('checksum');
        for ($i = 0; $i < $checksumNodes->length; $i++) {
            $node = $checksumNodes->item($i);
            if ($node->getAttribute('type') === 'sha256') {
                return $node->textContent;
            }
        }

        return null;
    }

    /**
     * Extrait le nom du fichier installeur
     */
    private function extractInstallerFilename(\DOMElement $package): ?string
    {
        $filename = $package->getAttribute('filename');
        if (!empty($filename)) {
            return $filename;
        }

        return null;
    }

    /**
     * Extrait la taille de l'installeur
     */
    private function extractInstallerSize(\DOMElement $package): ?int
    {
        $size = $package->getAttribute('size');
        if (!empty($size) && is_numeric($size)) {
            return (int) $size;
        }

        return null;
    }

    /**
     * Extrait l'URL de l'icône
     */
    private function extractIconUrl(Depot $depot, \DOMElement $package): ?string
    {
        $icon = $package->getAttribute('icon');
        if (!empty($icon)) {
            if (str_starts_with($icon, 'http')) {
                return $icon;
            }
            return rtrim($depot->url, '/') . '/' . $icon;
        }

        // Convention : icône dans le dossier de l'app
        $appId = $package->getAttribute('id');
        if (!empty($appId)) {
            return rtrim($depot->url, '/') . '/' . $appId . '/icon.png';
        }

        return null;
    }
}
