<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Depot;
use App\Models\InstallationLog;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Repository pour les requêtes du magasin d'applications
 * 
 * Fournit les méthodes de lecture pour l'interface App Store.
 */
class AppStoreRepository
{
    /**
     * Liste les applications du catalogue avec filtres et pagination
     */
    public function listApplications(
        int $perPage = 20,
        ?string $search = null,
        ?string $category = null,
        ?string $status = null,
        ?int $depotId = null,
        string $sortBy = 'name',
        string $sortDir = 'asc'
    ): LengthAwarePaginator {
        $query = Application::query()->with('depot');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('app_id', 'ILIKE', "%{$search}%")
                    ->orWhere('description', 'ILIKE', "%{$search}%")
                    ->orWhere('author', 'ILIKE', "%{$search}%");
            });
        }

        if ($category) {
            $query->where('category', $category);
        }

        if ($status) {
            $statusEnum = ApplicationStatus::tryFrom($status);
            if ($statusEnum) {
                $query->where('status', $statusEnum);
            }
        }

        if ($depotId) {
            $query->where('depot_id', $depotId);
        }

        $allowedSorts = ['name', 'category', 'version', 'status', 'installed_at', 'created_at'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'name';
        }

        return $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    }

    /**
     * Récupère une application par son ID avec ses relations
     */
    public function find(int $id): ?Application
    {
        return Application::with(['depot', 'installationLogs'])->find($id);
    }

    /**
     * Récupère une application par son app_id technique
     */
    public function findByAppId(string $appId): ?Application
    {
        return Application::where('app_id', $appId)->with('depot')->first();
    }

    /**
     * Récupère les catégories disponibles avec le nombre d'applications
     */
    public function getCategories(): Collection
    {
        return Application::query()
            ->selectRaw('category, COUNT(*) as count')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->groupBy('category')
            ->orderBy('category')
            ->get();
    }

    /**
     * Récupère les dépôts actifs avec le nombre d'applications
     */
    public function getDepots(): Collection
    {
        return Depot::active()
            ->withCount('applications')
            ->orderBy('name')
            ->get();
    }

    /**
     * Récupère les applications installées
     */
    public function getInstalledApplications(): Collection
    {
        return Application::installed()
            ->with('depot')
            ->orderBy('name')
            ->get();
    }

    /**
     * Récupère les applications avec mises à jour disponibles
     */
    public function getUpdatableApplications(): Collection
    {
        return Application::updatable()
            ->with('depot')
            ->orderBy('name')
            ->get();
    }

    /**
     * Récupère les logs d'installation récents
     */
    public function getRecentInstallationLogs(int $limit = 20): Collection
    {
        return InstallationLog::with('application')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Récupère les installations en cours
     */
    public function getActiveInstallations(): Collection
    {
        return InstallationLog::with('application')
            ->inProgress()
            ->orderByDesc('created_at')
            ->get();
    }
}
