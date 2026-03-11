<?php

namespace App\LdapModels;

use LdapRecord\Models\ActiveDirectory\Group as BaseGroup;
use App\Constants\Ldap\MainGroups;

/**
 * Modèle LdapRecord pour les groupes Active Directory SambaEdu
 * 
 * Représente un groupe dans Active Directory
 * Étend le modèle Group de LdapRecord pour ajouter des fonctionnalités spécifiques à SambaEdu
 */
class SambaEduGroup extends BaseGroup
{
    /**
     * Les attributs à retourner dans les résultats
     */
    protected array $columns = [
        'cn',                    // Nom du groupe
        'description',           // Description
        'samaccountname',        // Nom SAM du groupe
        'member',                // Membres du groupe
        'memberof',              // Groupes dont ce groupe est membre
        'info',                  // Informations additionnelles
        'grouptype',             // Type de groupe
    ];
    
    /**
     * Le DN de base pour ce type d'objet
     * Utilise la configuration SambaEdu pour déterminer le DN des groupes
     * 
     * @return string
     */
    public static function baseDn(): string
    {
        // Récupérer le DN depuis la configuration
        $baseDnGroups = config('sambaedu.ldap.base_dn_groups');
        
        if (!empty($baseDnGroups)) {
            return $baseDnGroups;
        }
        
        // Fallback vers le base_dn général
        return config('sambaedu.ldap.base_dn', '');
    }
    
    /**
     * Récupère un groupe principal par son nom
     * 
     * @param string $groupName Un des groupes principaux (Eleves, Profs, Administratifs)
     * @return SambaEduGroup|null
     */
    public static function findMainGroup(string $groupName): ?self
    {
        if (!MainGroups::isMainGroup($groupName)) {
            return null;
        }
        
        return static::query()
            ->where('cn', '=', $groupName)
            ->first();
    }
    
    /**
     * Récupère tous les groupes principaux
     * 
     * @return \Illuminate\Support\Collection Collection de SambaEduGroup
     */
    public static function findAllMainGroups(): \Illuminate\Support\Collection
    {
        return static::query()
            ->whereIn('cn', MainGroups::all())
            ->get();
    }
    
    /**
     * Récupère le DN d'un groupe principal par son nom
     * 
     * @param string $groupName Un des groupes principaux (Eleves, Profs, Administratifs)
     * @return string|null Le DN du groupe ou null si non trouvé
     */
    public static function getMainGroupDn(string $groupName): ?string
    {
        $group = self::findMainGroup($groupName);
        
        return $group ? $group->getDn() : null;
    }
    
    /**
     * Récupère les DN de tous les groupes principaux
     * 
     * @return array Tableau associatif ['Eleves' => 'CN=Eleves,...', ...]
     */
    public static function getAllMainGroupsDn(): array
    {
        $groups = self::findAllMainGroups();
        $result = [];
        
        foreach ($groups as $group) {
            $cn = $group->getAttribute('cn');
            
            // Normaliser le CN (peut être un tableau ou une chaîne)
            if (is_array($cn)) {
                $cn = !empty($cn) ? (string)$cn[0] : null;
            } else {
                $cn = $cn ? (string)$cn : null;
            }
            
            if ($cn && MainGroups::isMainGroup($cn)) {
                $result[$cn] = $group->getDn();
            }
        }
        
        return $result;
    }
    
    /**
     * Recherche un groupe par son CN
     * 
     * @param string $cn Le CN du groupe
     * @return static|null
     */
    public static function findByCn(string $cn): ?static
    {
        return static::query()
            ->where('cn', '=', $cn)
            ->first();
    }
    
    /**
     * Normalise une valeur LDAP qui peut être un tableau ou une chaîne
     * 
     * @param mixed $value
     * @return string|null
     */
    private static function normalizeLdapValue($value): ?string
    {
        if (is_array($value)) {
            return !empty($value) ? (string)$value[0] : null;
        }
        
        return $value !== null ? (string)$value : null;
    }
}

