<?php

namespace App\Constants\Ldap;

/**
 * Constantes pour les scopes LDAP utilisés dans SambaEdu
 * 
 * Les scopes définissent la profondeur de recherche dans l'arborescence LDAP
 * @see includes/ldap.inc.php:search_ad() - paramètre $scope
 */
class LdapScope
{
    /**
     * Recherche dans le sous-arbre complet (subtree)
     * Utilise ldap_search() - recherche récursive dans toute la branche
     */
    public const SUBTREE = 'subtree';
    
    /**
     * Recherche au niveau de base uniquement (base)
     * Utilise ldap_list() - recherche uniquement au niveau spécifié, sans récursion
     */
    public const BASE = 'base';
    
    /**
     * Recherche au niveau un seul niveau (onelevel)
     * Utilise ldap_list() - recherche uniquement les enfants directs, sans récursion
     */
    public const ONELEVEL = 'onelevel';
    
    /**
     * Valeur par défaut utilisée dans search_ad()
     */
    public const DEFAULT = self::SUBTREE;
    
    /**
     * Liste de tous les scopes valides
     */
    public const ALL = [
        self::SUBTREE,
        self::BASE,
        self::ONELEVEL,
    ];
    
    /**
     * Vérifie si un scope est valide
     * 
     * @param string $scope
     * @return bool
     */
    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::ALL, true);
    }
}

