<?php

namespace App\Repositories;

use App\Models\AppProfile;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Repository pur-SQL pour la gestion des profils applicatifs (AppProfiles).
 *
 * La lecture AD des CN sous OU=Parcs a été retirée (nettoyage post-38.7 :
 * OU=Parcs est en lecture seule, et seul l'import de migration
 * {@see \App\Services\AppProfile\AppProfileAdImporter} le consulte, via
 * DeviceGroupTagModel directement — pas via ce repository).
 */
class AppProfileRepository
{
    // ========================================
    // LECTURE SQL
    // ========================================

    /**
     * Récupère tous les profils avec pagination
     */
    public function getAll(
        int $perPage = 20,
        ?string $search = null
    ): LengthAwarePaginator {
        $query = AppProfile::query();

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * Récupère tous les profils
     */
    public function getAllActive(): Collection
    {
        return AppProfile::orderBy('name')->get();
    }

    /**
     * Récupère un profil par son ID
     */
    public function find(int $id): ?AppProfile
    {
        return AppProfile::with(['workstationGroups', 'applications'])->find($id);
    }

    /**
     * Récupère un profil par son nom
     */
    public function findByName(string $name): ?AppProfile
    {
        return AppProfile::where('name', $name)->first();
    }

    /**
     * Récupère un profil par son GUID AD
     */
    public function findByAdGuid(string $guid): ?AppProfile
    {
        return AppProfile::where('ad_guid', $guid)->first();
    }

    /**
     * Crée un nouveau profil
     */
    public function create(array $data): AppProfile
    {
        return AppProfile::create($data);
    }

    /**
     * Met à jour un profil
     */
    public function update(AppProfile $profile, array $data): bool
    {
        return $profile->update($data);
    }

    /**
     * Supprime un profil
     */
    public function delete(AppProfile $profile): bool
    {
        // Détacher les groupes et applications avant suppression
        $profile->workstationGroups()->detach();
        $profile->applications()->detach();

        return $profile->delete();
    }

    /**
     * Compte le nombre de profils
     */
    public function count(): int
    {
        return AppProfile::count();
    }

    // ========================================
    // RELATIONS
    // ========================================

    /**
     * Récupère les groupes associés à un profil
     */
    public function getWorkstationGroups(int $profileId): Collection
    {
        $profile = AppProfile::find($profileId);

        if (!$profile) {
            return collect();
        }

        return $profile->workstationGroups()->orderBy('name')->get();
    }

    /**
     * Définit les groupes d'un profil
     */
    public function setWorkstationGroups(int $profileId, array $groupIds): void
    {
        $profile = AppProfile::findOrFail($profileId);
        $profile->workstationGroups()->sync($groupIds);
    }

    /**
     * Récupère les applications associées à un profil
     */
    public function getApplications(int $profileId): Collection
    {
        $profile = AppProfile::find($profileId);

        if (!$profile) {
            return collect();
        }

        return $profile->applications()->orderBy('name')->get();
    }

    /**
     * Définit les applications d'un profil
     */
    public function setApplications(int $profileId, array $applicationIds): void
    {
        $profile = AppProfile::findOrFail($profileId);
        $profile->applications()->sync($applicationIds);
    }
}
