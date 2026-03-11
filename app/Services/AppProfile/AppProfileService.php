<?php

declare(strict_types=1);

namespace App\Services\AppProfile;

use App\Config\LdapDnHelper;
use App\LdapModels\DeviceGroupTagModel;
use App\Models\AppProfile;
use App\Models\Application;
use App\Models\WorkstationGroup;
use App\Observers\AppProfileObserver;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service pour la gestion des profils applicatifs
 * 
 * Un AppProfile est un groupe d'applications WPKG qui peut être assigné
 * à plusieurs WorkstationGroups (parcs). Cette architecture remplace
 * le système polymorphique legacy de applications_profile.
 */
class AppProfileService
{
    /**
     * Liste tous les profils applicatifs avec pagination
     */
    public function listProfiles(
        int $perPage = 20,
        ?string $search = null,
        ?bool $activeOnly = null
    ): LengthAwarePaginator {
        $query = AppProfile::query()
            ->withCount(['applications', 'workstationGroups']);

        if ($search) {
            $query->search($search);
        }

        if ($activeOnly !== null) {
            $query->where('is_active', $activeOnly);
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * Liste tous les profils pour un select (sans pagination)
     */
    public function listProfilesForSelect(): Collection
    {
        return AppProfile::active()
            ->orderBy('name')
            ->get(['id', 'name', 'display_name']);
    }

    /**
     * Récupère un profil par son ID avec ses relations
     */
    public function getProfile(int $id, bool $withRelations = true): ?AppProfile
    {
        $query = AppProfile::query();

        if ($withRelations) {
            $query->with(['applications', 'workstationGroups']);
        }

        return $query->find($id);
    }

    /**
     * Récupère un profil par son nom
     */
    public function getProfileByName(string $name): ?AppProfile
    {
        return AppProfile::where('name', $name)
            ->with(['applications', 'workstationGroups'])
            ->first();
    }

    /**
     * Crée un nouveau profil applicatif
     */
    public function createProfile(array $data): AppProfile
    {
        return DB::transaction(function () use ($data) {
            $profile = AppProfile::create([
                'name' => $data['name'],
                'display_name' => $data['display_name'] ?? null,
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (!empty($data['application_ids'])) {
                $profile->applications()->attach($data['application_ids']);
            }

            if (!empty($data['workstation_group_ids'])) {
                $profile->workstationGroups()->attach($data['workstation_group_ids']);
            }

            Log::info('[AppProfileService] Profil créé', [
                'id' => $profile->id,
                'name' => $profile->name,
            ]);

            return $profile;
        });
    }

    /**
     * Met à jour un profil applicatif
     */
    public function updateProfile(int $id, array $data): ?AppProfile
    {
        return DB::transaction(function () use ($id, $data) {
            $profile = AppProfile::find($id);

            if (!$profile) {
                return null;
            }

            $profile->update([
                'name' => $data['name'] ?? $profile->name,
                'display_name' => $data['display_name'] ?? $profile->display_name,
                'description' => $data['description'] ?? $profile->description,
                'is_active' => $data['is_active'] ?? $profile->is_active,
            ]);

            if (array_key_exists('application_ids', $data)) {
                $profile->applications()->sync($data['application_ids'] ?? []);
            }

            if (array_key_exists('workstation_group_ids', $data)) {
                $profile->workstationGroups()->sync($data['workstation_group_ids'] ?? []);
            }

            Log::info('[AppProfileService] Profil mis à jour', [
                'id' => $profile->id,
                'name' => $profile->name,
            ]);

            return $profile->fresh(['applications', 'workstationGroups']);
        });
    }

    /**
     * Supprime un profil applicatif
     */
    public function deleteProfile(int $id): bool
    {
        $profile = AppProfile::find($id);

        if (!$profile) {
            return false;
        }

        $name = $profile->name;
        $profile->delete();

        Log::info('[AppProfileService] Profil supprimé', [
            'id' => $id,
            'name' => $name,
        ]);

        return true;
    }

    /**
     * Ajoute des applications à un profil
     */
    public function addApplications(int $profileId, array $applicationIds): bool
    {
        $profile = AppProfile::find($profileId);

        if (!$profile) {
            return false;
        }

        $profile->applications()->syncWithoutDetaching($applicationIds);

        Log::info('[AppProfileService] Applications ajoutées au profil', [
            'profile_id' => $profileId,
            'application_ids' => $applicationIds,
        ]);

        return true;
    }

    /**
     * Retire des applications d'un profil
     */
    public function removeApplications(int $profileId, array $applicationIds): bool
    {
        $profile = AppProfile::find($profileId);

        if (!$profile) {
            return false;
        }

        $profile->applications()->detach($applicationIds);

        Log::info('[AppProfileService] Applications retirées du profil', [
            'profile_id' => $profileId,
            'application_ids' => $applicationIds,
        ]);

        return true;
    }

    /**
     * Ajoute des groupes de postes à un profil
     */
    public function addWorkstationGroups(int $profileId, array $groupIds): bool
    {
        $profile = AppProfile::find($profileId);

        if (!$profile) {
            return false;
        }

        $profile->workstationGroups()->syncWithoutDetaching($groupIds);

        Log::info('[AppProfileService] Groupes ajoutés au profil', [
            'profile_id' => $profileId,
            'group_ids' => $groupIds,
        ]);

        return true;
    }

    /**
     * Retire des groupes de postes d'un profil
     */
    public function removeWorkstationGroups(int $profileId, array $groupIds): bool
    {
        $profile = AppProfile::find($profileId);

        if (!$profile) {
            return false;
        }

        $profile->workstationGroups()->detach($groupIds);

        Log::info('[AppProfileService] Groupes retirés du profil', [
            'profile_id' => $profileId,
            'group_ids' => $groupIds,
        ]);

        return true;
    }

    /**
     * Ajoute des postes à un profil
     */
    public function addWorkstations(int $profileId, array $workstationIds): bool
    {
        $profile = AppProfile::find($profileId);

        if (!$profile) {
            return false;
        }

        // Convertir les IDs en integers et filtrer les valeurs invalides
        $workstationIds = array_filter(
            array_map('intval', $workstationIds),
            fn($id) => $id > 0
        );

        if (empty($workstationIds)) {
            return false;
        }

        $profile->workstations()->syncWithoutDetaching($workstationIds);

        Log::info('[AppProfileService] Postes ajoutés au profil', [
            'profile_id' => $profileId,
            'workstation_ids' => $workstationIds,
        ]);

        return true;
    }

    /**
     * Retire des postes d'un profil
     */
    public function removeWorkstations(int $profileId, array $workstationIds): bool
    {
        $profile = AppProfile::find($profileId);

        if (!$profile) {
            return false;
        }

        $profile->workstations()->detach($workstationIds);

        Log::info('[AppProfileService] Postes retirés du profil', [
            'profile_id' => $profileId,
            'workstation_ids' => $workstationIds,
        ]);

        return true;
    }

    /**
     * Récupère les statistiques globales
     */
    public function getStats(): array
    {
        return [
            'profiles_count' => AppProfile::count(),
            'active_profiles_count' => AppProfile::active()->count(),
            'applications_count' => Application::count(),
            'workstation_groups_count' => WorkstationGroup::count(),
        ];
    }

    /**
     * Liste toutes les applications disponibles avec pagination
     * Utilise depot_applications car la table applications est vide
     */
    public function listApplications(
        int $perPage = 20,
        ?string $search = null,
        ?string $category = null,
        ?bool $activeOnly = true
    ): LengthAwarePaginator {
        $query = Application::query()
            ->with('depot');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('app_id', 'ILIKE', "%{$search}%");
            });
        }

        if ($category) {
            $query->where('category', $category);
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * Liste toutes les applications pour un select (sans pagination)
     */
    public function listApplicationsForSelect(): Collection
    {
        return Application::query()
            ->orderBy('name')
            ->get(['id', 'app_id', 'name', 'version', 'category']);
    }

    /**
     * Récupère les catégories d'applications disponibles
     */
    public function getCategories(): Collection
    {
        return Application::query()
            ->select('category')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
    }

    /**
     * Récupère une application par son ID
     */
    public function getApplication(int $id): ?Application
    {
        return Application::with(['depot'])->find($id);
    }

    /**
     * Récupère les profils assignés à un groupe de postes
     */
    public function getProfilesForGroup(int $groupId): Collection
    {
        return AppProfile::whereHas('workstationGroups', function ($query) use ($groupId) {
            $query->where('workstation_groups.id', $groupId);
        })->get();
    }

    /**
     * Récupère toutes les applications effectives pour un groupe de postes
     * (via tous ses profils applicatifs)
     */
    public function getApplicationsForGroup(int $groupId): Collection
    {
        $profiles = $this->getProfilesForGroup($groupId);

        return Application::whereHas('appProfiles', function ($query) use ($profiles) {
            $query->whereIn('app_profiles.id', $profiles->pluck('id'));
        })->get();
    }

    // ========================================
    // IMPORT DEPUIS L'AD (MIGRATION INITIALE)
    // ========================================

    /**
     * Importe les profils applicatifs depuis l'Active Directory vers la base de données SQL.
     * 
     * ⚠️ WARNING: Cette méthode ne devrait être utilisée QUE pour l'initialisation initiale
     * de la base de données Laravel. Une fois l'import effectué, SQL devient la source de vérité
     * et les modifications doivent être faites via l'interface Laravel, qui synchronisera
     * automatiquement vers l'AD via les observers.
     * 
     * @deprecated Utiliser uniquement pour la migration initiale AD → SQL
     * @param callable|null $logCallback Callback pour les logs (fn(string $level, string $message) => void)
     * @return array Statistiques d'import ['created' => int, 'updated' => int, 'skipped' => int, 'linked_groups' => int, 'errors' => array]
     */
    public function importFromAd(?callable $logCallback = null): array
    {
        Log::warning('AppProfileService::importFromAd() appelé - Cette méthode ne devrait être utilisée que pour l\'initialisation initiale. SQL est la source de vérité.');

        $log = $logCallback ?? fn(string $level, string $message) => Log::log($level, $message);
        
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'linked_groups' => 0,
            'errors' => [],
        ];

        try {
            $dnHelper = app(LdapDnHelper::class);
            $parcsDn = $dnHelper->parcsDn();
            $log('info', "Recherche dans: {$parcsDn}");

            // Récupérer les parcs depuis l'AD
            $parcsAd = DeviceGroupTagModel::in($parcsDn)->get();
            $log('info', count($parcsAd) . ' profils trouvés dans l\'AD');

            // Désactiver la synchronisation AD pendant l'import
            AppProfileObserver::disableSync();

            try {
                DB::beginTransaction();

                // Pré-charger les groupes pour les liens
                $groups = WorkstationGroup::all()->keyBy(fn($g) => strtolower($g->name));

                foreach ($parcsAd as $parc) {
                    try {
                        $name = $parc->getParcName();
                        if (empty($name)) {
                            continue;
                        }

                        $rawGuid = $parc->getFirstAttribute('objectguid');
                        $uuid = $rawGuid ? $this->convertGuidToString($rawGuid) : null;
                        $description = $parc->getDescription();

                        $existing = AppProfile::where('name', $name)->first();

                        if ($existing) {
                            $updated = false;
                            if (empty($existing->ad_guid) && !empty($uuid)) {
                                $existing->ad_guid = $uuid;
                                $updated = true;
                            }
                            if ($updated) {
                                $existing->save();
                                $stats['updated']++;
                                $log('info', "Mis à jour: {$name}");
                            } else {
                                $stats['skipped']++;
                            }

                            // Lier au groupe de même nom si pas déjà fait
                            if ($groups->has(strtolower($name))) {
                                $group = $groups->get(strtolower($name));
                                if (!$existing->workstationGroups()->where('workstation_group_id', $group->id)->exists()) {
                                    $existing->workstationGroups()->attach($group->id);
                                    $stats['linked_groups']++;
                                }
                            }
                        } else {
                            $profile = AppProfile::create([
                                'name' => $name,
                                'display_name' => $description ?? $name,
                                'description' => $description,
                                'ad_guid' => $uuid,
                                'is_active' => true,
                            ]);

                            // Lier au groupe de même nom
                            if ($groups->has(strtolower($name))) {
                                $group = $groups->get(strtolower($name));
                                $profile->workstationGroups()->attach($group->id);
                                $stats['linked_groups']++;
                            }

                            $stats['created']++;
                            $log('success', "Créé: {$name}");
                        }
                    } catch (\Exception $e) {
                        $parcName = $parc->getParcName() ?? 'inconnu';
                        $stats['errors'][] = "Erreur pour {$parcName}: " . $e->getMessage();
                        $log('error', "Erreur pour {$parcName}: " . $e->getMessage());
                    }
                }

                DB::commit();

            } finally {
                AppProfileObserver::enableSync();
            }

            $log('info', "Résultat: {$stats['created']} créés, {$stats['updated']} mis à jour, {$stats['skipped']} ignorés, {$stats['linked_groups']} liés");

        } catch (\Exception $e) {
            DB::rollBack();
            $stats['errors'][] = 'Erreur globale: ' . $e->getMessage();
            $log('error', 'Erreur lors de l\'import: ' . $e->getMessage());
            Log::error('AppProfileService::importFromAd erreur', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $stats;
    }

    /**
     * Convertit un GUID binaire en chaîne formatée
     */
    private function convertGuidToString(string $binaryGuid): string
    {
        $hex = bin2hex($binaryGuid);
        if (strlen($hex) !== 32) {
            return $hex;
        }
        return sprintf(
            '%s%s%s%s-%s%s-%s%s-%s-%s',
            substr($hex, 6, 2), substr($hex, 4, 2), substr($hex, 2, 2), substr($hex, 0, 2),
            substr($hex, 10, 2), substr($hex, 8, 2),
            substr($hex, 14, 2), substr($hex, 12, 2),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
