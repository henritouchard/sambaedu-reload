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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service principal du magasin d'applications — orchestrateur
 *
 * Delegue la synchronisation a DepotSyncService, la generation
 * du packages.xml a PackagesXmlService. Conserve la consultation
 * du catalogue, les stats, et le flow d'installation (jusqu'a 8.2.6).
 */
class AppStoreService
{
    private string $storagePath;
    private int $downloadTimeout;
    private int $syncTimeout;

    public function __construct(
        private DepotSyncService $depotSyncService,
        private PackagesXmlService $packagesXmlService,
        private PackageInstallerService $packageInstallerService,
    ) {
        $this->storagePath = config('sambaedu.wpkg.storage_path', '/var/se4fs/wpkg');
        $this->downloadTimeout = (int) config('sambaedu.wpkg.download_timeout', 300);
        $this->syncTimeout = (int) config('sambaedu.wpkg.sync_timeout', 30);
    }

    // ========================================
    // SYNCHRONISATION DU CATALOGUE (delegue)
    // ========================================

    /**
     * Synchronise le catalogue d'applications depuis tous les depots actifs
     *
     * @return array{synced: int, new: int, updated: int, errors: array}
     */
    public function syncAllDepots(): array
    {
        return $this->depotSyncService->syncAllDepots();
    }

    /**
     * Synchronise un depot specifique en recuperant son XML distant
     *
     * @return array{new: int, updated: int}
     */
    public function syncDepot(Depot $depot): array
    {
        return $this->depotSyncService->syncDepot($depot);
    }

    // ========================================
    // INSTALLATION D'APPLICATION
    // ========================================

