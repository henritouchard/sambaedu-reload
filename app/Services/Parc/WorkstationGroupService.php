<?php

namespace App\Services\Parc;

use App\Config\LdapDnHelper;
use App\LdapModels\DeviceGroupModel;
use App\LdapModels\DeviceGroupTagModel;
use App\Services\WorkstationService;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Repositories\WorkstationGroupRepository;
use App\Services\Parc\RemoteAccessService;
use App\Enums\LockReason;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service pour la gestion des groupes de postes de travail (WorkstationGroups)
 * 
 * Fournit la logique métier pour la gestion des postes et groupes de postes.
 * Utilise le WorkstationGroupRepository pour l'accès aux données.
 */
class WorkstationGroupService
{
    /** @var array<int, string> */
    private const SUPPORTED_MACHINE_ACTIONS = ['wake', 'shutdown', 'shutdown-force', 'restart', 'remote'];

    /** @var array<string, string> */
    private const MACHINE_ACTION_LABELS = [
        'wake' => 'allumage',
        'shutdown' => 'extinction',
        'shutdown-force' => 'extinction forcée',
        'restart' => 'redémarrage',
        'remote' => 'accès distant',
    ];

    public function __construct(
        private WorkstationGroupRepository $repository,
        private WorkstationService $workstationService,
        private RemoteAccessService $remoteAccessService,
    ) {
    }

    // ========================================
    // MACHINES
    // ========================================

    /**
     * Liste les machines avec filtres et pagination
     */
    public function listMachines(
        int $perPage = 20,
        ?string $search = null,
        ?string $os = null,
        ?int $groupId = null
    ): LengthAwarePaginator {
        return $this->repository->getMachines($perPage, $search, $os, $groupId);
    }

    /**
     * Récupère une machine par son ID
     */
    public function getWorkstation(int $id): ?Workstation
    {
        return $this->repository->findMachine($id);
    }

    /**
     * Récupère une machine par son nom
     */
    public function getWorkstationByName(string $name): ?Workstation
    {
        return $this->repository->findMachineByName($name);
    }

    /**
     * Récupère les statistiques des machines
     */
    public function getMachineStats(): array
    {
        $total = $this->repository->countMachines();
        $withoutGroup = $this->repository->getMachinesWithoutGroup()->count();
        $osList = $this->repository->getDistinctOs();

        $osCounts = [];
        foreach ($osList as $os) {
            $osCounts[$os] = Workstation::where('os', $os)->count();
        }

        return [
            'total' => $total,
            'without_group' => $withoutGroup,
            'by_os' => $osCounts,
        ];
    }

    /**
     * Récupère les OS disponibles
     */
    public function getAvailableOs(): Collection
    {
        return $this->repository->getDistinctOs();
    }

    /**
     * Retourne les actions machines disponibles côté interface
     *
     * @return array<int, array{key: string, label: string, icon: string, requires_confirmation: bool}>
     */
    public function getAvailableMachineActions(): array
    {
        return [
            [
                'key' => 'wake',
                'label' => 'Allumer',
                'icon' => 'fa-solid fa-power-off',
                'requires_confirmation' => false,
            ],
            [
                'key' => 'shutdown',
                'label' => 'Éteindre',
                'icon' => 'fa-solid fa-stop',
                'requires_confirmation' => true,
            ],
            [
                'key' => 'shutdown-force',
                'label' => 'Forcer l\'extinction',
                'icon' => 'fa-solid fa-triangle-exclamation',
                'requires_confirmation' => true,
            ],
            [
                'key' => 'restart',
                'label' => 'Redémarrer',
                'icon' => 'fa-solid fa-rotate-right',
                'requires_confirmation' => true,
            ],
            [
                'key' => 'remote',
                'label' => 'Accès distant',
                'icon' => 'fa-solid fa-desktop',
                'requires_confirmation' => false,
            ],
        ];
    }

    /**
     * Libellé lisible d'une action machine
     */
    public function getMachineActionLabel(string $action): string
    {
        return self::MACHINE_ACTION_LABELS[$action] ?? $action;
    }

