<?php

namespace App\Repositories;

use App\Config\LdapDnHelper;
use App\LdapModels\DeviceGroupTagModel;
use App\Models\AppProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Repository pour la gestion des profils applicatifs (AppProfiles)
 * 
 * Fournit une interface unifiée pour accéder aux données des profils applicatifs.
 * Gère les données SQL et AD (via LdapRecord).
 * 
 * Les AppProfiles correspondent aux CN dans OU=Parcs de l'AD.
 */
class AppProfileRepository
{
    public function __construct(
        private LdapDnHelper $dnHelper
    ) {
    }

    // ========================================
    // LECTURE AD - PROFILS (CN)
    // ========================================

    /**
     * Récupère tous les profils applicatifs (CN) depuis l'AD
     * 
     * Retourne uniquement les CN dans OU=Parcs
     * 
     * @return array Liste des profils avec name, dn, guid, description, samaccountname
     */
    public function getAllFromAd(): array
    {
        $profiles = [];
        $parcsDn = $this->dnHelper->parcs();

        try {
            // Récupérer tous les groupes et filtrer ceux qui sont dans ou=Parcs
            // LdapRecord ne respecte pas toujours le baseDn() du modèle
            $groups = DeviceGroupTagModel::get();

            foreach ($groups as $group) {
                $dn = $group->getDn();

                // Filtrer : ne garder que les groupes directement dans ou=Parcs
                // Le DN doit se terminer par ou=Parcs,dc=...
                if (!str_contains(strtolower($dn), strtolower($parcsDn))) {
                    continue;
                }

                $name = $group->getFirstAttribute('cn');
                if (empty($name)) {
                    continue;
                }

                $profiles[] = [
                    'name' => $name,
                    'dn' => $dn,
                    'guid' => $group->getConvertedGuid(),
                    'description' => $group->getFirstAttribute('description'),
                    'samaccountname' => $group->getFirstAttribute('samaccountname'),
                ];
            }

            Log::debug('[AppProfileRepository] Profils applicatifs récupérés depuis AD', [
                'count' => count($profiles),
                'parcsDn' => $parcsDn,
            ]);

            return $profiles;

        } catch (\Exception $e) {
            Log::error('[AppProfileRepository] Erreur récupération profils AD', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

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
