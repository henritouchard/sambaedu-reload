<?php

declare(strict_types=1);

namespace App\Services\AdSync;

use App\Config\LdapDnHelper;
use App\Config\SambaEduConfig;
use App\LdapModels\DeviceGroupModel;
use App\LdapModels\MachineModel;
use App\Models\WorkstationGroup;
use App\Models\Workstation;
use App\Observers\WorkstationObserver;
use Illuminate\Support\Facades\Log;
use LdapRecord\Models\ActiveDirectory\OrganizationalUnit;

/**
 * Service de synchronisation SQL → AD des salles physiques et des machines.
 *
 * ── Asymétrie `OU=Parcs` / `OU=Computers` (Story 38.7) ───────────────────────
 * `OU=Parcs` est un vestige SE4 en LECTURE SEULE : LU à l'import de migration,
 * jamais ÉCRIT. Ce service n'écrit plus QUE dans `OU=Computers` — l'`OU` d'une
 * salle physique, où sont rangées les machines et où sont liées les GPO. C'est
 * l'unique invariant AD à préserver.
 *
 * Ce qui a disparu en 38.7 : la branche logique de {@see createWorkstationGroup()},
 * le miroir `CN` des salles dans `OU=Parcs`, et tout l'entretien de l'attribut
 * `member` (l'appartenance machine ↔ parc est SQL-only). Les groupes LOGIQUES
 * (`is_physical = false`) n'ont plus AUCUNE représentation écrite dans l'AD ;
 * les méthodes publiques les refusent explicitement (défense en profondeur —
 * l'observer filtre déjà en amont).
 */
class AdSyncService
{
    public function __construct(
        private SambaEduConfig $config,
        private LdapDnHelper $dnHelper
    ) {
    }

    // ========================================================================
    // GESTION DES SALLES PHYSIQUES (OU dans OU=Computers)
    // ========================================================================

