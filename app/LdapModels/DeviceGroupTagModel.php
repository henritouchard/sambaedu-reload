<?php

namespace App\LdapModels;

use App\Config\LdapDnHelper;
use LdapRecord\Models\ActiveDirectory\Group as BaseGroup;
use App\Constants\Ldap\LdapAttributes;

/**
 * Modèle LdapRecord pour les parcs (Groups avec samaccountname)
 * 
 * Représente un parc de machines dans Active Directory
 * Un parc est un groupe LDAP avec un samaccountname (ex: SALLE-INFO-101$)
 */
class DeviceGroupTagModel extends BaseGroup
{
    /**
     * Les attributs à retourner dans les résultats
     * Correspond aux attributs utilisés dans search_ad() pour le type "parc"
     */
    protected array $columns = [
        'cn',
        'description',
        'member',
        'samaccountname',
        'objectguid',
    ];

    /**
     * Le DN de base pour ce type d'objet
     * Utilise la configuration SambaEdu pour déterminer le DN des parcs
     * Les parcs sont dans ou=Parcs directement sous la base DN
     * 
     * @return string
     */
    public static function baseDn(): string
    {
        return LdapDnHelper::parcsDn();
    }

    /**
     * Récupère les machines membres du parc
     * 
     * Optimisé pour éviter de charger tous les membres en mémoire.
     * Utilise les DN des membres pour faire une requête ciblée sur les machines.
     * 
     * @param int $limit Limite le nombre de machines retournées (défaut: 100)
     * @return \LdapRecord\Models\Collection
     */
    public function machines(int $limit = 100): \LdapRecord\Models\Collection
    {
        // Récupérer les DN des membres depuis l'attribut member (déjà chargé)
        $memberDns = $this->getMachineNames();

        if (empty($memberDns)) {
            return new \LdapRecord\Models\Collection();
        }

        // Limiter le nombre de machines pour éviter les problèmes de mémoire
        $memberDns = array_slice($memberDns, 0, $limit);

        // Rechercher les machines par leur CN
        $baseDn = MachineModel::baseDn();
        return MachineModel::in($baseDn)
            ->whereIn('cn', $memberDns)
            ->limit($limit)
            ->get();
    }

    /**
     * Relation : groupe associé (OU/DeviceGroup)
     * 
     * Un tag (parc de type "parc") peut être associé à un groupe (parc de type "salle")
     * Le samaccountname du tag correspond au nom (ou) du groupe
     * 
     * @return DeviceGroupModel|null
     */
    public function associatedGroup(): ?DeviceGroupModel
    {
        $samAccountName = $this->getSamAccountName();
        if (empty($samAccountName)) {
            return null;
        }

        // Retirer le $ à la fin si présent (format samaccountname)
        $groupName = str_replace('$', '', $samAccountName);

        // Chercher l'OU avec le même nom que le samaccountname du tag
        $baseDn = DeviceGroupModel::baseDn();
        return DeviceGroupModel::in($baseDn)
            ->where('ou', '=', $groupName)
            ->first();
    }

    // ============================================
    // ACCESSEURS SÉMANTIQUES - Masquent la complexité LDAP
    // ============================================

    /**
     * Récupère un attribut de manière sécurisée depuis les données déjà chargées
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function getAttributeSafe(string $key, $default = null)
    {
        // Utiliser les attributs déjà chargés pour éviter les requêtes LDAP supplémentaires
        $attributes = $this->getAttributes();
        $value = $attributes[$key] ?? $default;

        // LDAP peut retourner un tableau pour les attributs multi-valeurs
        if (is_array($value) && isset($value[0])) {
            return $value[0];
        }

        return $value;
    }

    /**
     * Récupère le nom du parc (équivalent à cn en LDAP)
     * 
     * @return string
     */
    public function getParcName(): string
    {
        $cn = $this->getAttributeSafe('cn', '');
        return (string) $cn;
    }

    /**
     * Accesseur magique pour $parc->name
     * Note: getName() existe déjà dans le modèle de base, on utilise un accesseur magique
     */
    public function getNameAttribute(): string
    {
        return $this->getParcName();
    }

    /**
     * Récupère la description du parc (équivalent à description en LDAP)
     * 
     * @return string|null
     */
    public function getDescription(): ?string
    {
        $description = $this->getAttributeSafe('description');
        return $description ? (string) $description : null;
    }

    /**
     * Accesseur magique pour $parc->description
     */
    public function getDescriptionAttribute(): ?string
    {
        return $this->getDescription();
    }

    /**
     * Récupère le samaccountname du parc (équivalent à samaccountname en LDAP)
     * 
     * @return string|null
     */
    public function getSamAccountName(): ?string
    {
        $sam = $this->getAttributeSafe('samaccountname');
        return $sam ? (string) $sam : null;
    }

    /**
     * Accesseur magique pour $parc->sam_account_name
     */
    public function getSamAccountNameAttribute(): ?string
    {
        return $this->getSamAccountName();
    }