    /**
     * Exécute une action de puissance sur une sélection de machines
     *
     * @param array<int|string> $machineIds
     * @return array{action: string, requested_count: int, success_count: int, failed_count: int, results: array<int, array{machine: string, success: bool, code: int}>}
     */
    public function executeMachinesAction(array $machineIds, string $action): array
    {
        $machines = $this->repository->findMachinesByIds($this->normalizeMachineIds($machineIds));

        return $this->executeMachineActionOnCollection($machines, $action);
    }

    /**
     * Exécute une action de puissance sur une machine précise
     *
     * @return array{action: string, requested_count: int, success_count: int, failed_count: int, results: array<int, array{machine: string, success: bool, code: int}>}
     */
    public function executeMachineAction(int $machineId, string $action): array
    {
        $machines = $this->repository->findMachinesByIds([$machineId]);

        return $this->executeMachineActionOnCollection($machines, $action);
    }

    /**
     * Exécute une action de puissance sur des machines appartenant à un groupe
     *
     * @param array<int|string> $machineIds
     * @return array{action: string, requested_count: int, success_count: int, failed_count: int, results: array<int, array{machine: string, success: bool, code: int}>}
     */
    public function executeGroupMachinesAction(int $groupId, array $machineIds, string $action): array
    {
        $machines = $this->repository->findGroupMachinesByIds($groupId, $this->normalizeMachineIds($machineIds));

        return $this->executeMachineActionOnCollection($machines, $action);
    }

    // ========================================
    // GROUPES DE MACHINES
    // ========================================

    /**
     * Liste les groupes avec filtres et pagination
     */
    public function listGroups(
        int $perPage = 20,
        ?string $search = null,
        ?int $parentId = null,
        ?bool $isPhysical = null
    ): LengthAwarePaginator {
        return $this->repository->getGroups($perPage, $search, $parentId, $isPhysical);
    }

    /**
     * Récupère l'arborescence complète des groupes
     */
    public function getGroupsTree(): Collection
    {
        return $this->repository->getGroupsTree();
    }

    /**
     * Récupère un groupe par son ID
     */
    public function getGroup(int $id): ?WorkstationGroup
    {
        return $this->repository->findGroup($id);
    }

    /**
     * Crée un nouveau groupe
     * 
     * Note: La création automatique de l'AppProfile (si app_profile_name est rempli)
     * est gérée par le WorkstationGroupObserver.
     */
    public function createGroup(array $data): WorkstationGroup
    {
        $this->validateGroupData($data);

        return DB::transaction(function () use ($data) {
            $group = $this->repository->createGroup($data);

            Log::info('Groupe de machines créé', [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'app_profile_name' => $group->app_profile_name,
            ]);

            return $group;
        });
    }

    /**
     * Met à jour un groupe
     * 
     * Note: Le renommage automatique de l'AppProfile associé
     * est géré par le WorkstationGroupObserver.
     * 
     * @throws \RuntimeException Si le groupe est verrouillé
     */
    public function updateGroup(int $id, array $data): WorkstationGroup
    {
        $group = $this->repository->findGroup($id);

        if (!$group) {
            throw new \InvalidArgumentException("Groupe non trouvé: {$id}");
        }

        if ($group->isLocked()) {
            throw new \RuntimeException("Groupe verrouillé: {$group->locked}");
        }

        $this->validateGroupData($data, $group);

        DB::transaction(function () use ($group, $data) {
            $this->repository->updateGroup($group, $data);

            Log::info('Groupe de machines mis à jour', [
                'group_id' => $group->id,
                'name' => $group->name,
            ]);
        });

        return $group->fresh();
    }

