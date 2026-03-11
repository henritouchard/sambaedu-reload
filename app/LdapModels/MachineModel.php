<?php

namespace App\LdapModels;

use LdapRecord\Models\ActiveDirectory\Computer as BaseComputer;
use App\Constants\Ldap\LdapAttributes;
use Illuminate\Support\Facades\Log;

/**
 * Modèle LdapRecord pour les machines (computers)
 * 
 * Représente une machine/ordinateur dans Active Directory
 */
class MachineModel extends BaseComputer
{
    /**
     * Les attributs à retourner dans les résultats
     * Correspond aux attributs utilisés dans search_ad() pour le type "machine"
     */
    protected array $columns = [
        'cn',                    // nom d'origine
        'samaccountname',        // Nom netbios type NOM$
        'dnsHostname',           // FDQN
        'location',              // Emplacement (prise murale)
        'lastlogon',             // Dernière connexion
        'description',           // Description de la machine
        'iphostnumber',          // Adresse IP réservée
        'networkaddress',        // Adresse MAC
        'memberof',              // Appartenance aux groupes
        'netbootguid',           // Action programmée (token/uuid pxe)
        'operatingsystem',        // Windows
        'operatingsystemversion', // Build version
        'objectguid',
    ];
    
    /**
     * Le DN de base pour ce type d'objet
     * Utilise la configuration SambaEdu pour déterminer le DN des machines
     * 
     * @return string
     */
    public static function baseDn(): string
    {
        // Utiliser LdapDnHelper pour construire le DN des computers
        // Filtre par établissement courant par défaut
        $dnHelper = app(\App\Config\LdapDnHelper::class);
        return $dnHelper->computers();
    }
    
    // ============================================
    // ACCESSEURS SÉMANTIQUES - Masquent la complexité LDAP
    // ============================================
    
    /**
     * Récupère le nom de la machine (équivalent à cn en LDAP)
     * 
     * @return string
     */
    public function getMachineName(): string
    {
        $cn = $this->getAttribute('cn', '');
        return is_array($cn) ? ($cn[0] ?? '') : (string) $cn;
    }
    
    /**
     * Accesseur magique pour $machine->name
     * Note: getName() existe déjà dans le modèle de base, on utilise un accesseur magique
     */
    public function getNameAttribute(): string
    {
        return $this->getMachineName();
    }
    
    /**
     * Récupère le hostname de la machine (équivalent à samaccountname sans $)
     * 
     * @return string
     */
    public function getHostname(): string
    {
        $hostname = $this->getAttribute('samaccountname', '');
        $hostname = is_array($hostname) ? ($hostname[0] ?? '') : (string) $hostname;
        // Retirer le $ à la fin si présent
        return str_replace('$', '', strtolower($hostname));
    }
    
    /**
     * Accesseur magique pour $machine->hostname
     */
    public function getHostnameAttribute(): string
    {
        return $this->getHostname();
    }
    
    /**
     * Récupère l'adresse IP de la machine (équivalent à iphostnumber en LDAP)
     * 
     * @return string|null
     */
    public function getIpAddress(): ?string
    {
        $ip = $this->getAttribute('iphostnumber');
        if ($ip === null) {
            return null;
        }
        return is_array($ip) ? ($ip[0] ?? null) : (string) $ip;
    }
    
    /**
     * Accesseur magique pour $machine->ip_address
     */
    public function getIpAddressAttribute(): ?string
    {
        return $this->getIpAddress();
    }
    
    /**
     * Récupère l'adresse MAC de la machine (équivalent à networkaddress en LDAP)
     * 
     * @return string|null
     */
    public function getMacAddress(): ?string
    {
        $mac = $this->getAttribute('networkaddress');
        if ($mac === null) {
            return null;
        }
        return is_array($mac) ? ($mac[0] ?? null) : (string) $mac;
    }
    
    /**
     * Accesseur magique pour $machine->mac_address
     */
    public function getMacAddressAttribute(): ?string
    {
        return $this->getMacAddress();
    }
    