    /**
     * Récupère les machines membres sous forme de noms simples
     * 
     * @return array Liste des noms de machines
     */
    public function getMachineNames(): array
    {
        $members = $this->getAttributeSafe('member', []);

        // Si member n'est pas défini ou vide, retourner un tableau vide
        if (empty($members)) {
            return [];
        }

        // Normaliser en tableau
        if (!is_array($members)) {
            $members = [$members];
        }

        // Retirer la clé 'count' si présente (format LDAP natif)
        if (isset($members['count'])) {
            unset($members['count']);
        }

        $machineNames = [];
        foreach ($members as $dn) {
            if (is_string($dn) && preg_match('/^CN=([^,]+),/', $dn, $matches)) {
                $machineNames[] = $matches[1];
            }
        }

        return $machineNames;
    }

    /**
     * Accesseur magique pour $parc->machine_names
     */
    public function getMachineNamesAttribute(): array
    {
        return $this->getMachineNames();
    }

    /**
     * Récupère le nombre de machines dans le parc
     * 
     * Optimisé pour éviter de traiter tous les membres si on veut juste le count
     * 
     * @return int
     */
    public function getMachineCount(): int
    {
        $members = $this->getAttributeSafe('member', []);

        if (empty($members)) {
            return 0;
        }

        // Si c'est un tableau avec une clé 'count', l'utiliser directement
        if (\is_array($members) && isset($members['count'])) {
            return (int) $members['count'];
        }

        // Sinon, compter les éléments
        if (!\is_array($members)) {
            return 1; // Un seul membre
        }

        // Retirer la clé 'count' si présente
        $filtered = \array_filter(\array_keys($members), fn($k) => $k !== 'count');
        return \count($filtered);
    }

    /**
     * Accesseur magique pour $parc->machine_count
     */
    public function getMachineCountAttribute(): int
    {
        return $this->getMachineCount();
    }

    /**
     * Recherche un parc par son samaccountname
     * 
     * @param string $samaccountname
     * @return static|null
     */
    public static function findBySamAccountName(string $samaccountname): ?static
    {
        $baseDn = static::baseDn();
        return static::in($baseDn)
            ->where('samaccountname', '=', $samaccountname)
            ->first();
    }

    /**
     * Conversion vers DataObject métier DeviceGroup
     * 
     * @return \App\Types\DeviceGroup
     */
    public function toBusinessObject(): \App\Types\DeviceGroup
    {
        $name = $this->getName();
        $description = $this->getDescription();
        $samAccountName = $this->getSamAccountName();
        $dn = $this->getDn();

        // Extraire le parent depuis le DN si présent
        $parentDn = $this->extractParentDn($dn);

        // Extraire l'établissement depuis le DN
        $etab = $this->extractEstablishment($dn);

        // Construire les données brutes minimales
        $rawData = [
            'cn' => $name,
            'description' => $description,
            'samaccountname' => $samAccountName,
            'dn' => $dn,
        ];

        return new \App\Types\DeviceGroup(
            cn: $name,
            name: $name,
            description: $description,
            parentDn: $parentDn,
            dn: $dn,
            location: null,
            etab: $etab,
            rawData: $rawData,
            children: null,
            machineCount: 0,
        );
    }

    /**
     * Détermine le type de parc depuis le DN ou la description
     * 
     * @param string $dn
     * @param string|null $description
     * @param string $name
     * @return string 'building', 'room', ou 'lab'
     */
    private function determineParcType(string $dn, ?string $description, string $name): string
    {
        $descriptionLower = strtolower($description ?? '');
        $nameLower = strtolower($name);

        // Vérifier dans la description
        if (str_contains($descriptionLower, 'bâtiment') || str_contains($descriptionLower, 'batiment')) {
            return 'building';
        }
        if (str_contains($descriptionLower, 'laboratoire') || str_contains($descriptionLower, 'labo')) {
            return 'lab';
        }

        // Vérifier dans le nom
        if (str_starts_with($nameLower, 'batiment') || str_starts_with($nameLower, 'bat-')) {
            return 'building';
        }
        if (str_contains($nameLower, 'labo') || str_contains($nameLower, 'laboratoire')) {
            return 'lab';
        }

        // Par défaut, c'est un parc (groupe LDAP dans ou=Parcs)
        // Différent de DeviceGroupModel qui retourne 'room' (salle/OU)
        return 'parc';
    }

    /**
     * Extrait le DN du parent depuis le DN actuel
     * 
     * @param string $dn
     * @return string|null
     */
    private function extractParentDn(string $dn): ?string
    {
        // Si le DN contient plusieurs CN, le parent est le DN sans le premier CN
        if (preg_match('/^CN=([^,]+),(.+)$/', $dn, $matches)) {
            return $matches[2];
        }

        return null;
    }

    /**
     * Extrait le code établissement (UAI) depuis le DN
     * 
     * @param string $dn
     * @return string|null
     */
    private function extractEstablishment(string $dn): ?string
    {
        // Pattern pour trouver l'UAI dans le DN
        // Format attendu : OU=0751234a,...
        if (preg_match('/OU=([0-9]{7}[a-z])/i', $dn, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }
}