    /**
     * Supprime un groupe
     * 
     * Note: La suppression automatique de l'AppProfile associé
     * est gérée par le WorkstationGroupObserver.
     * 
     * @throws \RuntimeException Si le groupe est verrouillé
     */
    public function deleteGroup(int $id): bool
    {
        $group = $this->repository->findGroup($id);

        if (!$group) {
            throw new \InvalidArgumentException("Groupe non trouvé: {$id}");
        }

        if ($group->isLocked()) {
            throw new \RuntimeException("Groupe verrouillé: {$group->locked}");
        }

        return DB::transaction(function () use ($group) {
            $name = $group->name;

            $result = $this->repository->deleteGroup($group);

            Log::info('Groupe de machines supprimé', [
                'group_id' => $group->id,
                'name' => $name,
            ]);

            return $result;
        });
    }

    /**
     * Récupère les groupes synchronisés avec AD
     */
    public function getGroupsSyncedWithAd(): Collection
    {
        return WorkstationGroup::syncedWithAd()->get();
    }

    /**
     * Récupère les groupes racine pour les sélecteurs
     */
    public function getRootGroupsForSelect(): Collection
    {
        return $this->repository->getRootGroups();
    }

    /**
     * Récupère les statistiques des groupes
     */
    public function getGroupStats(): array
    {
        $total = $this->repository->countGroups();
        $synced = WorkstationGroup::syncedWithAd()->count();
        $physicalRooms = WorkstationGroup::physical()->count();
        $logicalGroups = WorkstationGroup::logical()->count();

        return [
            'total' => $total,
            'physical_rooms' => $physicalRooms,
            'logical_groups' => $logicalGroups,
            'synced_with_ad' => $synced,
            'not_synced' => $total - $synced,
        ];
    }

    /**
     * @param array<int|string> $machineIds
     * @return array<int>
     */
    private function normalizeMachineIds(array $machineIds): array
    {
        $normalizedIds = array_map(
            static fn (mixed $id): int => (int) $id,
            $machineIds
        );

        $normalizedIds = array_filter(
            $normalizedIds,
            static fn (int $id): bool => $id > 0
        );

        return array_values(array_unique($normalizedIds));
    }

    /**
     * @return array{action: string, requested_count: int, success_count: int, failed_count: int, results: array<int, array{machine: string, success: bool, code: int}>}
     */
    private function executeMachineActionOnCollection(Collection $machines, string $action): array
    {
        if (!in_array($action, self::SUPPORTED_MACHINE_ACTIONS, true)) {
            throw new \InvalidArgumentException("Action machine non supportée: {$action}");
        }

        // Gestion spéciale pour l'accès distant
        if ($action === 'remote') {
            return $this->executeRemoteAccessAction($machines);
        }

        $machineNames = $machines
            ->pluck('name')
            ->filter(static fn (mixed $name): bool => is_string($name) && $name !== '')
            ->values()
            ->all();

        if (empty($machineNames)) {
            return [
                'action' => $action,
                'requested_count' => 0,
                'success_count' => 0,
                'failed_count' => 0,
                'results' => [],
            ];
        }

        return $this->workstationService->executePowerAction($machineNames, $action);
    }