    /**
     * Crée l'`OU` d'une salle physique dans `OU=Computers`.
     *
     * Un groupe LOGIQUE est refusé : il n'a plus aucune écriture AD (38.7).
     */
    public function createWorkstationGroup(WorkstationGroup $group): array
    {
        if (! $group->is_physical) {
            return $this->refuseLogical('createWorkstationGroup', $group->name, withGuidDn: true);
        }

        $name = $group->name;
        $description = $group->description ?? "Groupe de postes $name";

        Log::info('[AdSyncService] Création OU salle dans AD', [
            'name' => $name,
            'parent_id' => $group->parent_id,
        ]);

        try {
            $existingOu = $this->findSalleOu($name);

            if ($existingOu) {
                return [
                    'success' => true,
                    'guid' => $existingOu->getConvertedGuid(),
                    'dn' => $existingOu->getDn(),
                    'error' => null,
                ];
            }

            $ouResult = $this->createSalleOu($name, $description, $group->parent_id);
            if (! $ouResult['success']) {
                return $ouResult;
            }

            return [
                'success' => true,
                'guid' => $ouResult['guid'],
                'dn' => $ouResult['dn'],
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('[AdSyncService] Erreur création OU salle AD', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'guid' => null, 'dn' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Supprime l'`OU` d'une salle physique de `OU=Computers`.
     *
     * `$isPhysical = false` est un refus explicite : un groupe logique n'a plus
     * de représentation AD (38.7). Le paramètre est conservé pour la stabilité
     * de signature (job de suppression), mais toute valeur `false` est rejetée.
     */
    public function deleteWorkstationGroupByName(string $name, ?string $adGuid = null, bool $isPhysical = true): array
    {
        if (! $isPhysical) {
            return $this->refuseLogical('deleteWorkstationGroupByName', $name);
        }

        Log::info('[AdSyncService] Suppression OU salle AD', ['name' => $name]);

        try {
            return $this->deleteSalleOu($name);
        } catch (\Exception $e) {
            Log::error('[AdSyncService] Erreur suppression OU salle AD', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Renomme l'`OU` d'une salle physique dans `OU=Computers`.
     *
     * Un groupe logique est refusé (plus d'écriture AD — 38.7).
     */
    public function renameWorkstationGroup(WorkstationGroup $group, string $oldName, string $newName): array
    {
        if (! $group->is_physical) {
            return $this->refuseLogical('renameWorkstationGroup', $oldName);
        }

        Log::info('[AdSyncService] Renommage OU salle dans AD', [
            'old_name' => $oldName,
            'new_name' => $newName,
        ]);

        try {
            return $this->renameSalleOu($oldName, $newName);
        } catch (\Exception $e) {
            Log::error('[AdSyncService] Erreur renommage OU salle AD', [
                'old_name' => $oldName,
                'new_name' => $newName,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Déplace l'`OU` d'une salle physique vers un nouveau parent dans `OU=Computers`.
     *
     * Réduit en 38.7 au seul `move()` de l'`OU` : plus aucun entretien de membres
     * (l'appartenance machine ↔ parc est SQL-only). Un groupe logique est refusé
     * — il n'a d'ailleurs jamais eu d'`OU` à déplacer (`findSalleOu()` retournait
     * null → « OU salle non trouvée » ; cf. défaut n°3 du contexte de la story).
     */
    public function moveWorkstationGroup(WorkstationGroup $group, ?WorkstationGroup $newParent): array
    {
        if (! $group->is_physical) {
            return $this->refuseLogical('moveWorkstationGroup', $group->name);
        }

        $groupName = $group->name;
        $newParentName = $newParent?->name ?? 'Computers (racine)';

        Log::info('[AdSyncService] Déplacement OU salle dans AD', [
            'name' => $groupName,
            'new_parent' => $newParentName,
        ]);

        try {
            $currentOu = $this->findSalleOu($groupName);
            if (! $currentOu) {
                return ['success' => false, 'error' => "OU salle '$groupName' non trouvée dans AD"];
            }

            $newParentDn = $this->dnHelper->computers();
            if ($newParent) {
                $parentOu = $this->findSalleOu($newParent->name);
                if ($parentOu) {
                    $newParentDn = $parentOu->getDn();
                }
            }

            $currentOu->move($newParentDn);

            Log::info('[AdSyncService] Déplacement OU salle réussi', [
                'name' => $groupName,
                'new_dn' => "OU={$groupName},{$newParentDn}",
            ]);

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            Log::error('[AdSyncService] Erreur déplacement OU salle AD', [
                'name' => $groupName,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ========================================================================
    // GESTION DES MACHINES DANS LES SALLES (OU)
    // ========================================================================

    // NOTE: l'appartenance des machines aux groupes (parcs) est gérée uniquement
    // en SQL. Le calcul des applications WPKG se fait depuis la base, pas depuis
    // l'AD. Seul le déplacement physique d'une machine vers une salle (OU) reste
    // synchronisé (rangement + GPO).

    /**
     * Déplace une machine vers l'`OU` d'une salle et remonte `workstations.ad_dn`.
     *
     * Réduit en 38.7 au seul `move()` de l'objet ordinateur : plus aucun entretien
     * de l'attribut `member` des groupes `OU=Parcs`.
     */
    public function moveMachineToSalle(Workstation $machine, WorkstationGroup $targetSalle): array
    {
        $machineName = $machine->name;
        $salleName = $targetSalle->name;

        Log::info('[AdSyncService] Déplacement machine vers salle', [
            'machine' => $machineName,
            'target_salle' => $salleName,
        ]);

        try {
            $machineAd = $this->findMachine($machineName);
            if (! $machineAd) {
                return ['success' => false, 'error' => "Machine '$machineName' non trouvée dans AD"];
            }

            $targetOu = $this->findSalleOu($salleName);
            if (! $targetOu) {
                return ['success' => false, 'error' => "Salle OU '$salleName' non trouvée dans AD"];
            }

            try {
                $machineAd->move($targetOu);
            } catch (\LdapRecord\LdapRecordException $e) {
                return ['success' => false, 'error' => "Erreur LDAP rename: {$e->getMessage()}"];
            }

            $machineAd = $this->findMachine($machineName);
            $this->syncAdDnFromMachine($machine, $machineAd);

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            Log::error('[AdSyncService] Erreur déplacement machine', [
                'machine' => $machineName,
                'target_salle' => $salleName,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ========================================================================
    // MÉTHODES PRIVÉES - OPÉRATIONS LDAP DE BAS NIVEAU (OU=Computers uniquement)
    // ========================================================================

    /**
     * Refus uniforme d'un groupe logique sur une méthode d'écriture AD (défense
     * en profondeur : le chemin normal ne doit jamais l'atteindre, l'observer
     * filtrant sur `is_physical` en amont).
     */
    private function refuseLogical(string $method, string $name, bool $withGuidDn = false): array
    {
        $error = "Groupe logique '{$name}' refusé : OU=Parcs est en lecture seule (38.7), aucune écriture AD.";

        Log::warning('[AdSyncService] Écriture AD refusée pour groupe logique', [
            'method' => $method,
            'name' => $name,
        ]);

        $result = ['success' => false, 'error' => $error];
        if ($withGuidDn) {
            $result['guid'] = null;
            $result['dn'] = null;
        }

        return $result;
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
            'guid' => $guid,
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
        if (! $ou) {
            return ['success' => false, 'error' => "OU salle '$oldName' non trouvée"];
        }

        $ou->rename("OU={$newName}");

        Log::debug('[AdSyncService] OU salle renommée', [
            'old_name' => $oldName,
            'new_name' => $newName,
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

    /**
     * Machines rangées dans une `OU` de salle. Conservé (moitié `OU=Computers`)
     * bien qu'inutilisé depuis le retrait de l'entretien de membres.
     *
     * @return array<int, MachineModel>
     */
    private function getMachinesInOu(DeviceGroupModel $ou): array
    {
        return MachineModel::in($ou->getDn())
            ->limit(500)
            ->get()
            ->all();
    }
}
