<?php

namespace App\Constants\Ldap;

/**
 * Constantes pour les attributs LDAP utilisés dans SambaEdu
 * 
 * Ces attributs correspondent aux différents types de recherche dans search_ad()
 * @see includes/ldap.inc.php:search_ad()
 */
class LdapAttributes
{
    // Attributs de base communs
    public const BASE = ['cn', 'dn', 'objectguid'];
    
    // Attributs pour les utilisateurs
    public const USER = [
        'cn',                    // login
        'displayname',           // Prenom Nom
        'sn',                    // Nom
        'givenname',             // Prenom
        'mail',                  // Mail
        'telephonenumber',       // Numéro téléphone
        'description',
        'physicaldeliveryofficename', // Date de naissance, Sexe (F/M) hash
        'title',                 // Numéro unique id ENT (OpenENT), externalId
        'employeenumber',        // Identifiants unique SIECLE et/ou GPEI, ASM... (séparés par des ,)
        'initials',              // pseudo
        'useraccountcontrol',    // État du compte actif : 512, désactivé 514
        'memberof',              // Groupes
        'userprincipalname',     // Pseudo-adresse mail correspondant au login ENT
        'objectguid',            // Identifiant unique à décoder avec to_guid()
        'pwdlastset',            // 0 => doit changer de mdp
        'accountexpires',        // Date d'expiration du compte en temps windows
    ];
    
    public const USER_MINIMAL = ['cn'];
    
    public const USER_MEMBEROF = [
        'cn',
        'displayname',
        'givenname',
        'sn',
        'physicaldeliveryofficename',
        'mail',
        'memberof',
        'employeenumber',
        'pwdlastset',
        'userprincipalname',
        'objectguid',
    ];
    
    // Attributs pour les groupes
    public const GROUP = [
        'cn',
        'description',
        'samaccountname',
        'member',
        'memberof',
        'info',
    ];
    
    public const GROUP_FAST = [
        'cn',
        'description',
        'samaccountname',
        'memberof',
        'info',
    ];
    
    // Attributs pour les parcs (Groups avec samaccountname)
    public const PARC = [
        'cn',
        'description',
        'member',
        'samaccountname',
        'objectguid',
    ];
    
    // Attributs pour les groupes de périphériques (OrganizationalUnit)
    public const SALLE = [
        'cn',
        'ou',
        'description',
        'objectguid',
    ];
    
    // Attributs pour les machines (computers)
    public const MACHINE = [
        'cn',                    // nom d'origine
        'samaccountname',        // Nom netbios type NOM$
        'dnsHostname',          // FDQN
        'location',             // Emplacement (prise murale)
        'lastlogon',            // Dernière connexion
        'description',          // Description de la machine
        'iphostnumber',         // Adresse IP réservée
        'networkaddress',      // Adresse MAC
        'memberof',             // Appartenance aux groupes
        'netbootguid',          // Action programmée (token/uuid pxe)
        'operatingsystem',      // Windows ou linux
        'operatingsystemversion', // Build version
        'objectguid',
    ];
    
    // Attributs pour les classes
    public const CLASSE = [
        'cn',
        'samaccountname',
        'description',
        'member',
    ];
    
    // Attributs pour les équipes
    public const EQUIPE = [
        'cn',
        'samaccountname',
        'description',
        'member',
        'memberof',
    ];
    
    // Attributs pour les cours
    public const COURS = [
        'cn',
        'samaccountname',
        'description',
        'member',
    ];
    
    // Attributs pour les projets
    public const PROJET = [
        'cn',
        'samaccountname',
        'description',
        'member',
    ];
    
    // Attributs pour les matières
    public const MATIERE = [
        'cn',
        'info',                  // Id GPEI
        'description',
        'member',
    ];
    
    // Attributs pour les établissements
    public const ETABLISSEMENT = [
        'cn',                    // UAI
        'info',                  // Id GPEI
        'displayname',           // nom
        'description',           // typeEtab nom ville
        'samaccountname',
        'member',
        'memberof',             // bassin
        'telephonenumber',       // phone
        'textencodedoraddress',  // coordonnées géographiques
    ];
    
    // Attributs pour les délégations
    public const DELEGATION = [
        'cn',
        'samaccountname',
        'info',
        'member',
        'memberof',
        'description',
    ];
    
    // Attributs pour les droits
    public const RIGHT = [
        'cn',
        'description',
        'member',
        'info',
    ];
    
    // Attributs pour les PP (Professeurs Principaux)
    public const PP = [
        'cn',
        'member',
    ];
    
    // Attributs pour les OU
    public const OU = [
        'ou',
        'description',
    ];
    
    // Attributs pour les DN
    public const DN = [
        'cn',
        'samaccountname',
        'memberof',
        'member',
        'description',
    ];
    
    // Attributs pour les filtres personnalisés
    public const FILTER = [
        'cn',
        'dn',
        'displayname',
        'description',
    ];
    
    // Attributs pour les imprimantes (obsolete)
    public const PRINTER = [
        'cn',
        'printername',
        'servername',
        'printattributes',
        'uncname',
    ];
    
    // Attributs pour les GPO
    public const GPO = [
        'cn',
        'displayname',
        'gpcfilesyspath',
        'versionnumber',
        'gpcuserextensionnames',
        'gpcmachineextensionnames',
        'gpcfunctionalityversion',
        'flags',
    ];
    
    // Attributs pour les sites
    public const SITE = [
        'cn',
        'description',
    ];
    
    // Attributs pour les sous-réseaux
    public const SUBNET = [
        'cn',                    // IP/CIDR
        'description',
        'siteobject',
        'location',              // n° de vlan
    ];
    
    // Attributs pour les site links
    public const SITELINK = [
        'cn',
        'description',
        'cost',
        'replinterval',
        'sitelist',
    ];
    
    // Attributs pour les containers
    public const CONTAINER = ['cn'];
    
    // Attributs pour les types d'objets
    public const TYPE = ['objectclass'];
    
    // Attributs pour les remote (RDP)
    public const REMOTE = [
        'cn',
        'member',
        'guacConfigProtocol',
        'guacConfigParameter',
    ];
}