    /**
     * Exécute l'action d'accès distant sur une collection de machines
     * 
     * @param Collection $machines
     * @return array{action: string, requested_count: int, success_count: int, failed_count: int, results: array<int, array{machine: string, success: bool, code: int, url?: string}>}
     */
    private function executeRemoteAccessAction(Collection $machines): array
    {
        if (!$this->remoteAccessService->hasRemoteAccessRights()) {
            throw new \InvalidArgumentException('Droits insuffisants pour l\'accès distant');
        }

        $results = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($machines as $machine) {
            try {
                $connectionType = RemoteAccessService::DEFAULT_CONNECTION_TYPE;
                $remoteUrl = $this->remoteAccessService->generateRemoteToken($machine->name, $connectionType);

                if ($remoteUrl) {
                    $results[] = [
                        'machine' => $machine->name,
                        'success' => true,
                        'code' => 200,
                        'url' => $remoteUrl,
                    ];
                    $successCount++;
                } else {
                    $results[] = [
                        'machine' => $machine->name,
                        'success' => false,
                        'code' => 500,
                    ];
                    $failedCount++;
                }
            } catch (\Exception $e) {
                Log::error('[WorkstationGroupService] Erreur accès distant machine: ' . $e->getMessage(), [
                    'machine' => $machine->name,
                ]);
                $results[] = [
                    'machine' => $machine->name,
                    'success' => false,
                    'code' => 500,
                ];
                $failedCount++;
            }
        }

        return [
            'action' => 'remote',
            'requested_count' => $machines->count(),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }

    // ========================================
    // GESTION DES RELATIONS
    // ========================================

    /**
     * Ajoute une machine à un groupe
     */
    public function addMachineToGroup(int $machineId, int $groupId): void
    {
        $this->repository->addMachineToGroup($machineId, $groupId);

        Log::info('Machine ajoutée au groupe', [
            'machine_id' => $machineId,
            'group_id' => $groupId,
        ]);
    }

    /**
     * Retire une machine d'un groupe
     */
    public function removeMachineFromGroup(int $machineId, int $groupId): void
    {
        $this->repository->removeMachineFromGroup($machineId, $groupId);

        Log::info('Machine retirée du groupe', [
            'machine_id' => $machineId,
            'group_id' => $groupId,
        ]);
    }

    /**
     * Définit les groupes d'une machine
     */
    public function setMachineGroups(int $machineId, array $groupIds): void
    {
        $this->repository->setMachineGroups($machineId, $groupIds);

        Log::info('Groupes de la machine mis à jour', [
            'machine_id' => $machineId,
            'group_ids' => $groupIds,
        ]);
    }

    /**
     * Définit les machines d'un groupe
     */
    public function setGroupMachines(int $groupId, array $machineIds): void
    {
        $this->repository->setGroupMachines($groupId, $machineIds);

        Log::info('Machines du groupe mises à jour', [
            'group_id' => $groupId,
            'machine_count' => count($machineIds),
        ]);
    }

    /**
     * Déplace plusieurs machines vers un groupe
     */
    public function bulkAddMachinesToGroup(array $machineIds, int $groupId): int
    {
        $count = 0;

        DB::transaction(function () use ($machineIds, $groupId, &$count) {
            foreach ($machineIds as $machineId) {
                try {
                    $this->repository->addMachineToGroup($machineId, $groupId);
                    $count++;
                } catch (\Exception $e) {
                    Log::warning('Erreur lors de l\'ajout de la machine au groupe', [
                        'machine_id' => $machineId,
                        'group_id' => $groupId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('Machines ajoutées en masse au groupe', [
            'group_id' => $groupId,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Retire plusieurs machines d'un groupe
     */
    public function bulkRemoveMachinesFromGroup(array $machineIds, int $groupId): int
    {
        $count = 0;

        DB::transaction(function () use ($machineIds, $groupId, &$count) {
            foreach ($machineIds as $machineId) {
                try {
                    $this->repository->removeMachineFromGroup($machineId, $groupId);
                    $count++;
                } catch (\Exception $e) {
                    Log::warning('Erreur lors du retrait de la machine du groupe', [
                        'machine_id' => $machineId,
                        'group_id' => $groupId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('Machines retirées en masse du groupe', [
            'group_id' => $groupId,
            'count' => $count,
        ]);

        return $count;
    }

    // ========================================
    // GESTION DES SALLES PHYSIQUES
    // ========================================

    /**
     * Récupère les salles physiques disponibles
     */
    public function getPhysicalRooms(): Collection
    {
        return WorkstationGroup::physical()->active()->orderBy('name')->get();
    }

    /**
     * Assigne une machine à une salle physique
     */
    public function assignMachineToPhysicalRoom(int $machineId, ?int $roomId): bool
    {
        $machine = $this->repository->findMachine($machineId);
        if (!$machine) {
            throw new \InvalidArgumentException("Machine non trouvée: {$machineId}");
        }

        if ($roomId !== null) {
            $room = $this->repository->findGroup($roomId);
            if (!$room) {
                throw new \InvalidArgumentException("Salle non trouvée: {$roomId}");
            }
            if (!$room->is_physical) {
                throw new \InvalidArgumentException("Le groupe '{$room->name}' n'est pas une salle physique");
            }
        }

        $oldRoomId = $machine->physical_room_id;
        $machine->physical_room_id = $roomId;
        $result = $machine->save();

        Log::info('Salle physique de la machine mise à jour', [
            'machine_id' => $machineId,
            'old_room_id' => $oldRoomId,
            'new_room_id' => $roomId,
        ]);

        return $result;
    }

    /**
     * Vérifie si une machine nécessite une confirmation pour être déplacée
     */
    public function checkPhysicalRoomConflict(int $machineId, int $targetGroupId): ?array
    {
        $machine = $this->repository->findMachine($machineId);
        if (!$machine || !$machine->physical_room_id) {
            return null;
        }

        $targetGroup = $this->repository->findGroup($targetGroupId);
        if (!$targetGroup || !$targetGroup->is_physical) {
            return null;
        }

        if ($machine->physical_room_id === $targetGroupId) {
            return null;
        }

        $currentRoom = $machine->physicalRoom;
        return [
            'machine_id' => $machineId,
            'machine_name' => $machine->name,
            'current_room_id' => $machine->physical_room_id,
            'current_room_name' => $currentRoom?->name ?? 'Inconnue',
            'target_room_id' => $targetGroupId,
            'target_room_name' => $targetGroup->name,
            'message' => "La machine '{$machine->name}' est actuellement dans la salle physique '{$currentRoom?->name}'. Voulez-vous la déplacer vers '{$targetGroup->name}' ?",
        ];
    }

    /**
     * Déplace une machine vers une nouvelle salle physique avec confirmation
     */
    public function moveMachineToPhysicalRoom(int $machineId, int $newRoomId, bool $confirmed = false): array
    {
        $conflict = $this->checkPhysicalRoomConflict($machineId, $newRoomId);

        if ($conflict && !$confirmed) {
            return [
                'success' => false,
                'requires_confirmation' => true,
                'conflict' => $conflict,
            ];
        }

        $this->assignMachineToPhysicalRoom($machineId, $newRoomId);

        return [
            'success' => true,
            'requires_confirmation' => false,
            'message' => 'Machine déplacée avec succès',
        ];
    }

    // ========================================
    // VALIDATION
    // ========================================

    // ========================================
    // IMPORT DEPUIS L'AD (MIGRATION INITIALE)
    // ========================================

    /**
     * Importe les groupes de postes depuis l'Active Directory vers la base de données SQL.
     * 
     * ⚠️ WARNING: Cette méthode ne devrait être utilisée QUE pour l'initialisation initiale
     * de la base de données Laravel. Une fois l'import effectué, SQL devient la source de vérité
     * et les modifications doivent être faites via l'interface Laravel, qui synchronisera
     * automatiquement vers l'AD via les observers.
     * 
     * @param callable|null $logCallback Callback pour les logs (fn(string $level, string $message) => void)
     * @return array Statistiques d'import ['created' => int, 'updated' => int, 'skipped' => int, 'errors' => array]
     */
    public function importFromAd(?callable $logCallback = null): array
    {
        Log::warning('WorkstationGroupService::importFromAd() appelé - Cette méthode ne devrait être utilisée que pour l\'initialisation initiale. SQL est la source de vérité.');

        $log = $logCallback ?? fn(string $level, string $message) => Log::log($level, $message);
        
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'linked' => 0,
            'errors' => [],
        ];

        try {
            $dnHelper = app(LdapDnHelper::class);
            $computersDn = $dnHelper->computers();
            $log('info', "Recherche dans: {$computersDn}");

            // Récupérer les OU depuis l'AD
            $groupsAd = DeviceGroupModel::in($computersDn)->get();
            $log('info', count($groupsAd) . ' groupes (OU) trouvés dans l\'AD');

            // Désactiver la synchronisation AD pendant l'import
            WorkstationGroupObserver::disableSync();

            try {
                DB::beginTransaction();

                // Première passe : créer/mettre à jour les groupes
                foreach ($groupsAd as $group) {
                    try {
                        $name = $group->getGroupName();
                        if (empty($name)) {
                            continue;
                        }

                        $dn = $group->getDn();
                        $rawGuid = $group->getFirstAttribute('objectguid');
                        $uuid = $rawGuid ? $this->convertGuidToString($rawGuid) : null;
                        $description = $group->getGroupDescription();

                        $existing = WorkstationGroup::where('name', $name)->first();

                        if ($existing) {
                            $updated = false;
                            if (empty($existing->ad_guid) && !empty($uuid)) {
                                $existing->ad_guid = $uuid;
                                $updated = true;
                            }
                            if (empty($existing->ad_dn) && !empty($dn)) {
                                $existing->ad_dn = $dn;
                                $updated = true;
                            }
                            if (empty($existing->description) && !empty($description)) {
                                $existing->description = $description;
                                $updated = true;
                            }
                            if ($name === 'computers' && empty($existing->locked)) {
                                $existing->locked = LockReason::ROOT->value;
                                $updated = true;
                            }
                            // S'assurer que is_physical est true pour les groupes importés depuis OU=Computers
                            if (!$existing->is_physical) {
                                $existing->is_physical = true;
                                $updated = true;
                            }

                            if ($updated) {
                                $existing->save();
                                $stats['updated']++;
                                $log('info', "Mis à jour: {$name}");
                            } else {
                                $stats['skipped']++;
                            }
                        } else {
                            WorkstationGroup::create([
                                'name' => $name,
                                'is_physical' => true, // Groupe physique (OU dans OU=Computers)
                                'description' => $description,
                                'ad_dn' => $dn,
                                'ad_guid' => $uuid,
                                'is_active' => true,
                            ]);

                            $stats['created']++;
                            $log('success', "Créé: {$name}");
                        }
                    } catch (\Exception $e) {
                        $groupName = $group->getGroupName() ?? 'inconnu';
                        $stats['errors'][] = "Erreur pour {$groupName}: " . $e->getMessage();
                        $log('error', "Erreur pour {$groupName}: " . $e->getMessage());
                    }
                }

                // Deuxième passe : établir les liens parent_id depuis les DN
                $allGroups = WorkstationGroup::physical()->get()->keyBy(fn($g) => strtolower($g->name));
                foreach ($allGroups as $group) {
                    if (empty($group->ad_dn)) {
                        continue;
                    }
                    $parentName = $this->extractParentGroupFromDn($group->ad_dn);
                    if ($parentName && $allGroups->has(strtolower($parentName))) {
                        $parent = $allGroups->get(strtolower($parentName));
                        if ($group->parent_id !== $parent->id) {
                            $group->parent_id = $parent->id;
                            $group->save();
                            $stats['linked']++;
                        }
                    }
                }

                // Troisième passe : créer les liens workstation <-> groupe physique dans la table pivot
                $workstations = Workstation::whereNotNull('ad_dn')->get();
                $stats['workstation_links'] = 0;
                foreach ($workstations as $workstation) {
                    $groupName = $this->extractParentGroupFromDn($workstation->ad_dn);
                    if ($groupName && $allGroups->has(strtolower($groupName))) {
                        $group = $allGroups->get(strtolower($groupName));
                        // Vérifier si le lien physique existe déjà
                        $existingLink = $workstation->groups()
                            ->where('workstation_group_id', $group->id)
                            ->wherePivot('physical', true)
                            ->exists();
                        if (!$existingLink) {
                            $workstation->groups()->attach($group->id, ['physical' => true]);
                            $stats['workstation_links']++;
                        }
                    }
                }
                $log('info', "{$stats['workstation_links']} liens workstation-groupe créés");

                DB::commit();

            } finally {
                WorkstationGroupObserver::enableSync();
            }

            $log('info', "Résultat: {$stats['created']} créés, {$stats['updated']} mis à jour, {$stats['skipped']} ignorés");

        } catch (\Exception $e) {
            DB::rollBack();
            $stats['errors'][] = 'Erreur globale: ' . $e->getMessage();
            $log('error', 'Erreur lors de l\'import: ' . $e->getMessage());
            Log::error('WorkstationGroupService::importFromAd erreur', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $stats;
    }

    /**
     * Importe les groupes logiques depuis OU=Parcs vers la base de données SQL.
     * 
     * ⚠️ WARNING: Cette méthode ne devrait être utilisée QUE pour l'initialisation initiale.
     * 
     * @deprecated Utiliser uniquement pour la migration initiale AD → SQL
     * @param callable|null $logCallback Callback pour les logs
     * @return array Statistiques d'import
     */
    public function importLogicalGroupsFromAd(?callable $logCallback = null): array
    {
        Log::warning('WorkstationGroupService::importLogicalGroupsFromAd() appelé - Migration initiale.');

        $log = $logCallback ?? fn(string $level, string $message) => Log::log($level, $message);
        
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            $dnHelper = app(LdapDnHelper::class);
            $parcsDn = $dnHelper->parcs();
            $log('info', "Recherche des groupes logiques dans: {$parcsDn}");

            // Récupérer les groupes depuis OU=Parcs
            $groupsAd = DeviceGroupTagModel::in($parcsDn)->get();
            $log('info', count($groupsAd) . ' groupes logiques (CN) trouvés dans l\'AD');

            WorkstationGroupObserver::disableSync();

            try {
                DB::beginTransaction();

                foreach ($groupsAd as $group) {
                    try {
                        $name = $group->getParcName();
                        if (empty($name)) {
                            continue;
                        }

                        $dn = $group->getDn();
                        $rawGuid = $group->getFirstAttribute('objectguid');
                        $uuid = $rawGuid ? $this->convertGuidToString($rawGuid) : null;
                        $description = $group->getDescription();

                        $existing = WorkstationGroup::where('name', $name)->first();

                        if ($existing) {
                            // Si le groupe existe déjà et est physique, on ne le modifie pas
                            // (un groupe physique est aussi un groupe logique dans l'AD)
                            if ($existing->is_physical) {
                                $stats['skipped']++;
                                $log('info', "Ignoré (groupe physique existant): {$name}");
                                continue;
                            }

                            $updated = false;
                            if (empty($existing->ad_guid) && !empty($uuid)) {
                                $existing->ad_guid = $uuid;
                                $updated = true;
                            }
                            if (empty($existing->ad_dn) && !empty($dn)) {
                                $existing->ad_dn = $dn;
                                $updated = true;
                            }
                            if (empty($existing->description) && !empty($description)) {
                                $existing->description = $description;
                                $updated = true;
                            }
                            if ($updated) {
                                $existing->save();
                                $stats['updated']++;
                                $log('info', "Mis à jour: {$name}");
                            } else {
                                $stats['skipped']++;
                            }
                        } else {
                            $locked = ($name === 'computers') ? LockReason::ROOT->value : null;
                            WorkstationGroup::create([
                                'name' => $name,
                                'is_physical' => false, // Groupe logique (CN dans OU=Parcs)
                                'description' => $description,
                                'ad_dn' => $dn,
                                'ad_guid' => $uuid,
                                'is_active' => true,
                            ]);

                            $stats['created']++;
                            $log('success', "Créé: {$name}");
                        }
                    } catch (\Exception $e) {
                        $groupName = $group->getParcName() ?? 'inconnu';
                        $stats['errors'][] = "Erreur pour {$groupName}: " . $e->getMessage();
                        $log('error', "Erreur pour {$groupName}: " . $e->getMessage());
                    }
                }

                // Deuxième passe : créer les liens workstation <-> groupe logique
                // Les groupes dans OU=Parcs ont un attribut 'member' avec les DN des machines
                $stats['workstation_links'] = 0;
                $allWorkstations = Workstation::all()->keyBy(fn($w) => strtolower($w->name));
                
                // Indexer les groupes AD par nom pour recherche rapide
                $adGroupsByName = [];
                foreach ($groupsAd as $adGroup) {
                    $name = $adGroup->getParcName();
                    if (!empty($name)) {
                        $adGroupsByName[strtolower($name)] = $adGroup;
                    }
                }

                $logicalGroups = WorkstationGroup::logical()->get();

                foreach ($logicalGroups as $sqlGroup) {
                    // Récupérer le groupe AD correspondant
                    $adGroup = $adGroupsByName[strtolower($sqlGroup->name)] ?? null;
                    if (!$adGroup) {
                        continue;
                    }

                    // Récupérer les membres du groupe AD
                    $members = $adGroup->getFirstAttribute('member');
                    if (empty($members)) {
                        continue;
                    }
                    
                    // member peut être un string ou un array
                    $memberDns = is_array($members) ? $members : [$members];

                    foreach ($memberDns as $memberDn) {
                        // Extraire le nom de la machine depuis le DN (CN=pc-xxx,...)
                        if (preg_match('/^CN=([^,]+),/i', $memberDn, $matches)) {
                            $machineName = strtolower(rtrim($matches[1], '$')); // Enlever le $ final si présent
                            
                            if ($allWorkstations->has($machineName)) {
                                $workstation = $allWorkstations->get($machineName);
                                
                                // Vérifier si le lien existe déjà
                                $existingLink = $workstation->groups()
                                    ->where('workstation_group_id', $sqlGroup->id)
                                    ->exists();
                                    
                                if (!$existingLink) {
                                    $workstation->groups()->attach($sqlGroup->id, ['physical' => false]);
                                    $stats['workstation_links']++;
                                }
                            }
                        }
                    }
                }
                $log('info', "{$stats['workstation_links']} liens workstation-groupe logique créés");

                DB::commit();

            } finally {
                WorkstationGroupObserver::enableSync();
            }

            $log('info', "Résultat: {$stats['created']} créés, {$stats['updated']} mis à jour, {$stats['skipped']} ignorés");

        } catch (\Exception $e) {
            DB::rollBack();
            $stats['errors'][] = 'Erreur globale: ' . $e->getMessage();
            $log('error', 'Erreur lors de l\'import: ' . $e->getMessage());
            Log::error('WorkstationGroupService::importLogicalGroupsFromAd erreur', [
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

    /**
     * Extrait le nom du groupe parent depuis le DN
     * Pour une OU: OU=chimie1,OU=labos,OU=Computers,DC=... => labos
     * Pour une machine: CN=pc-xxx,OU=techno,OU=Computers,DC=... => techno
     */
    private function extractParentGroupFromDn(string $dn): ?string
    {
        // Pour une machine (CN=...,OU=groupe,...)
        if (preg_match('/^CN=[^,]+,OU=([^,]+),/i', $dn, $matches)) {
            $parent = $matches[1];
            if (strtolower($parent) !== 'computers') {
                return $parent;
            }
        }
        // Pour une OU (OU=...,OU=parent,...)
        elseif (preg_match('/^OU=[^,]+,OU=([^,]+),/i', $dn, $matches)) {
            $parent = $matches[1];
            if (strtolower($parent) !== 'computers') {
                return $parent;
            }
        }
        return null;
    }

    // ========================================
    // VALIDATION
    // ========================================

    /**
     * Valide les données d'un groupe
     */
    private function validateGroupData(array $data, ?WorkstationGroup $existingGroup = null): void
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('Le nom du groupe est requis');
        }

        $query = WorkstationGroup::where('name', $data['name']);

        if ($existingGroup) {
            $query->where('id', '!=', $existingGroup->id);
        }

        if ($query->exists()) {
            throw new \InvalidArgumentException("Un groupe avec le nom '{$data['name']}' existe déjà");
        }

        if (!empty($data['parent_id'])) {
            $parent = WorkstationGroup::find($data['parent_id']);

            if (!$parent) {
                throw new \InvalidArgumentException("Le groupe parent {$data['parent_id']} n'existe pas");
            }

            if ($existingGroup && $data['parent_id'] == $existingGroup->id) {
                throw new \InvalidArgumentException('Un groupe ne peut pas être son propre parent');
            }
        }
    }
}
