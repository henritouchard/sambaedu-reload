<?php

declare(strict_types=1);

namespace App\Services\AdSync;

use App\Config\LdapDnHelper;
use App\Config\SambaEduConfig;
use App\LdapModels\DeviceGroupModel;
use App\LdapModels\DeviceGroupTagModel;
use App\LdapModels\MachineModel;
use App\Models\WorkstationGroup;
use App\Models\Workstation;
use App\Observers\WorkstationObserver;
use Illuminate\Support\Facades\Log;
use LdapRecord\Models\ActiveDirectory\Group;
use LdapRecord\Models\ActiveDirectory\OrganizationalUnit;

/**
 * Service de synchronisation SQL → AD pour les parcs et machines
 * 
 * Adapté pour le nouveau schéma PostgreSQL.
 */
class AdSyncService
{
    public function __construct(
        private SambaEduConfig $config,
        private LdapDnHelper $dnHelper
    ) {
    }

    // ========================================================================
    // GESTION DES PARCS/SALLES
    // ========================================================================

    /**
     * Crée un groupe de machines dans l'AD
     * 
     * Règles de synchronisation SQL → AD :
     * - Groupe physique (is_physical=true) : crée OU dans OU=Computers ET CN dans OU=Parcs
     * - Groupe logique (is_physical=false) : crée CN dans OU=Parcs uniquement
     * 
     * Les AppProfiles sont gérés séparément par l'AppProfileObserver.
     */
    public function createWorkstationGroup(WorkstationGroup $group): array
    {
        $name = $group->name;
        $description = $group->description ?? "Groupe de postes $name";
        $isPhysical = $group->is_physical;

        Log::info('[AdSyncService] Création WorkstationGroup dans AD', [
            'name' => $name,
            'is_physical' => $isPhysical,
            'parent_id' => $group->parent_id
        ]);

        try {
            $guid = null;
            $dn = null;

            if ($isPhysical) {
                // Groupe physique : créer OU dans OU=Computers
                $existingOu = $this->findSalleOu($name);

                if ($existingOu) {
                    $guid = $existingOu->getConvertedGuid();
                    $dn = $existingOu->getDn();
                    Log::debug('[AdSyncService] OU existe déjà', [
                        'name' => $name,
                        'guid' => $guid,
                        'dn' => $dn
                    ]);
                } else {
                    $ouResult = $this->createSalleOu($name, $description, $group->parent_id);
                    if (!$ouResult['success']) {
                        return $ouResult;
                    }
                    $guid = $ouResult['guid'];
                    $dn = $ouResult['dn'];
                }

                // Groupe physique : créer aussi CN dans OU=Parcs
                $existingCn = $this->findGroupCn($name);
                if (!$existingCn) {
                    $cnResult = $this->createGroupCn($name, $description);
                    if (!$cnResult['success']) {
                        Log::warning('[AdSyncService] Échec création CN pour groupe physique', [
                            'name' => $name,
                            'error' => $cnResult['error']
                        ]);
                    }
                }
            } else {
                // Groupe logique : créer CN dans OU=Parcs uniquement
                $existingCn = $this->findGroupCn($name);

                if ($existingCn) {
                    $guid = $existingCn->getConvertedGuid();
                    $dn = $existingCn->getDn();
                    Log::debug('[AdSyncService] CN existe déjà', [
                        'name' => $name,
                        'guid' => $guid,
                        'dn' => $dn
                    ]);
                } else {
                    $cnResult = $this->createGroupCn($name, $description);
                    if (!$cnResult['success']) {
                        return $cnResult;
                    }
                    $guid = $cnResult['guid'];
                    $dn = "CN={$name}," . $this->dnHelper->parcs();
                }
            }

            return [
                'success' => true,
                'guid' => $guid,
                'dn' => $dn,
                'error' => null
            ];

        } catch (\Exception $e) {
            Log::error('[AdSyncService] Erreur création groupe AD', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'guid' => null,
                'dn' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Supprime un groupe de machines de l'AD par son nom
     * 
     * Règles de synchronisation SQL → AD :
     * - Groupe physique : supprime OU dans OU=Computers ET CN dans OU=Parcs
     * - Groupe logique : supprime CN dans OU=Parcs uniquement
     */
    public function deleteWorkstationGroupByName(string $name, ?string $adGuid = null, bool $isPhysical = true): array
    {
        Log::info('[AdSyncService] Suppression groupe AD', [
            'name' => $name,
            'is_physical' => $isPhysical
        ]);

        try {
            if ($isPhysical) {
                // Groupe physique : supprimer OU dans OU=Computers
                $ouResult = $this->deleteSalleOu($name);
                if (!$ouResult['success']) {
                    return $ouResult;
                }
            }

            // Supprimer CN dans OU=Parcs (pour physique ET logique)
            $cnResult = $this->deleteGroupCn($name);
            if (!$cnResult['success']) {
                Log::warning('[AdSyncService] Échec suppression CN', [
                    'name' => $name,
                    'error' => $cnResult['error']
                ]);
            }

            return ['success' => true, 'error' => null];

        } catch (\Exception $e) {
            Log::error('[AdSyncService] Erreur suppression groupe AD', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Renomme un groupe de machines dans l'AD
     * 
     * Règles de synchronisation SQL → AD :
     * - Groupe physique : renomme OU dans OU=Computers ET CN dans OU=Parcs
     * - Groupe logique : renomme CN dans OU=Parcs uniquement
     */
    public function renameWorkstationGroup(WorkstationGroup $group, string $oldName, string $newName): array
    {
        $isPhysical = $group->is_physical;

        Log::info('[AdSyncService] Renommage WorkstationGroup dans AD', [
            'old_name' => $oldName,
            'new_name' => $newName,
            'is_physical' => $isPhysical
        ]);

        try {
            if ($isPhysical) {
                // Groupe physique : renommer OU dans OU=Computers
                $ouResult = $this->renameSalleOu($oldName, $newName);
                if (!$ouResult['success']) {
                    return $ouResult;
                }
            }

            // Renommer CN dans OU=Parcs (pour physique ET logique)
            $cnResult = $this->renameGroupCn($oldName, $newName);
            if (!$cnResult['success']) {
                Log::warning('[AdSyncService] Échec renommage CN', [
                    'old_name' => $oldName,
                    'new_name' => $newName,
                    'error' => $cnResult['error']
                ]);
            }

            return ['success' => true, 'error' => null];

        } catch (\Exception $e) {
            Log::error('[AdSyncService] Erreur renommage groupe AD', [
                'old_name' => $oldName,
                'new_name' => $newName,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Déplace un groupe de machines vers un nouveau parent dans l'AD
     */
    public function moveWorkstationGroup(WorkstationGroup $group, ?WorkstationGroup $newParent): array
    {
        $groupName = $group->name;
        $newParentName = $newParent?->name ?? 'Computers (racine)';

        Log::info('[AdSyncService] Déplacement WorkstationGroup dans AD', [
            'name' => $groupName,
            'new_parent' => $newParentName
        ]);

        try {
            $currentOu = $this->findSalleOu($groupName);
            if (!$currentOu) {
                return ['success' => false, 'error' => "OU salle '$groupName' non trouvée dans AD"];
            }

            $newParentDn = $this->dnHelper->computers();
            if ($newParent) {
                $parentOu = $this->findSalleOu($newParent->name);
                if ($parentOu) {
                    $newParentDn = $parentOu->getDn();
                }
            }

            $machines = $this->getMachinesInOu($currentOu);
            $oldParentGroups = $this->getParentGroupNames($group);

            $newDn = "OU={$groupName},{$newParentDn}";
            $currentOu->move($newParentDn);

            $group->refresh();
            $newParentGroups = $this->getParentGroupNames($group);

            $groupsToRemove = array_diff($oldParentGroups, $newParentGroups);
            $groupsToAdd = array_diff($newParentGroups, $oldParentGroups);

            foreach ($machines as $machine) {
                foreach ($groupsToRemove as $groupToRemove) {
                    $this->removeMachineFromGroupByName($machine, $groupToRemove);
                }
                foreach ($groupsToAdd as $groupToAdd) {
                    $this->addMachineToGroupByName($machine, $groupToAdd);
                }
            }

            Log::info('[AdSyncService] Déplacement groupe réussi', [
                'name' => $groupName,
                'new_dn' => $newDn,
                'machines_updated' => count($machines)
            ]);

            return ['success' => true, 'error' => null];

        } catch (\Exception $e) {
            Log::error('[AdSyncService] Erreur déplacement groupe AD', [
                'name' => $groupName,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ========================================================================
    // GESTION DES MACHINES DANS LES SALLES (OU)
    // ========================================================================

    // NOTE: Les méthodes addMemberToGroup et removeMemberFromGroup ont été supprimées.
    // L'appartenance des machines aux groupes (parcs) est maintenant gérée uniquement en SQL.
    // Le calcul des applications WPKG se fait depuis la base de données, pas depuis l'AD.
    // Seul le déplacement physique d'une machine vers une salle (OU) reste synchronisé.

    /**
     * Déplace une machine vers une salle (OU)
     */
    public function moveMachineToSalle(Workstation $machine, WorkstationGroup $targetSalle): array
    {
        $machineName = $machine->name;
        $salleName = $targetSalle->name;

        Log::info('[AdSyncService] Déplacement machine vers salle', [
            'machine' => $machineName,
            'target_salle' => $salleName
        ]);

        try {
            $machineAd = $this->findMachine($machineName);
            if (!$machineAd) {
                return ['success' => false, 'error' => "Machine '$machineName' non trouvée dans AD"];
            }

            $targetOu = $this->findSalleOu($salleName);
            if (!$targetOu) {
                return ['success' => false, 'error' => "Salle OU '$salleName' non trouvée dans AD"];
            }

            $oldGroups = $this->getMachineGroups($machineAd);

            try {
                $machineAd->move($targetOu);
            } catch (\LdapRecord\LdapRecordException $e) {
                return ['success' => false, 'error' => "Erreur LDAP rename: {$e->getMessage()}"];
            }

            $machineAd = $this->findMachine($machineName);
            $this->syncAdDnFromMachine($machine, $machineAd);
            $newGroups = $this->getSalleHierarchyGroups($targetSalle);

            foreach ($oldGroups as $oldGroup) {
                if (!in_array($oldGroup, $newGroups)) {
                    $this->removeMachineFromGroupByName($machineAd, $oldGroup);
                }
            }

            foreach ($newGroups as $newGroup) {
                if (!in_array($newGroup, $oldGroups)) {
                    $this->addMachineToGroupByName($machineAd, $newGroup);
                }
            }

            return ['success' => true, 'error' => null];

        } catch (\Exception $e) {
            Log::error('[AdSyncService] Erreur déplacement machine', [
                'machine' => $machineName,
                'target_salle' => $salleName,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ========================================================================
    // MÉTHODES PRIVÉES - OPÉRATIONS LDAP DE BAS NIVEAU
    // ========================================================================

    private function createGroupCn(string $name, string $description): array
    {
        $parcsDn = $this->dnHelper->parcs();
        $suffix = $this->config->establishment()->suffix ?? '';
        $samAccountName = $name . $suffix;

        $group = new Group();
        $group->setDn("CN={$name},{$parcsDn}");
        $group->cn = $name;
        $group->samaccountname = $samAccountName;
        $group->description = $description;
        $group->grouptype = -2147483646;

        $group->save();

        $group = Group::find($group->getDn());
        $guid = $group?->getConvertedGuid();

        Log::debug('[AdSyncService] Groupe CN créé', [
            'name' => $name,
            'dn' => "CN={$name},{$parcsDn}",
            'guid' => $guid
        ]);

        return ['success' => true, 'guid' => $guid, 'error' => null];
    }

    private function deleteGroupCn(string $name): array
    {
        $group = $this->findGroupCn($name);
        if ($group) {
            $group->delete();
            Log::debug('[AdSyncService] Groupe CN supprimé', ['name' => $name]);
        }
        return ['success' => true, 'error' => null];
    }

    private function renameGroupCn(string $oldName, string $newName): array
    {
        $group = $this->findGroupCn($oldName);
        if (!$group) {
            return ['success' => false, 'error' => "Groupe CN '$oldName' non trouvé"];
        }

        $suffix = $this->config->establishment()->suffix ?? '';
        $group->rename("CN={$newName}");
        $group->samaccountname = $newName . $suffix;
        $group->save();

        Log::debug('[AdSyncService] Groupe CN renommé', [
            'old_name' => $oldName,
            'new_name' => $newName
        ]);

        return ['success' => true, 'error' => null];
    }

    private function findGroupCn(string $name): ?DeviceGroupTagModel
    {
        $parcsDn = $this->dnHelper->parcs();
        return DeviceGroupTagModel::in($parcsDn)
            ->where('cn', '=', $name)
            ->first();
    }

    private function createSalleOu(string $name, string $description, ?int $parentId): array
    {
        $parentDn = $this->dnHelper->computers();

        if ($parentId) {
            $parentGroup = WorkstationGroup::find($parentId);
            if ($parentGroup && $parentGroup->is_physical) {
                $parentOu = $this->findSalleOu($parentGroup->name);
                if ($parentOu) {
                    $parentDn = $parentOu->getDn();
                }
            }
        }

        $ouDn = "OU={$name},{$parentDn}";

        $ou = new OrganizationalUnit();
        $ou->setDn($ouDn);
        $ou->ou = $name;
        $ou->save();

        $ou = OrganizationalUnit::find($ouDn);
        $guid = $ou?->getConvertedGuid();

        Log::debug('[AdSyncService] OU salle créée', [
            'name' => $name,
            'dn' => $ouDn,
            'guid' => $guid
        ]);

        return ['success' => true, 'guid' => $guid, 'dn' => $ouDn, 'error' => null];
    }

    private function deleteSalleOu(string $name): array
    {
        $ou = $this->findSalleOu($name);
        if ($ou) {
            $machines = MachineModel::in($ou->getDn())->limit(1)->get();
            if ($machines->count() > 0) {
                return ['success' => false, 'error' => "L'OU '$name' contient encore des machines"];
            }
            $ou->delete();
            Log::debug('[AdSyncService] OU salle supprimée', ['name' => $name]);
        }
        return ['success' => true, 'error' => null];
    }

    private function renameSalleOu(string $oldName, string $newName): array
    {
        $ou = $this->findSalleOu($oldName);
        if (!$ou) {
            return ['success' => false, 'error' => "OU salle '$oldName' non trouvée"];
        }

        $ou->rename("OU={$newName}");

        Log::debug('[AdSyncService] OU salle renommée', [
            'old_name' => $oldName,
            'new_name' => $newName
        ]);

        return ['success' => true, 'error' => null];
    }

    private function findSalleOu(string $name): ?DeviceGroupModel
    {
        $computersDn = $this->dnHelper->computers();
        return DeviceGroupModel::in($computersDn)
            ->where('ou', '=', $name)
            ->first();
    }

    private function findMachine(string $name): ?MachineModel
    {
        $computersDn = $this->dnHelper->computers();
        return MachineModel::in($computersDn)
            ->where('cn', '=', $name)
            ->first();
    }

    /**
     * Rafraîchit `workstations.ad_dn` après un déplacement d'OU.
     * Iso pattern {@see \App\Jobs\AdSync\WorkstationAdSyncJob::syncAdDnFromMachine()}.
     */
    private function syncAdDnFromMachine(Workstation $workstation, ?MachineModel $machineAd): void
    {
        $dn = (string) $machineAd?->getDn();
        if ($dn === '' || (string) $workstation->ad_dn === $dn) {
            return;
        }

        WorkstationObserver::withoutSync(function () use ($workstation, $dn): void {
            $workstation->ad_dn = $dn;
            $workstation->save();
        });

        Log::info('[AdSyncService] ad_dn PG rafraîchi après déplacement OU', [
            'id' => $workstation->id,
            'ad_dn' => $dn,
        ]);
    }

    private function getMachineGroups(MachineModel $machine): array
    {
        $memberOf = $machine->memberof ?? [];
        if (!is_array($memberOf)) {
            $memberOf = [$memberOf];
        }

        $parcsDn = strtolower($this->dnHelper->parcs());
        $groups = [];

        foreach ($memberOf as $dn) {
            if (stripos($dn, $parcsDn) !== false) {
                if (preg_match('/^CN=([^,]+),/i', $dn, $matches)) {
                    $groups[] = $matches[1];
                }
            }
        }

        return $groups;
    }

    private function getSalleHierarchyGroups(WorkstationGroup $salle): array
    {
        $groups = [$salle->name];

        $current = $salle;
        while ($current->parent_id) {
            $parent = WorkstationGroup::find($current->parent_id);
            if ($parent && $parent->is_physical) {
                $groups[] = $parent->name;
                $current = $parent;
            } else {
                break;
            }
        }

        return $groups;
    }

    private function getMachinesInOu(DeviceGroupModel $ou): array
    {
        $ouDn = $ou->getDn();
        return MachineModel::in($ouDn)
            ->limit(500)
            ->get()
            ->all();
    }

    private function getParentGroupNames(WorkstationGroup $group): array
    {
        $groups = [];

        $current = $group;
        while ($current->parent_id) {
            $parent = WorkstationGroup::find($current->parent_id);
            if ($parent && $parent->is_physical) {
                $groups[] = $parent->name;
                $current = $parent;
            } else {
                break;
            }
        }

        return $groups;
    }

    private function addMachineToGroupByName(MachineModel $machine, string $groupName): void
    {
        $group = $this->findGroupCn($groupName);
        if ($group) {
            $members = $group->member ?? [];
            if (!is_array($members)) {
                $members = [$members];
            }

            $machineDn = $machine->getDn();
            if (!in_array($machineDn, $members)) {
                $members[] = $machineDn;
                $group->member = $members;
                $group->save();
            }
        }
    }

    private function removeMachineFromGroupByName(MachineModel $machine, string $groupName): void
    {
        $group = $this->findGroupCn($groupName);
        if ($group) {
            $members = $group->member ?? [];
            if (!is_array($members)) {
                $members = [$members];
            }

            $machineDn = $machine->getDn();
            $members = array_filter($members, fn($m) => strcasecmp($m, $machineDn) !== 0);
            $group->member = array_values($members);
            $group->save();
        }
    }
}