    /**
     * Installe une application depuis le depot distant vers le catalogue local
     *
     * Flow complet :
     * 1. Copie les infos de depot_applications vers applications
     * 2. Telecharge package.xml + installeur
     * 3. Verifie l'integrite SHA256
     * 4. Met a jour packages.xml local
     *
     * @param DepotApplication $depotApp Application du catalogue distant
     * @param string $initiatedBy Utilisateur ayant initie l'installation
     * @return InstallationLog Le log de l'installation
     */
    public function installApplication(DepotApplication $depotApp, string $initiatedBy = 'system'): InstallationLog
    {
        Log::info('[AppStore] Debut installation', [
            'app_id' => $depotApp->app_id,
            'name' => $depotApp->name,
            'version' => $depotApp->version,
        ]);

        // Creer ou recuperer l'application locale
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
                'description' => null,
                'author' => null,
                'icon_url' => $depotApp->icon_url,
                'status' => ApplicationStatus::Downloading,
            ]
        );

        // Creer le log d'installation
        $log = InstallationLog::create([
            'application_id' => $application->id,
            'status' => InstallationStatus::Pending,
            'version' => $depotApp->version,
            'initiated_by' => $initiatedBy,
            'started_at' => now(),
        ]);

        try {
            // Marquer l'application en telechargement
            $application->update(['status' => ApplicationStatus::Downloading]);

            // Etape 1 : Telecharger le XML recipe, verifier le hash, parser les directives
            $log->update(['status' => InstallationStatus::Downloading, 'message' => 'Telechargement du package XML...', 'progress' => 10]);
            $xmlPath = $this->packageInstallerService->downloadXmlRecipe($depotApp);
            $this->packageInstallerService->verifyXmlHash($xmlPath, $depotApp->xml_sha);
            $directives = $this->packageInstallerService->parseDirectives($xmlPath);

            Log::debug('[AppStore] Directives XML parsees', [
                'app_id' => $depotApp->app_id,
                'packages' => count($directives['packages']),
                'downloads' => count($directives['downloads']),
                'deletes' => count($directives['deletes']),
                'untars' => count($directives['untars']),
                'unzips' => count($directives['unzips']),
            ]);

            // Stocker le contenu XML dans Application (comme avant)
            $application->update([
                'xml' => file_get_contents($xmlPath),
                'local_xml_path' => $xmlPath,
            ]);

            // Etape 2 : Telecharger l'installeur
            $log->update(['message' => 'Telechargement de l\'installeur...', 'progress' => 30]);
            $installerPath = $this->downloadInstaller($application, $log);

            // Etape 3 : Verifier l'integrite SHA256
            $log->update(['status' => InstallationStatus::Verifying, 'message' => 'Verification de l\'integrite SHA256...', 'progress' => 70]);
            $this->verifyIntegrity($application, $installerPath, $log);

            // Etape 4 : Installer (mettre a jour la base + packages.xml local)
            $log->update(['status' => InstallationStatus::Installing, 'message' => 'Installation en cours...', 'progress' => 85]);
            $this->finalizeInstallation($application, $xmlPath, $installerPath);

            // Succes
            $log->update([
                'status' => InstallationStatus::Success,
                'message' => 'Installation terminee avec succes',
                'progress' => 100,
                'completed_at' => now(),
            ]);

            Log::info('[AppStore] Installation reussie', [
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

            throw $e;
        }
    }

    /**
     * Telecharge le fichier package.xml de l'application
     */
    private function downloadPackageXml(Application $application): string
    {
        if (empty($application->xml_url)) {
            throw new \RuntimeException('URL du XML non definie pour ' . $application->app_id);
        }

        $response = Http::timeout($this->syncTimeout)->get($application->xml_url);

        if (!$response->successful()) {
            throw new \RuntimeException("Echec telechargement XML: HTTP {$response->status()}");
        }

        $dir = $this->getAppStoragePath($application);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $xmlPath = $dir . '/package.xml';
        file_put_contents($xmlPath, $response->body());

        // Stocker le contenu XML dans le modele
        $application->update([
            'xml' => $response->body(),
            'local_xml_path' => $xmlPath,
        ]);

        return $xmlPath;
    }

    /**
     * Telecharge l'installeur de l'application
     */
    private function downloadInstaller(Application $application, InstallationLog $log): ?string
    {
        if (empty($application->installer_url)) {
            Log::debug('[AppStore] Pas d\'installeur a telecharger', ['app_id' => $application->app_id]);
            return null;
        }

        $dir = $this->getAppStoragePath($application);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = $application->installer_filename ?: basename(parse_url($application->installer_url, PHP_URL_PATH));
        $installerPath = $dir . '/' . $filename;

        // Telechargement avec suivi de progression
        $response = Http::timeout($this->downloadTimeout)
            ->withOptions(['sink' => $installerPath])
            ->get($application->installer_url);

        if (!file_exists($installerPath)) {
            throw new \RuntimeException("Echec telechargement installeur: fichier non cree");
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
     * Verifie l'integrite SHA256 de l'installeur telecharge
     */
    private function verifyIntegrity(Application $application, ?string $installerPath, InstallationLog $log): void
    {
        if (!$installerPath || empty($application->installer_sha256)) {
            Log::debug('[AppStore] Verification SHA256 ignoree (pas d\'installeur ou pas de hash)', [
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
                "Integrite SHA256 invalide. Attendu: {$application->installer_sha256}, Calcule: {$computedHash}"
            );
        }

        $log->update(['sha256_verified' => true]);
        Log::info('[AppStore] Integrite SHA256 verifiee', ['app_id' => $application->app_id]);
    }

    /**
     * Finalise l'installation : met a jour la base et le packages.xml local
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

        // Mettre a jour le packages.xml local
        $this->updateLocalPackagesXml();
    }

    /**
     * Regenere le fichier packages.xml local (delegue a PackagesXmlService)
     */
    public function updateLocalPackagesXml(): void
    {
        $this->packagesXmlService->regenerate();
    }

    // ========================================
    // DESINSTALLATION
    // ========================================

    /**
     * Desinstalle une application (supprime les fichiers locaux, remet le statut a available)
     */
    public function uninstallApplication(Application $application): void
    {
        Log::info('[AppStore] Desinstallation', ['app_id' => $application->app_id]);

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
     * Retourne la liste des depots, tries par principal d'abord puis par nom
     */
    public function listDepots(): Collection
    {
        return Depot::active()->orderByDesc('is_primary')->orderBy('name')->get();
    }

    /**
     * Retourne le depot par defaut (principal ou premier disponible)
     */
    public function getDefaultDepot(): ?Depot
    {
        return Depot::active()->primary()->first() ?? Depot::active()->first();
    }

    /**
     * Retourne les applications d'un depot avec statut d'installation, paginees
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
     * Retourne les statistiques d'un depot specifique
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
     * Retourne les categories distinctes d'un depot
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
     * Retourne les branches distinctes d'un depot
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
     * Supprime recursivement un repertoire
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
}