    /**
     * Récupère le système d'exploitation (équivalent à operatingsystem en LDAP)
     * 
     * @return string|null
     */
    public function getOperatingSystem(): ?string
    {
        $os = $this->getAttribute('operatingsystem');
        if ($os === null) {
            return null;
        }
        return is_array($os) ? ($os[0] ?? null) : (string) $os;
    }
    
    /**
     * Accesseur magique pour $machine->operating_system
     */
    public function getOperatingSystemAttribute(): ?string
    {
        return $this->getOperatingSystem();
    }
    
    /**
     * Récupère la version du système d'exploitation (équivalent à operatingsystemversion en LDAP)
     * 
     * @return string|null
     */
    public function getOperatingSystemVersion(): ?string
    {
        $version = $this->getAttribute('operatingsystemversion');
        if ($version === null) {
            return null;
        }
        return is_array($version) ? ($version[0] ?? null) : (string) $version;
    }
    
    /**
     * Accesseur magique pour $machine->operating_system_version
     */
    public function getOperatingSystemVersionAttribute(): ?string
    {
        return $this->getOperatingSystemVersion();
    }
    
    /**
     * Récupère l'emplacement de la machine (équivalent à location en LDAP)
     * 
     * @return string|null
     */
    public function getLocation(): ?string
    {
        $location = $this->getAttribute('location');
        if ($location === null) {
            return null;
        }
        return is_array($location) ? ($location[0] ?? null) : (string) $location;
    }
    
    /**
     * Accesseur magique pour $machine->location
     */
    public function getLocationAttribute(): ?string
    {
        return $this->getLocation();
    }
    
    /**
     * Récupère la description de la machine (équivalent à description en LDAP)
     * 
     * @return string|null
     */
    public function getDescription(): ?string
    {
        $description = $this->getAttribute('description');
        if ($description === null) {
            return null;
        }
        return is_array($description) ? ($description[0] ?? null) : (string) $description;
    }
    
    /**
     * Accesseur magique pour $machine->description
     */
    public function getDescriptionAttribute(): ?string
    {
        return $this->getDescription();
    }
    
    /**
     * Récupère le statut de la machine sous forme de string sémantique
     * 
     * @return string 'active' ou 'disabled'
     */
    public function getStatus(): string
    {
        return $this->isActive() ? 'active' : 'disabled';
    }
    
    /**
     * Accesseur magique pour $machine->status
     */
    public function getStatusAttribute(): string
    {
        return $this->getStatus();
    }
    
    /**
     * Récupère les parcs sous forme de noms simples (pas de DN)
     * 
     * @return array Liste des noms de parcs
     */
    public function getParcs(): array
    {
        $memberOf = $this->getAttribute('memberof', []);
        if (!is_array($memberOf)) {
            $memberOf = [$memberOf];
        }
        
        $parcs = [];
        foreach ($memberOf as $dn) {
            if (preg_match('/^CN=([^,]+),/', $dn, $matches)) {
                $parcs[] = $matches[1];
            }
        }
        
        return $parcs;
    }
    
    /**
     * Accesseur magique pour $machine->parcs
     */
    public function getParcsAttribute(): array
    {
        return $this->getParcs();
    }
    
    /**
     * Récupère le nom complet DNS (équivalent à dnsHostname en LDAP)
     * 
     * @return string|null
     */
    public function getDnsHostname(): ?string
    {
        $hostname = $this->getAttribute('dnshostname');
        if ($hostname === null) {
            return null;
        }
        return is_array($hostname) ? ($hostname[0] ?? null) : (string) $hostname;
    }
    
    /**
     * Accesseur magique pour $machine->dns_hostname
     */
    public function getDnsHostnameAttribute(): ?string
    {
        return $this->getDnsHostname();
    }
    
