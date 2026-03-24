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

        $peopleDn = "{$peopleRdn},{$baseDn}";

        // Comme le legacy : créer séquentiellement les OUs nécessaires (parents d'abord)
        // 1. OU catégorie (ex: OU=Administratifs,ou=Utilisateurs,dc=...)
        if (!empty($categorie)) {
            $categorieDn = "OU={$categorie},{$peopleDn}";
            $this->createOuIfNotExists($categorieDn, $categorie);

            // 2. OU fonction si présente (ex: OU=Direction,OU=Administratifs,ou=Utilisateurs,dc=...)
            if (!empty($fonction) && $fonction !== $categorie) {
                $fonctionDn = "OU={$fonction},{$categorieDn}";
                $this->createOuIfNotExists($fonctionDn, $fonction);
            }
        }
    }

    /**
     * Crée une OU si elle n'existe pas — appel direct sans récursion
     * Reproduit le comportement de ouadd() du legacy
     */
    private function createOuIfNotExists(string $dn, string $ouName): void
    {
        if ($this->exists($dn)) {
            return;
        }

        try {
            $this->create($dn, $ouName);
            Log::info("OU créée: {$dn}");
        } catch (\LdapRecord\Exceptions\AlreadyExistsException $e) {
            // Race condition, pas grave
        } catch (\Exception $e) {
            Log::error("Échec création OU: {$dn}", ['error' => $e->getMessage()]);
            throw new \RuntimeException("Impossible de créer l'OU '{$ouName}' ({$dn}): " . $e->getMessage(), 0, $e);
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

