<?php

namespace App\Repositories;

use App\LdapModels\MachineModel;
use Illuminate\Support\Collection;

/**
 * Repository pour les postes de travail (workstations)
 * 
 * Masque complètement la complexité LDAP et expose uniquement des objets métier
 */
class WorkstationRepository
{
    /**
     * Recherche une machine par son nom
     * 
     * @param string $name
     * @return MachineModel|null
     */
    public function findByName(string $name): ?MachineModel
    {
        return MachineModel::findByName($name);
    }

    /**
     * Recherche une machine par son hostname
     * 
     * @param string $hostname
     * @return MachineModel|null
     */
    public function findByHostname(string $hostname): ?MachineModel
    {
        return MachineModel::findByHostname($hostname);
    }

    /**
     * Recherche une machine par son adresse IP
     * 
     * @param string $ip
     * @return MachineModel|null
     */
    public function findByIp(string $ip): ?MachineModel
    {
        return MachineModel::findByIp($ip);
    }

    /**
     * Recherche une machine par son adresse MAC
     * 
     * @param string $mac
     * @return MachineModel|null
     */
    public function findByMac(string $mac): ?MachineModel
    {
        return MachineModel::findByMac($mac);
    }

    /**
     * Recherche des machines par terme de recherche
     * 
     * @param string $query
     * @param int $limit
     * @return Collection Collection de MachineModel
     */
    public function search(string $query, int $limit = 50): Collection
    {
        return MachineModel::where('cn', 'contains', $query)
            ->orWhere('samaccountname', 'contains', $query)
            ->limit($limit)
            ->get();
    }

    /**
     * Récupère toutes les machines actives
     * 
     * @param int $limit
     * @return Collection Collection de MachineModel
     */
    public function findActive(int $limit = 1000): Collection
    {
        return MachineModel::where('useraccountcontrol', '=', 4096)
            ->limit($limit)
            ->get();
    }

    /**
     * Récupère les machines d'un parc
     * 
     * @param string $parcId Identifiant du parc (CN ou samAccountName)
     * @param int $limit Limite le nombre de machines retournées (défaut: 200)
     * @return Collection Collection de MachineModel
     */
    public function findByParc(string $parcId, int $limit = 200): Collection
    {
        // Rechercher le parc par samAccountName d'abord
        $parc = \App\LdapModels\DeviceGroupTagModel::findBySamAccountName($parcId);

        // Si non trouvé, essayer par nom (cn)
        if (!$parc) {
            $baseDn = \App\LdapModels\DeviceGroupTagModel::baseDn();
            $parc = \App\LdapModels\DeviceGroupTagModel::in($baseDn)
                ->where('cn', '=', $parcId)
                ->first();
        }

        if (!$parc) {
            return collect([]);
        }

        return $parc->machines($limit);
    }

    /**
     * Vérifie si une machine existe
     * 
     * @param string $machineName Nom ou hostname de la machine
     * @return bool
     */
    public function exists(string $machineName): bool
    {
        $machine = $this->findByName($machineName);

        if (!$machine) {
            $machine = $this->findByHostname($machineName);
        }

        return $machine !== null;
    }
}

