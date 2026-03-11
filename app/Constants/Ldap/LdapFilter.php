<?php

namespace App\Constants\Ldap;

/**
 * Constantes pour les filtres LDAP utilisés dans SambaEdu
 * 
 * Ces filtres correspondent aux différents types de recherche dans search_ad()
 * @see includes/ldap.inc.php:search_ad()
 */
class LdapFilter
{
    // Filtres pour les utilisateurs
    public const USER_ALL = '(&(objectclass=user)(!(objectclass=computer)))';
    public const USER_BY_CN = '(&(objectclass=user)(!(objectclass=computer))(cn=%s))';
    public const USER_BY_EMPLOYEENUMBER = '(&(objectclass=user)(!(objectclass=computer))(|(title=%s)(title=%05d)(title=%s)))';
    public const USER_BY_USERPRINCIPALNAME = '(&(objectclass=user)(|(cn=%s)(userprincipalname=%s@%s)(userprincipalname=%s@%s)))';
    public const USER_MEMBEROF = '(&(objectclass=user)(!(objectclass=computer))(memberof=%s))';
    
    // Filtres pour les groupes
    public const GROUP_ALL = '(objectclass=group)';
    public const GROUP_BY_CN = '(&(objectclass=group)(cn=%s))';
    public const GROUP_BY_SAMACCOUNTNAME = '(&(objectclass=group)(samaccountname=%s))';
    public const GROUP_BY_CN_OR_SAM = '(&(objectclass=group)(|(samaccountname=%s)(cn=%s)))';
    
    // Filtres pour les machines (computers)
    public const COMPUTER_ALL = '(objectclass=computer)';
    public const COMPUTER_BY_NAME = '(&(objectclass=computer)(|(cn=%s)(samaccountname=%s)(samaccountname=%s$)(dnshostname=%s.%s)(dnshostname=%s)(iphostnumber=%s)(networkaddress=%s)(netbootguid=%s)(dn=%s)))';
    public const COMPUTER_BY_MEMBEROF = '(&(objectclass=computer)(|(memberof=%s)))';
    
    // Filtres pour les groupes de périphériques (OrganizationalUnit)
    public const SALLE_BY_OU = '(&(objectclass=organizationalunit)(ou=%s))';
    
    // Filtres pour les parcs (Groups avec samaccountname)
    public const PARC_BY_SAMACCOUNTNAME = '(&(objectclass=group)(samaccountname=%s))';
    
    // Filtres pour les classes
    public const CLASSE_ALL = '(objectclass=group)';
    public const CLASSE_BY_NAME = '(&(objectclass=group)(cn=Classe_%s))';
    
    // Filtres pour les équipes
    public const EQUIPE_ALL = '(objectclass=group)';
    public const EQUIPE_BY_NAME = '(&(objectclass=group)(cn=Equipe_%s))';
    
    // Filtres pour les cours
    public const COURS_ALL = '(objectclass=group)';
    public const COURS_BY_NAME = '(&(objectclass=group)(cn=Cours_%s))';
    
    // Filtres pour les projets
    public const PROJET_ALL = '(objectclass=group)';
    public const PROJET_BY_NAME = '(&(objectclass=group)(cn=Projet_%s))';
    
    // Filtres pour les matières
    public const MATIERE_ALL = '(objectclass=group)';
    public const MATIERE_BY_NAME = '(&(|(info=%s)(cn=%s))(objectclass=group))';
    public const MATIERE_BY_EMAIL = '(&(cn=Matiere_%s)(objectclass=group))';
    
    // Filtres pour les établissements
    public const ETABLISSEMENT_ALL = '(objectclass=group)';
    public const ETABLISSEMENT_BY_NAME = '(&(objectclass=group)(|(cn=%s)(info=%s)))';
    
    // Filtres pour les délégations
    public const DELEGATION_ALL = '(objectclass=group)';
    public const DELEGATION_BY_NAME = '(&(objectclass=group)(|(samaccountname=%s)(cn=%s)(cn=no_%s)))';
    public const DELEGATION_BY_DN = '(&(objectclass=group)(|(cn=%s)(member=%s)))';
    
    // Filtres pour les droits
    public const RIGHT_ALL = '(objectclass=group)';
    public const RIGHT_BY_NAME = '(&(objectclass=group)(|(samaccountname=%s)(cn=%s)(cn=no_%s)))';
    public const RIGHT_BY_DN = '(&(objectclass=group)(|(cn=%s)(member=%s)))';
    
    // Filtres pour les PP (Professeurs Principaux)
    public const PP_ALL = '(&(objectclass=group)(cn=PP_*))';
    public const PP_BY_NAME = '(&(objectclass=group)(cn=PP_%s))';
    
    // Filtres pour les OU
    public const OU_BY_CN = '(&(objectclass=organizationalunit)(cn=%s))';
    
    // Filtres pour les imprimantes (obsolete)
    public const PRINTER_ALL = '(objectclass=msPrint-ConnectionPolicy)';
    public const PRINTER_BY_NAME = '(&(objectclass=msPrint-ConnectionPolicy)(|(printername=%s)(cn=%s)))';
    
    // Filtres pour les GPO
    public const GPO_ALL = '(objectclass=grouppolicycontainer)';
    public const GPO_BY_NAME = '(&(objectclass=grouppolicycontainer)(|(cn=%s)(displayname=%s)))';
    
    // Filtres pour les sites
    public const SITE_ALL = '(objectclass=site)';
    public const SITE_BY_NAME = '(&(objectclass=site)(cn=%s))';
    
    // Filtres pour les sous-réseaux
    public const SUBNET_ALL = '(objectclass=Subnet)';
    public const SUBNET_BY_NAME = '(&(objectclass=Subnet)(|(cn=%s)(siteobject=CN=%s,CN=Sites,CN=Configuration,%s)))';
    
    // Filtres pour les site links
    public const SITELINK_ALL = '(objectclass=sitelink)';
    public const SITELINK_BY_NAME = '(&(objectclass=sitelink)(cn=%s))';
    
    // Filtres pour les containers
    public const CONTAINER_ALL = '(objectclass=container)';
    public const CONTAINER_BY_CN = '(&(objectclass=container)(cn=%s))';
    
    // Filtres pour les types d'objets
    public const TYPE_ALL = '(|(objectclass=group)(objectclass=person)(objectclass=computer)(objectclass=organizationalunit))';
    
    // Filtre par défaut
    public const DEFAULT = '(cn=%s)';
    
    /**
     * Construit un filtre avec des paramètres
     * 
     * @param string $filter Le filtre avec des placeholders %s
     * @param mixed ...$args Les arguments à substituer
     * @return string
     */
    public static function build(string $filter, ...$args): string
    {
        return sprintf($filter, ...$args);
    }
}

