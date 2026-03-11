<?php

namespace App\Repositories;

use App\Config\LdapDnHelper;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use LdapRecord\Models\ActiveDirectory\OrganizationalUnit;
use LdapRecord\Models\ActiveDirectory\Group;

/**
 * Repository pour la gestion des groupes de postes de travail (WorkstationGroups)
 * 
 * Fournit une interface unifiée pour accéder aux données des salles/groupes.
 * Gère les données SQL (PostgreSQL) et AD (via LdapRecord).
 */
class WorkstationGroupRepository
{
    public function __construct(
        private LdapDnHelper $dnHelper
    ) {
    }

    // ========================================
    // LECTURE AD - SALLES (OU)
    // ========================================

    /**
     * Récupère toutes les salles (OU) depuis l'AD
     */
    public function getAllFromAd(): array
    {
        $computersDn = $this->dnHelper->computers();
        $salles = [];

        try {
            $ous = OrganizationalUnit::in($computersDn)
                ->recursive()
                ->get();

            foreach ($ous as $ou) {
                $name = $ou->getFirstAttribute('ou');

                if (empty($name)) {
                    continue;
                }

                $parentDn = $ou->getParentDn();
                $parentName = null;
                if ($parentDn && strcasecmp($parentDn, $computersDn) !== 0) {
                    if (preg_match('/^OU=([^,]+),/i', $parentDn, $matches)) {
                        $parentName = $matches[1];
                    }
                }

                $salles[] = [
                    'name' => $name,
                    'dn' => $ou->getDn(),
                    'guid' => $ou->getConvertedGuid(),
                    'description' => $ou->getFirstAttribute('description'),
                    'parent' => $parentName,
                ];
            }

            Log::debug('[WorkstationGroupRepository] Salles récupérées depuis AD', [
                'count' => count($salles)
            ]);

            return $salles;

        } catch (\Exception $e) {
            Log::error('[WorkstationGroupRepository] Erreur récupération salles AD', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Récupère les noms des parcs (groupes dans ou=Parcs) depuis l'AD
     * 
     * Ces groupes correspondent aux salles physiques.
     * Si un groupe dans ou=Computers a un parc correspondant, c'est une salle physique.
     * 
     * @return array<string> Liste des noms de parcs (en minuscules pour comparaison)
     */
    public function getParcNamesFromAd(): array
    {
        $parcsDn = $this->dnHelper->parcs();
        $parcNames = [];

        try {
            $groups = Group::in($parcsDn)
                ->recursive()
                ->get();

            foreach ($groups as $group) {
                $name = $group->getFirstAttribute('cn');
                if (!empty($name)) {
                    // Normaliser en minuscules pour comparaison insensible à la casse
                    $parcNames[] = strtolower($name);
                }
            }

            Log::debug('[WorkstationGroupRepository] Parcs récupérés depuis AD', [
                'count' => count($parcNames),
                'parcsDn' => $parcsDn
            ]);

            return $parcNames;

        } catch (\Exception $e) {
            Log::error('[WorkstationGroupRepository] Erreur récupération parcs AD', [
                'error' => $e->getMessage(),
                'parcsDn' => $parcsDn
            ]);
            return [];
        }
    }

    // ========================================
    // MACHINES
    // ========================================

    /**
     * Récupère toutes les machines avec pagination
     */
    public function getMachines(
        int $perPage = 20,
        ?string $search = null,
        ?string $os = null,
        ?int $groupId = null
    ): LengthAwarePaginator {
        $query = Workstation::query();

        if ($search) {
            $query->search($search);
        }

        if ($os) {
            $query->where('os', $os);
        }

        if ($groupId) {
            $query->whereHas('groups', function (Builder $q) use ($groupId) {
                $q->where('workstation_groups.id', $groupId);
            });
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * Récupère une machine par son ID
     */
    public function findMachine(int $id): ?Workstation
    {
        return Workstation::find($id);
    }

    /**
     * Récupère une machine par son nom
     */
    public function findMachineByName(string $name): ?Workstation
    {
        return Workstation::where('name', strtolower($name))->first();
    }

    /**
     * Récupère une machine par son UUID AD
     */
    public function findMachineByUuid(string $uuid): ?Workstation
    {
        return Workstation::where('ad_guid', $uuid)->first();
    }

    /**
     * Récupère les machines d'un groupe
     */
    public function getMachinesByGroup(int $groupId): Collection
    {
        $group = WorkstationGroup::find($groupId);

        if (!$group) {
            return collect();
        }

        return $group->workstations()->orderBy('name')->get();
    }

    /**
     * Récupère plusieurs machines par leurs IDs
     *
     * @param array<int> $machineIds
     */
    public function findMachinesByIds(array $machineIds): Collection
    {
        if (empty($machineIds)) {
            return collect();
        }

        return Workstation::whereIn('id', $machineIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * Récupère plusieurs machines par leurs IDs, restreintes à un groupe donné
     *
     * @param array<int> $machineIds
     */
    public function findGroupMachinesByIds(int $groupId, array $machineIds): Collection
    {
        if (empty($machineIds)) {
            return collect();
        }

        $group = WorkstationGroup::find($groupId);

        if (!$group) {
            return collect();
        }

        return $group->workstations()
            ->whereIn('workstations.id', $machineIds)
            ->orderBy('workstations.name')
            ->get();
    }

    /**
     * Récupère les machines sans groupe
     */
    public function getMachinesWithoutGroup(): Collection
    {
        return Workstation::whereDoesntHave('groups')
            ->whereNull('physical_room_id')
            ->orderBy('name')
            ->get();
    }

    /**
     * Récupère les OS distincts des machines
     */
    public function getDistinctOs(): Collection
    {
        return Workstation::select('os')
            ->distinct()
            ->whereNotNull('os')
            ->orderBy('os')
            ->pluck('os');
    }

    /**
     * Compte le nombre total de machines
     */
    public function countMachines(): int
    {
        return Workstation::count();
    }

    // ========================================
    // GROUPES DE POSTES
    // ========================================

    /**
     * Récupère tous les groupes avec pagination
     */
    public function getGroups(
        int $perPage = 20,
        ?string $search = null,
        ?int $parentId = null,
        ?bool $isPhysical = null
    ): LengthAwarePaginator {
        $query = WorkstationGroup::query();

        if ($search) {
            $query->search($search);
        }

        if ($parentId !== null) {
            $query->where('parent_id', $parentId);
        }

        if ($isPhysical !== null) {
            $query->where('is_physical', $isPhysical);
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * Récupère tous les groupes sous forme d'arborescence
     */
    public function getGroupsTree(): Collection
    {
        return WorkstationGroup::root()
            ->with('descendants')
            ->orderBy('name')
            ->get();
    }

    /**
     * Récupère un groupe par son ID
     */
    public function findGroup(int $id): ?WorkstationGroup
    {
        return WorkstationGroup::with(['parent', 'children', 'workstations'])->find($id);
    }

    /**
     * Récupère un groupe par son nom
     */
    public function findGroupByName(string $name): ?WorkstationGroup
    {
        return WorkstationGroup::where('name', $name)->first();
    }

    /**
     * Crée un nouveau groupe
     */
    public function createGroup(array $data): WorkstationGroup
    {
        return WorkstationGroup::create($data);
    }

    /**
     * Met à jour un groupe
     */
    public function updateGroup(WorkstationGroup $group, array $data): bool
    {
        return $group->update($data);
    }

    /**
     * Supprime un groupe
     */
    public function deleteGroup(WorkstationGroup $group): bool
    {
        $group->workstations()->detach();
        $group->children()->update(['parent_id' => $group->parent_id]);
        return $group->delete();
    }

    /**
     * Récupère les groupes racine (sans parent)
     */
    public function getRootGroups(): Collection
    {
        return WorkstationGroup::root()->orderBy('name')->get();
    }

    /**
     * Récupère les groupes synchronisés avec AD
     */
    public function getGroupsSyncedWithAd(): Collection
    {
        return WorkstationGroup::syncedWithAd()->orderBy('name')->get();
    }

    /**
     * Compte le nombre de groupes
     */
    public function countGroups(): int
    {
        return WorkstationGroup::count();
    }

    // ========================================
    // RELATIONS POSTES <-> GROUPES
    // ========================================

    /**
     * Ajoute un poste à un groupe
     */
    public function addMachineToGroup(int $machineId, int $groupId): void
    {
        $group = WorkstationGroup::findOrFail($groupId);
        $group->workstations()->syncWithoutDetaching([$machineId]);
    }

    /**
     * Retire une machine d'un groupe
     */
    public function removeMachineFromGroup(int $machineId, int $groupId): void
    {
        $group = WorkstationGroup::findOrFail($groupId);
        $group->workstations()->detach($machineId);
    }

    /**
     * Définit les groupes d'une machine
     */
    public function setMachineGroups(int $machineId, array $groupIds): void
    {
        $workstation = Workstation::findOrFail($machineId);
        $workstation->groups()->sync($groupIds);
    }

    /**
     * Définit les machines d'un groupe
     */
    public function setGroupMachines(int $groupId, array $machineIds): void
    {
        $group = WorkstationGroup::findOrFail($groupId);
        $group->workstations()->sync($machineIds);
    }
}
