<?php

namespace App\LdapModels;

use LdapRecord\Models\Model;
use LdapRecord\Models\Attributes\DistinguishedName;
use Illuminate\Support\Facades\Log;

/**
 * Modèle LdapRecord pour les Unités Organisationnelles (OU)
 * Représente une Organizational Unit dans Active Directory
 */
class OrganizationalUnitModel extends Model
{
    /**
     * The object classes of the LDAP model.
     */
    public static array $objectClasses = [
        'top',
        'organizationalUnit',
    ];

    /**
     * Les attributs qui peuvent être définis en masse
     */
    protected $fillable = [
        'ou',
        'description',
        'name',
    ];

    /**
     * Recherche une OU par son DN
     * 
     * @param string $dn Le Distinguished Name de l'OU
     * @return static|null
     */
    public static function findByDn(string $dn): ?static
    {
        try {
            return static::findByDnOrFail($dn);
        } catch (\Exception $e) {
            Log::debug("OU non trouvée: $dn", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Vérifie si une OU existe
     * 
     * @param string $dn Le Distinguished Name de l'OU
     * @return bool
     */
    public static function exists(string $dn): bool
    {
        try {
            static::findByDnOrFail($dn);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Crée une nouvelle OU
     * 
     * @param string $dn Le Distinguished Name complet de l'OU
     * @param string $ouName Le nom de l'OU (valeur de l'attribut 'ou')
     * @param string|null $description Description optionnelle
     * @return static
     * @throws \LdapRecord\Exceptions\LdapRecordException
     */
    public static function createOU(string $dn, string $ouName, ?string $description = null): static
    {
        $ou = new static();
        $ou->setDn($dn);
        $ou->ou = [$ouName];
        
        if ($description) {
            $ou->description = [$description];
        }
        
        Log::info("Création de l'OU avec LdapRecord", [
            'dn' => $dn,
            'ou_name' => $ouName,
        ]);
        
        $ou->save();
        
        return $ou;
    }

    /**
     * Crée une OU si elle n'existe pas déjà
     * 
     * @param string $dn Le Distinguished Name complet de l'OU
     * @param string $ouName Le nom de l'OU
     * @param string|null $description Description optionnelle
     * @return static
     */
    public static function createIfNotExists(string $dn, string $ouName, ?string $description = null): static
    {
        // Vérifier si l'OU existe déjà
        $existing = static::findByDn($dn);
        
        if ($existing) {
            Log::debug("OU déjà existante: $dn");
            return $existing;
        }
        
        // Créer l'OU
        try {
            return static::createOU($dn, $ouName, $description);
        } catch (\LdapRecord\Exceptions\AlreadyExistsException $e) {
            // Si l'OU existe déjà (race condition), la récupérer
            Log::debug("OU créée entre-temps: $dn");
            return static::findByDn($dn) ?? static::createOU($dn, $ouName, $description);
        }
    }

    /**
     * Extrait le nom de l'OU depuis un DN
     * Exemple: "OU=Eleves,OU=People,DC=example,DC=com" => "Eleves"
     * 
     * @param string $dn
     * @return string
     */
    public static function extractOuNameFromDn(string $dn): string
    {
        $parts = DistinguishedName::make($dn)->components();
        
        foreach ($parts as $part) {
            if (stripos($part, 'OU=') === 0) {
                return substr($part, 3);
            }
        }
        
        return '';
    }

    /**
     * Extrait le DN parent depuis un DN
     * Exemple: "OU=Eleves,OU=People,DC=example,DC=com" => "OU=People,DC=example,DC=com"
     * 
     * @param string $dn
     * @return string|null
     */
    public static function extractParentDn(string $dn): ?string
    {
        $parts = DistinguishedName::make($dn)->components();
        
        if (count($parts) <= 1) {
            return null;
        }
        
        // Retirer le premier composant
        array_shift($parts);
        
        return implode(',', $parts);
    }

    /**
     * Crée une hiérarchie d'OUs en créant les parents si nécessaire
     * 
     * @param string $dn Le DN complet de l'OU à créer
     * @param string $ouName Le nom de l'OU
     * @return static
     */
    public static function createWithParents(string $dn, string $ouName): static
    {
        // Extraire le DN parent
        $parentDn = static::extractParentDn($dn);
        
        // Si on a un parent et qu'il ne s'agit pas d'un DC (base DN)
        if ($parentDn && stripos($parentDn, 'OU=') !== false) {
            // Vérifier si le parent existe
            if (!static::exists($parentDn)) {
                // Extraire le nom du parent
                $parentOuName = static::extractOuNameFromDn($parentDn);
                
                Log::info("Création récursive du parent: $parentDn");
                
                // Créer le parent récursivement
                static::createWithParents($parentDn, $parentOuName);
            }
        }
        
        // Créer l'OU courante
        return static::createIfNotExists($dn, $ouName);
    }

    /**
     * Supprime une OU (attention: doit être vide)
     * 
     * @return bool
     * @throws \LdapRecord\Exceptions\LdapRecordException
     */
    public function deleteOU(): null
    {
        Log::info("Suppression de l'OU", [
            'dn' => $this->getDn()
        ]);
        
        return $this->delete();
    }
}


