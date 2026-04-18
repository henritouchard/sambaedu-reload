<?php

declare(strict_types=1);

namespace App\Config;

use Illuminate\Support\Facades\Log;

/**
 * Configuration LDAP/Active Directory
 * 
 * Contient tous les paramètres nécessaires pour la connexion
 * et l'interrogation de l'annuaire LDAP/AD.
 * 
 * IMPORTANT: En mode strict (par défaut), seul l'AD de l'établissement est utilisé.
 * L'AD central n'est JAMAIS utilisé comme fallback pour éviter les connexions
 * non souhaitées à l'annuaire central.
 */
final readonly class LdapConfig
{
    public function __construct(
        /** URL complète du serveur LDAP (ex: ldaps://localdev.fr) */
        public string $url,

        /** Port LDAP (389 pour LDAP, 636 pour LDAPS) */
        public int $port,

        /** DN de base de l'annuaire (ex: dc=localdev,dc=fr) */
        public string $baseDn,

        /** Nom de l'administrateur LDAP */
        public string $adminName,

        /** Mot de passe de l'administrateur LDAP */
        public string $adminPassword,

        /** Domaine Active Directory (ex: localdev.fr) */
        public string $domain,

        /** Domaine Samba court (ex: localdev) */
        public string $sambaDomain,

        /** RDN pour les utilisateurs */
        public string $peopleRdn,

        /** RDN pour les groupes */
        public string $groupsRdn,

        /** RDN pour les ordinateurs */
        public string $computersRdn,

        /** RDN pour les parcs */
        public string $parcsRdn,

        /** RDN pour les classes */
        public string $classesRdn,

        /** RDN pour les équipes */
        public string $equipesRdn,

        /** RDN pour les matières */
        public string $matieresRdn,

        /** RDN pour les cours */
        public string $coursRdn,

        /** RDN pour les projets */
        public string $projetsRdn,

        /** RDN pour les autres groupes */
        public string $otherGroupsRdn,

        /** RDN pour les délégations */
        public string $delegationsRdn,

        /** RDN pour les équipements/matériels */
        public string $equipementsRdn,

        /** RDN pour les droits */
        public string $rightsRdn,

        /** RDN pour la corbeille */
        public string $trashRdn,

        /** RDN pour les établissements */
        public string $etablissementsRdn,

        /** RDN pour l'admin */
        public string $adminRdn,

        /** IP directe du serveur AD central (NE PAS UTILISER - uniquement pour référence) */
        public ?string $serverIp = null,

        /** IP directe du serveur AD de l'établissement (PRIORITAIRE) */
        public ?string $etabServerIp = null,

        /** Mode strict : interdire le fallback vers l'AD central */
        public bool $strictLocalAd = true,
    ) {
    }

    /**
     * Indique si la connexion utilise SSL (LDAPS)
     */
    public function useSsl(): bool
    {
        return str_starts_with(strtolower($this->url), 'ldaps://');
    }

    /**
     * Retourne le(s) host(s) pour la connexion LDAP
     * 
     * IMPORTANT: En mode strict (par défaut), utilise UNIQUEMENT l'AD de l'établissement.
     * L'AD central n'est JAMAIS utilisé comme fallback.
     * 
     * @return string[]
     * @throws \RuntimeException Si l'AD établissement n'est pas configuré en mode strict
     */
    public function getHosts(): array
    {
        // PRIORITÉ 1: AD de l'établissement (se4ad_etab_ip)
        if (!empty($this->etabServerIp)) {
            Log::debug('LdapConfig: Utilisation de l\'AD établissement', [
                'etab_ip' => $this->etabServerIp,
            ]);
            return [$this->etabServerIp];
        }

        // Mode strict : interdire le fallback vers l'AD central
        if ($this->strictLocalAd) {
            Log::error('LdapConfig: AD établissement non configuré et mode strict activé', [
                'etab_ip' => $this->etabServerIp,
                'central_ip' => $this->serverIp,
                'strict_mode' => $this->strictLocalAd,
            ]);
            throw new \RuntimeException(
                'AD établissement (se4ad_etab_ip) non configuré. ' .
                'En mode strict, la connexion à l\'AD central est interdite. ' .
                'Configurez SE4AD_ETAB_IP dans le fichier .env'
            );
        }

        // Mode non-strict : fallback vers AD central avec warning
        if (!empty($this->serverIp)) {
            Log::warning('LdapConfig: ATTENTION - Fallback vers AD central (non recommandé)', [
                'central_ip' => $this->serverIp,
                'reason' => 'etab_ip non configuré',
            ]);
            return [$this->serverIp];
        }
        
        // Dernier recours : extraire depuis l'URL
        Log::warning('LdapConfig: Extraction du host depuis l\'URL LDAP');
        $url = preg_replace('/^ldaps?:\/\//', '', $this->url);
        return array_filter(array_map('trim', explode(',', $url)));
    }

    /**
     * Vérifie si l'AD établissement est correctement configuré
     */
    public function hasLocalAd(): bool
    {
        return !empty($this->etabServerIp);
    }

    /**
     * Vérifie si on utilise l'AD central (situation à éviter)
     */
    public function isUsingCentralAd(): bool
    {
        return empty($this->etabServerIp) && !empty($this->serverIp);
    }

    /**
     * Construit le nom d'utilisateur complet pour l'authentification AD
     */
    public function getAdminUsername(): string
    {
        return $this->adminName . '@' . $this->domain;
    }

    /**
     * Construit le DN complet pour les établissements
     */
    public function etablissementsDn(): string
    {
        return $this->etablissementsRdn . ',' . $this->baseDn;
    }

    /**
     * Construit le DN d'un établissement spécifique
     */
    public function etablissementDn(string $code): string
    {
        return "CN={$code}," . $this->etablissementsDn();
    }

    /**
     * Construit le DN complet pour les utilisateurs
     */
    public function peopleDn(): string
    {
        return $this->peopleRdn . ',' . $this->baseDn;
    }

    /**
     * Construit le DN complet pour les groupes
     */
    public function groupsDn(): string
    {
        return $this->groupsRdn . ',' . $this->baseDn;
    }

    /**
     * Construit le DN complet pour les classes
     */
    public function classesDn(): string
    {
        return $this->classesRdn . ',' . $this->groupsRdn . ',' . $this->baseDn;
    }

    /**
     * Construit le DN complet pour la corbeille
     */
    public function trashDn(): string
    {
        return $this->trashRdn . ',' . $this->baseDn;
    }

    /**
     * Construit le DN complet pour les parcs
     */
    public function parcsDn(): string
    {
        return $this->parcsRdn . ',' . $this->baseDn;
    }

    /**
     * Construit le DN complet pour les droits
     */
    public function rightsDn(): string
    {
        return $this->rightsRdn . ',' . $this->baseDn;
    }

    /**
     * Retourne la configuration au format LdapRecord
     * 
     * @return array<string, mixed>
     */
    public function toLdapRecordConfig(): array
    {
        return [
            'hosts' => $this->getHosts(),
            'port' => $this->port,
            'base_dn' => $this->baseDn,
            'username' => $this->getAdminUsername(),
            'password' => $this->adminPassword,
            'timeout' => 60,
            'use_ssl' => $this->useSsl(),
            'use_tls' => false,
            'options' => [
                LDAP_OPT_PROTOCOL_VERSION => 3,
                LDAP_OPT_REFERRALS => 0,
                LDAP_OPT_NETWORK_TIMEOUT => 60,
            ],
        ];
    }
}
