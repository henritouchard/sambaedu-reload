<?php

declare(strict_types=1);

namespace App\Services\AppProfile;

use App\Config\LdapDnHelper;
use App\LdapModels\DeviceGroupTagModel;
use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\AppProfileObserver;
use App\Wpkg\Deployment\Events\AppProfileApplicationsChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationChanged;
use App\Wpkg\Deployment\Events\AppProfileWorkstationGroupChanged;
use App\Wpkg\Deployment\Events\WorkstationApplicationsChanged;
use App\Wpkg\Deployment\Events\WorkstationGroupApplicationsChanged;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     * Ajoute des applications à un profil.
     *
     * Story 15.4 / AC6.1 : dispatch d'un event pluriel `AppProfileApplicationsChanged`
     * APRÈS persistance (pattern post-commit via `DB::transaction`). Décision C
     * 2026-05-07 : 1 event pluriel plutôt que N events (cf. Dev Agent Record §
     * Decisions). Aucun event dispatché si `$applicationIds` est vide ou si le
     * profil n'existe pas — invariant AC6.3.
     */
    public function addApplications(int $profileId, array $applicationIds): bool
    {
        $applicationIds = $this->normalizeIds($applicationIds);
        if ($applicationIds === []) {
            return false;
        }

        $profile = AppProfile::find($profileId);
        if (! $profile) {
            return false;
        }

        DB::transaction(function () use ($profile, $profileId, $applicationIds) {
            $profile->applications()->syncWithoutDetaching($applicationIds);
            // Story 15.4 / Correction post-review #1 + #3 + #M3 : dispatch
            // post-commit pour garantir que les listeners (cache invalidation,
            // regen .ini) ne voient jamais un état non persisté en cas de
            // transaction parente (Command/Job/bulk).
            DB::afterCommit(fn () => event(
                new AppProfileApplicationsChanged($profileId, $applicationIds, 'attached')
            ));
        });

        Log::info('[AppProfileService] Applications ajoutées au profil', [
            'profile_id' => $profileId,
            'application_ids' => $applicationIds,
        ]);

        return true;
    }

    /**
     * Retire des applications d'un profil.
     */
    public function removeApplications(int $profileId, array $applicationIds): bool
    {
        $applicationIds = $this->normalizeIds($applicationIds);
        if ($applicationIds === []) {
            return false;
        }

        $profile = AppProfile::find($profileId);
        if (! $profile) {
            return false;
        }

        DB::transaction(function () use ($profile, $profileId, $applicationIds) {
            $profile->applications()->detach($applicationIds);
            DB::afterCommit(fn () => event(
                new AppProfileApplicationsChanged($profileId, $applicationIds, 'detached')
            ));
        });

        Log::info('[AppProfileService] Applications retirées du profil', [
            'profile_id' => $profileId,
            'application_ids' => $applicationIds,
        ]);

        return true;
    }

    /**
     * Ajoute des groupes de postes à un profil.
     *
     * Story 15.4 / AC6.1 : dispatch d'un event `AppProfileWorkstationGroupChanged`
     * par groupe (signature singulier 15.2 conservée — pas de cassure rétro-compat).
     */
    public function addWorkstationGroups(int $profileId, array $groupIds): bool
    {
        $groupIds = $this->normalizeIds($groupIds);
        if ($groupIds === []) {
            return false;
        }

        $profile = AppProfile::find($profileId);
        if (! $profile) {
            return false;
        }

        DB::transaction(function () use ($profile, $profileId, $groupIds) {
            $profile->workstationGroups()->syncWithoutDetaching($groupIds);
            DB::afterCommit(function () use ($profileId, $groupIds) {
                foreach ($groupIds as $groupId) {
                    event(new AppProfileWorkstationGroupChanged($profileId, $groupId, 'attached'));
                }
            });
        });

        Log::info('[AppProfileService] Groupes ajoutés au profil', [
            'profile_id' => $profileId,
            'group_ids' => $groupIds,
        ]);

        return true;
    }

    /**
     * Retire des groupes de postes d'un profil.
     */
    public function removeWorkstationGroups(int $profileId, array $groupIds): bool
    {
        $groupIds = $this->normalizeIds($groupIds);
        if ($groupIds === []) {
            return false;
        }

        $profile = AppProfile::find($profileId);
        if (! $profile) {
            return false;
        }

        DB::transaction(function () use ($profile, $profileId, $groupIds) {
            $profile->workstationGroups()->detach($groupIds);
            DB::afterCommit(function () use ($profileId, $groupIds) {
                foreach ($groupIds as $groupId) {
                    event(new AppProfileWorkstationGroupChanged($profileId, $groupId, 'detached'));
                }
            });
        });

        Log::info('[AppProfileService] Groupes retirés du profil', [
            'profile_id' => $profileId,
            'group_ids' => $groupIds,
        ]);

        return true;
    }

    /**
     * Ajoute des postes à un profil.
     */
    public function addWorkstations(int $profileId, array $workstationIds): bool
    {
        $workstationIds = $this->normalizeIds($workstationIds);
        if ($workstationIds === []) {
            return false;
        }

        $profile = AppProfile::find($profileId);
        if (! $profile) {
            return false;
        }

        DB::transaction(function () use ($profile, $profileId, $workstationIds) {
            $profile->workstations()->syncWithoutDetaching($workstationIds);
            DB::afterCommit(function () use ($profileId, $workstationIds) {
                foreach ($workstationIds as $workstationId) {
                    event(new AppProfileWorkstationChanged($profileId, $workstationId, 'attached'));
                }
            });
        });

        Log::info('[AppProfileService] Postes ajoutés au profil', [
            'profile_id' => $profileId,
            'workstation_ids' => $workstationIds,
        ]);

        return true;
    }

    /**
     * Retire des postes d'un profil.
     */
    public function removeWorkstations(int $profileId, array $workstationIds): bool
    {
        $workstationIds = $this->normalizeIds($workstationIds);
        if ($workstationIds === []) {
            return false;
        }

        $profile = AppProfile::find($profileId);
        if (! $profile) {
            return false;
        }

        DB::transaction(function () use ($profile, $profileId, $workstationIds) {
            $profile->workstations()->detach($workstationIds);
            DB::afterCommit(function () use ($profileId, $workstationIds) {
                foreach ($workstationIds as $workstationId) {
                    event(new AppProfileWorkstationChanged($profileId, $workstationId, 'detached'));
                }
            });
        });

        Log::info('[AppProfileService] Postes retirés du profil', [
            'profile_id' => $profileId,
            'workstation_ids' => $workstationIds,
        ]);

        return true;
    }

    // ============================================================
    // STORY 15.4 — Mutations directes parc/poste (pas via AppProfile).
    // ============================================================

    /**
     * Story 15.4 / AC1.4 — Ajoute des applications directement à un parc
     * (pivot `application_workstation_group`).
     *
     * @return list<int>  IDs effectivement ajoutés (différence syncWithoutDetaching).
     */
    public function addApplicationsToWorkstationGroup(int $groupId, array $applicationIds): array
    {
        $applicationIds = $this->normalizeIds($applicationIds);
        if ($applicationIds === []) {
            return [];
        }

        $group = WorkstationGroup::find($groupId);
        if (! $group) {
            return [];
        }

        $attached = DB::transaction(function () use ($group, $groupId, $applicationIds) {
            $changes = $group->applications()->syncWithoutDetaching($applicationIds);
            $attached = array_values(array_map('intval', $changes['attached'] ?? []));

            if ($attached !== []) {
                DB::afterCommit(fn () => event(
                    new WorkstationGroupApplicationsChanged($groupId, $attached, 'attached')
                ));
            }

            return $attached;
        });

        Log::channel('wpkg-deploy')->info('[AppProfileService] Applications attachées au parc', [
            'workstation_group_id' => $groupId,
            'application_ids' => $applicationIds,
            'attached' => $attached,
        ]);

        return $attached;
    }

    /**
     * Story 15.4 / AC1.4 — Retire des applications directement d'un parc.
     *
     * @return int  Nombre de lignes pivot supprimées.
     */
    public function removeApplicationsFromWorkstationGroup(int $groupId, array $applicationIds): int
    {
        $applicationIds = $this->normalizeIds($applicationIds);
        if ($applicationIds === []) {
            return 0;
        }

        $group = WorkstationGroup::find($groupId);
        if (! $group) {
            return 0;
        }

        $detached = DB::transaction(function () use ($group, $groupId, $applicationIds) {
            $detached = $group->applications()->detach($applicationIds);
            if ($detached > 0) {
                DB::afterCommit(fn () => event(
                    new WorkstationGroupApplicationsChanged($groupId, $applicationIds, 'detached')
                ));
            }
            return $detached;
        });

        Log::channel('wpkg-deploy')->info('[AppProfileService] Applications détachées du parc', [
            'workstation_group_id' => $groupId,
            'application_ids' => $applicationIds,
            'detached_count' => $detached,
        ]);

        return (int) $detached;
    }

    /**
     * Story 15.4 / AC2.4 — Ajoute des applications directement à un poste
     * (pivot `application_workstation`).
     *
     * @return list<int>
     */
    public function addApplicationsToWorkstation(int $workstationId, array $applicationIds): array
    {
        $applicationIds = $this->normalizeIds($applicationIds);
        if ($applicationIds === []) {
            return [];
        }

        $workstation = Workstation::find($workstationId);
        if (! $workstation) {
            return [];
        }

        $attached = DB::transaction(function () use ($workstation, $workstationId, $applicationIds) {
            $changes = $workstation->applications()->syncWithoutDetaching($applicationIds);
            $attached = array_values(array_map('intval', $changes['attached'] ?? []));

            if ($attached !== []) {
                DB::afterCommit(fn () => event(
                    new WorkstationApplicationsChanged($workstationId, $attached, 'attached')
                ));
            }

            return $attached;
        });

        Log::channel('wpkg-deploy')->info('[AppProfileService] Applications attachées au poste', [
            'workstation_id' => $workstationId,
            'application_ids' => $applicationIds,
            'attached' => $attached,
        ]);

        return $attached;
    }

    /**
     * Story 15.4 / AC2.4 — Retire des applications directement d'un poste.
     */
    public function removeApplicationsFromWorkstation(int $workstationId, array $applicationIds): int
    {
        $applicationIds = $this->normalizeIds($applicationIds);
        if ($applicationIds === []) {
            return 0;
        }

        $workstation = Workstation::find($workstationId);
        if (! $workstation) {
            return 0;
        }

        $detached = DB::transaction(function () use ($workstation, $workstationId, $applicationIds) {
            $detached = $workstation->applications()->detach($applicationIds);
            if ($detached > 0) {
                DB::afterCommit(fn () => event(
                    new WorkstationApplicationsChanged($workstationId, $applicationIds, 'detached')
                ));
            }
            return $detached;
        });

        Log::channel('wpkg-deploy')->info('[AppProfileService] Applications détachées du poste', [
            'workstation_id' => $workstationId,
            'application_ids' => $applicationIds,
            'detached_count' => $detached,
        ]);

        return (int) $detached;
    }

    /**
     * Story 15.4 / AC4 — Clone synchrone de la configuration WPKG d'un parc source
     * vers un parc cible. Calcule le diff (added/removed) sur les profils ET sur
     * les applications directes, applique en transaction DB, dispatche les events
     * ciblés et insère une ligne `wpkg_deployments` avec UUID partagé pour les
     * logs `wpkg-deploy`.
     *
     * @note Race condition preview/execute (Correction post-review #6) : le diff
     *       calculé par previewCloneConfiguration() au moment T peut diverger
     *       du diff réellement appliqué par cloneConfiguration() au moment T+N
     *       si un autre admin modifie source ou cible entre-temps. La méthode
     *       recalcule systématiquement le diff depuis la BDD à l'execute —
     *       l'état final reflète la BDD au moment de l'execute, pas au moment
     *       du preview. Le toast de résultat affiche le delta réel (peut
     *       différer du preview). Mitigation actuellement non implémentée :
     *       hash de la configuration source au preview comparé à l'execute
     *       permettrait d'alerter l'utilisateur. Hors scope MVP — voir
     *       review 15.4 problème #6.
     *
     * @return array{
     *     deployment_id: string,
     *     profiles: array{added: list<int>, removed: list<int>},
     *     applications: array{added: list<int>, removed: list<int>}
     * }
     */
    public function cloneConfiguration(int $sourceGroupId, int $targetGroupId): array
    {
        if ($sourceGroupId === $targetGroupId) {
            throw new \InvalidArgumentException('Source et cible identiques.');
        }

        $deploymentId = (string) Str::uuid();

        $source = WorkstationGroup::with(['appProfiles:id', 'applications:id'])
            ->find($sourceGroupId);
        $target = WorkstationGroup::with(['appProfiles:id', 'applications:id'])
            ->find($targetGroupId);

        if (! $source || ! $target) {
            throw new \RuntimeException('Parc source ou cible introuvable.');
        }

        $sourceProfileIds = $source->appProfiles->pluck('id')->map('intval')->all();
        $targetProfileIds = $target->appProfiles->pluck('id')->map('intval')->all();
        $sourceAppIds = $source->applications->pluck('id')->map('intval')->all();
        $targetAppIds = $target->applications->pluck('id')->map('intval')->all();

        $profilesAdded = array_values(array_diff($sourceProfileIds, $targetProfileIds));
        $profilesRemoved = array_values(array_diff($targetProfileIds, $sourceProfileIds));
        $appsAdded = array_values(array_diff($sourceAppIds, $targetAppIds));
        $appsRemoved = array_values(array_diff($targetAppIds, $sourceAppIds));

        Log::channel('wpkg-deploy')->info('[AppProfileService] Clone configuration parc — début', [
            'deployment_id' => $deploymentId,
            'source_group_id' => $sourceGroupId,
            'target_group_id' => $targetGroupId,
            'profiles_added' => $profilesAdded,
            'profiles_removed' => $profilesRemoved,
            'apps_added' => $appsAdded,
            'apps_removed' => $appsRemoved,
        ]);

        DB::transaction(function () use (
            $target, $sourceProfileIds, $sourceAppIds, $deploymentId,
            $sourceGroupId, $targetGroupId, $profilesAdded, $profilesRemoved,
            $appsAdded, $appsRemoved,
        ) {
            $target->appProfiles()->sync($sourceProfileIds);
            $target->applications()->sync($sourceAppIds);

            DB::table('wpkg_deployments')->insert([
                'id' => $deploymentId,
                'triggered_by' => Auth::id(),
                'triggered_at' => now(),
                'target_scope' => json_encode([
                    'workstation_group_ids' => [$targetGroupId],
                ]),
                'status' => 'completed',
                'summary' => json_encode([
                    'source_group_id' => $sourceGroupId,
                    'profiles' => ['added' => $profilesAdded, 'removed' => $profilesRemoved],
                    'applications' => ['added' => $appsAdded, 'removed' => $appsRemoved],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Story 15.4 / Correction post-review #1 + #M3 : dispatch events
            // ciblés post-commit + log audit corrélé deployment_id. Garantit
            // qu'aucun event/log n'est émis si la transaction rollback (ligne
            // wpkg_deployments inexistante = pas de bruit dans les logs).
            DB::afterCommit(function () use (
                $deploymentId, $sourceGroupId, $targetGroupId,
                $profilesAdded, $profilesRemoved, $appsAdded, $appsRemoved,
            ) {
                foreach ($profilesAdded as $pid) {
                    event(new AppProfileWorkstationGroupChanged($pid, $targetGroupId, 'attached'));
                }
                foreach ($profilesRemoved as $pid) {
                    event(new AppProfileWorkstationGroupChanged($pid, $targetGroupId, 'detached'));
                }
                if ($appsAdded !== []) {
                    event(new WorkstationGroupApplicationsChanged($targetGroupId, $appsAdded, 'attached'));
                }
                if ($appsRemoved !== []) {
                    event(new WorkstationGroupApplicationsChanged($targetGroupId, $appsRemoved, 'detached'));
                }

                Log::channel('wpkg-deploy')->info('[AppProfileService] Clone configuration parc — terminé', [
                    'deployment_id' => $deploymentId,
                    'source_group_id' => $sourceGroupId,
                    'target_group_id' => $targetGroupId,
                ]);
            });
        });

        return [
            'deployment_id' => $deploymentId,
            'profiles' => ['added' => $profilesAdded, 'removed' => $profilesRemoved],
            'applications' => ['added' => $appsAdded, 'removed' => $appsRemoved],
        ];
    }

    /**
     * Calcule le diff que produirait un clone, sans persister.
     *
     * @return array{
     *     profiles: array{added: list<int>, removed: list<int>},
     *     applications: array{added: list<int>, removed: list<int>}
     * }
     */
    public function previewCloneConfiguration(int $sourceGroupId, int $targetGroupId): array
    {
        $source = WorkstationGroup::with(['appProfiles:id', 'applications:id'])
            ->find($sourceGroupId);
        $target = WorkstationGroup::with(['appProfiles:id', 'applications:id'])
            ->find($targetGroupId);

        if (! $source || ! $target) {
            return [
                'profiles' => ['added' => [], 'removed' => []],
                'applications' => ['added' => [], 'removed' => []],
            ];
        }

        $sP = $source->appProfiles->pluck('id')->map('intval')->all();
        $tP = $target->appProfiles->pluck('id')->map('intval')->all();
        $sA = $source->applications->pluck('id')->map('intval')->all();
        $tA = $target->applications->pluck('id')->map('intval')->all();

        return [
            'profiles' => [
                'added' => array_values(array_diff($sP, $tP)),
                'removed' => array_values(array_diff($tP, $sP)),
            ],
            'applications' => [
                'added' => array_values(array_diff($sA, $tA)),
                'removed' => array_values(array_diff($tA, $sA)),
            ],
        ];
    }

    /**
     * Normalise un array d'IDs : cast int, filtre > 0, dédoublonne.
     *
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $clean[$i] = $i;
            }
        }

        return array_values($clean);
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
            ->with('depot')
            ->withCount([
                'workstationStatuses as deployed_total_count' => fn ($q) => $q->whereIn('status', ['installed', 'error', 'not-installed']),
                'workstationStatuses as deployed_installed_count' => fn ($q) => $q->where('status', 'installed'),
                'workstationStatuses as deployed_error_count' => fn ($q) => $q->whereIn('status', ['error', 'not-installed']),
            ]);

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
