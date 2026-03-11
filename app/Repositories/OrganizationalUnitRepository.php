<?php

namespace App\Repositories;

use App\Config\SambaEduConfig;
use App\LdapModels\OrganizationalUnitModel;
use Illuminate\Support\Facades\Log;

/**
 * Repository pour les opérations sur les Unités Organisationnelles (OU)
 * Abstraction des opérations LDAP sur les OUs
 */
class OrganizationalUnitRepository
{
    public function __construct(
        private SambaEduConfig $config,
        private EstablishmentRepository $establishmentRepository
    ) {
    }
    /**
     * Vérifie si une OU existe
     * 
     * @param string $dn Le Distinguished Name de l'OU
     * @return bool
     */
    public function exists(string $dn): bool
    {
        return OrganizationalUnitModel::exists($dn);
    }

    /**
     * Trouve une OU par son DN
     * 
     * @param string $dn Le Distinguished Name de l'OU
     * @return OrganizationalUnitModel|null
     */
    public function findByDn(string $dn): ?OrganizationalUnitModel
    {
        return OrganizationalUnitModel::findByDn($dn);
    }

    /**
     * Crée une nouvelle OU
     * 
     * @param string $dn Le Distinguished Name complet de l'OU
     * @param string $ouName Le nom de l'OU
     * @param string|null $description Description optionnelle
     * @return OrganizationalUnitModel
     * @throws \LdapRecord\Exceptions\LdapRecordException
     */
    public function create(string $dn, string $ouName, ?string $description = null): OrganizationalUnitModel
    {
        return OrganizationalUnitModel::createOU($dn, $ouName, $description);
    }

    /**
     * Crée une OU si elle n'existe pas déjà
     * 
     * @param string $dn Le Distinguished Name complet de l'OU
     * @param string $ouName Le nom de l'OU
     * @param string|null $description Description optionnelle
     * @return OrganizationalUnitModel
     */
    public function createIfNotExists(string $dn, string $ouName, ?string $description = null): OrganizationalUnitModel
    {
        return OrganizationalUnitModel::createIfNotExists($dn, $ouName, $description);
    }

    /**
     * Crée une hiérarchie d'OUs en créant les parents si nécessaire
     * Réplique le comportement de ouadd() dans le legacy
     * 
     * @param string $dn Le DN complet de l'OU à créer
     * @param string $ouName Le nom de l'OU
     * @return OrganizationalUnitModel
     */
    public function createWithParents(string $dn, string $ouName): OrganizationalUnitModel
    {
        return OrganizationalUnitModel::createWithParents($dn, $ouName);
    }

    /**
     * S'assure que toutes les OUs nécessaires existent pour un DN utilisateur
     * Crée la hiérarchie complète si nécessaire
     * Utilise SambaEduConfig pour accéder à la configuration de manière typée
     * 
     * @param string $categorie Catégorie de l'utilisateur (Eleves, Profs, Administratifs)
     * @param string $fonction Fonction/classe de l'utilisateur
     * @param int $etab ID de l'établissement
     * @return void
     */
    public function ensureUserOUsExist(string $categorie, string $fonction, int $etab): void
    {
        $ldapConfig = $this->config->ldap();
        $baseDn = $ldapConfig->baseDn;
        $peopleRdn = $ldapConfig->peopleRdn;

        // Ajouter l'UAI au peopleRdn si multi-établissement
        $uai = $this->establishmentRepository->toUai($etab);
        if (!empty($uai) && $uai != '0' && !str_contains($peopleRdn, $uai)) {
            $peopleRdn = "OU={$uai},{$peopleRdn}";
        }

        // Liste des OUs à créer dans l'ordre hiérarchique
        $ousToCreate = [];

        // 1. OU de base (People)
        $peopleDn = "{$peopleRdn},{$baseDn}";
        $peopleOuName = OrganizationalUnitModel::extractOuNameFromDn($peopleRdn);
        $ousToCreate[] = ['dn' => $peopleDn, 'name' => $peopleOuName];

        // 2. OU de catégorie (Eleves, Profs, etc.)
        if (!empty($categorie)) {
            $categorieDn = "OU={$categorie},{$peopleDn}";
            $ousToCreate[] = ['dn' => $categorieDn, 'name' => $categorie];

            // 3. OU de fonction/classe si présente
            if (!empty($fonction) && $fonction !== $categorie) {
                $fonctionDn = "OU={$fonction},{$categorieDn}";
                $ousToCreate[] = ['dn' => $fonctionDn, 'name' => $fonction];
            }
        }

        // Créer les OUs dans l'ordre (parents avant enfants)
        foreach ($ousToCreate as $ou) {
            try {
                $this->createWithParents($ou['dn'], $ou['name']);
            } catch (\Exception $e) {
                Log::warning("Erreur lors de la création de l'OU: {$ou['dn']}", [
                    'error' => $e->getMessage()
                ]);
                // Continuer même en cas d'erreur (l'OU existe peut-être déjà)
            }
        }
    }

    /**
     * Supprime une OU (attention: doit être vide)
     * 
     * @param string $dn
     * @return bool
     * @throws \LdapRecord\Exceptions\LdapRecordException
     */
    public function delete(string $dn): bool
    {
        $ou = $this->findByDn($dn);

        if (!$ou) {
            return false;
        }

        return $ou->deleteOU();
    }
}