    /**
     * Vérifie si la machine est active
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        $uac = $this->getAttribute('useraccountcontrol', 0);
        
        // 4096 = compte machine activé
        // 4098 = compte machine désactivé
        return ($uac == 4096);
    }
    
    /**
     * Relation : salle (OU/DeviceGroup) dans laquelle se trouve la machine
     * 
     * La salle est l'OU parent directement au-dessus de la machine dans le DN
     * 
     * @return DeviceGroupModel|null
     */
    public function salle(): ?DeviceGroupModel
    {
        try {
            // Extraire l'OU parent depuis le DN de la machine
            $dn = $this->getDn();
            
            // Format DN: CN=MachineName,OU=Salle,OU=Computers,DC=...
            // On veut récupérer OU=Salle,OU=Computers,DC=...
            if (preg_match('/^CN=[^,]+,(.+)$/', $dn, $matches)) {
                $ouDn = $matches[1];
                
                // Vérifier que c'est bien une OU (commence par OU=)
                if (preg_match('/^OU=/i', $ouDn)) {
                    return DeviceGroupModel::findByDn($ouDn);
                }
            }
            
            return null;
        } catch (\Exception $e) {
            Log::warning('Erreur lors de la récupération de la salle', [
                'machine_dn' => $this->getDn(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Relation : parcs (DeviceGroupTag) dont la machine est membre
     * 
     * Les parcs sont des groupes (DeviceGroupTagModel) dans OU=Parcs
     * La machine est membre de ces groupes via l'attribut memberof
     * 
     * @return \LdapRecord\Models\Collection Collection de DeviceGroupTagModel
     */
    public function parcs(): \LdapRecord\Models\Collection
    {
        try {
            $memberOf = $this->getAttribute('memberof', []);
            
            if (empty($memberOf)) {
                return new \LdapRecord\Models\Collection();
            }
            
            // Normaliser en tableau
            if (!is_array($memberOf)) {
                $memberOf = [$memberOf];
            }
            
            // Retirer la clé 'count' si présente
            if (isset($memberOf['count'])) {
                unset($memberOf['count']);
            }
            
            // Filtrer pour ne garder que les groupes qui sont dans OU=Parcs
            $baseDnParcs = DeviceGroupTagModel::baseDn();
            $parcDns = array_filter($memberOf, function($dn) use ($baseDnParcs) {
                if (!is_string($dn)) {
                    return false;
                }
                // Vérifier que le DN contient le baseDn des parcs
                return stripos($dn, $baseDnParcs) !== false;
            });
            
            if (empty($parcDns)) {
                return new \LdapRecord\Models\Collection();
            }
            
            // Récupérer les DeviceGroupTagModel correspondants
            $parcs = DeviceGroupTagModel::in($baseDnParcs)
                ->whereIn('distinguishedname', array_values($parcDns))
                ->get();
            
            return $parcs;
        } catch (\Exception $e) {
            Log::warning('Erreur lors de la récupération des parcs', [
                'machine_dn' => $this->getDn(),
                'error' => $e->getMessage()
            ]);
            return new \LdapRecord\Models\Collection();
        }
    }
    
    /**
     * @deprecated Utiliser parcs() qui retourne maintenant DeviceGroupTagModel
     * Relation : parcs dont la machine est membre (ancienne version)
     * 
     * @return \LdapRecord\Models\Relations\HasMany
     */
    public function parcsLegacy()
    {
        // Les parcs sont des groupes, utiliser le modèle Group de base
        return $this->hasMany(\LdapRecord\Models\ActiveDirectory\Group::class, 'distinguishedname', 'memberof');
    }
    
    /**
     * Recherche une machine par son nom (cn)
     * 
     * @param string $name
     * @return static|null
     */
    public static function findByName(string $name): ?static
    {
        return static::where('cn', '=', $name)->first();
    }
    
    /**
     * Recherche une machine par son hostname
     * 
     * @param string $hostname
     * @return static|null
     */
    public static function findByHostname(string $hostname): ?static
    {
        // Normaliser le hostname (ajouter $ si nécessaire)
        $samaccountname = str_ends_with($hostname, '$') ? $hostname : $hostname . '$';
        
        return static::where('samaccountname', '=', $samaccountname)->first();
    }
    
    /**
     * Recherche une machine par son adresse IP
     * 
     * @param string $ip
     * @return static|null
     */
    public static function findByIp(string $ip): ?static
    {
        return static::where('iphostnumber', '=', $ip)->first();
    }
    
    /**
     * Recherche une machine par son adresse MAC
     * 
     * @param string $mac
     * @return static|null
     */
    public static function findByMac(string $mac): ?static
    {
        return static::where('networkaddress', '=', $mac)->first();
    }
}

