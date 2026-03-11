<?php

namespace App\LdapModels;

use LdapRecord\Models\ActiveDirectory\OrganizationalUnit as BaseOU;
use App\Constants\Ldap\LdapAttributes;
use Illuminate\Support\Facades\Log;

/**
 * Modèle LdapRecord pour les groupes de périphériques (OrganizationalUnit)
 * 
 * Représente un groupe de périphériques dans Active Directory
 * Un groupe de périphériques est une OrganizationalUnit (OU) dans l'arborescence des Computers
 * Note: Dans la pratique, les groupes imbriqués sont utilisés plutôt que les OU
 */
class DeviceGroupModel extends BaseOU
{
    /**
     * Les attributs à retourner dans les résultats
     * Correspond aux attributs utilisés dans search_ad() pour le type "salle" (legacy)
     */
    protected array $columns = [
        'cn',
        'ou',
        'description',
        'objectguid',
    ];

    /**
     * Le DN de base pour ce type d'objet
     * Utilise la configuration SambaEdu pour déterminer le DN des groupes de périphériques
     * Les groupes sont dans l'arborescence des Computers
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

    /**
     * Relation : tag associé (parc)
     * 
     * Un parc (tag) peut être associé à un groupe via le samaccountname
     * Le samaccountname du parc correspond souvent au nom du groupe
     * 
     * @return \LdapRecord\Models\Relations\HasOne|null
     */
    public function associatedTag()
    {
        $ou = $this->getAttributeSafe('ou');
        if (empty($ou)) {
            return null;
        }

        // Chercher le tag avec le même samaccountname que l'OU
        return $this->hasOne(DeviceGroupTagModel::class, 'samaccountname', 'ou');
    }

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
     * Récupère le nom du groupe (ou ou cn)
     * 
     * @return string
     */
    public function getGroupName(): string
    {
        $ou = $this->getAttributeSafe('ou');
        if (!empty($ou)) {
            return (string) $ou;
        }

        $cn = $this->getAttributeSafe('cn', '');
        return (string) $cn;
    }

    /**
     * Récupère la description du groupe
     * 
     * @return string|null
     */
    public function getGroupDescription(): ?string
    {
        $description = $this->getAttributeSafe('description');
        return $description ? (string) $description : null;
    }

    /**
     * Recherche un groupe par son nom (ou)
     * 
     * @param string $name
     * @return static|null
     */
    public static function findByName(string $name): ?static
    {
        return static::where('ou', '=', $name)->first();
    }

    /**
     * Conversion vers DataObject métier DeviceGroup
     * 
     * Convertit un groupe (OU) en DeviceGroup DataObject pour l'affichage
     * 
     * @return \App\Types\DeviceGroup
     */
    public function toBusinessObject(): \App\Types\DeviceGroup
    {
        $name = $this->getGroupName();
        $description = $this->getGroupDescription();
        $dn = $this->getDn();

        // Déterminer le type du groupe depuis le DN ou la description
        $type = $this->determineGroupType($dn, $description, $name);

        // Extraire le parent depuis le DN si présent
        $parentDn = $this->extractParentDn($dn);

        // Extraire l'établissement depuis le DN
        $etab = $this->extractEstablishment($dn);

        // Construire les données brutes minimales
        $rawData = [
            'cn' => $name,
            'ou' => $name,
            'description' => $description,
            'dn' => $dn,
        ];

        // NE PAS calculer le nombre de machines ici - trop coûteux (1 requête LDAP par groupe)
        // Le comptage sera fait en lazy loading si nécessaire

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
            machineCount: 0, // Sera chargé en lazy loading
        );
    }

    /**
     * Détermine le type de groupe depuis le DN ou la description
     * 
     * @param string $dn
     * @param string|null $description
     * @param string $name
     * @return string 'building', 'room', ou 'lab'
     */
    private function determineGroupType(string $dn, ?string $description, string $name): string
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

        // Par défaut, c'est une salle
        return 'room';
    }

    /**
     * Extrait le DN du parent depuis le DN actuel
     * 
     * @param string $dn
     * @return string|null
     */
    private function extractParentDn(string $dn): ?string
    {
        // Si le DN contient plusieurs OU, le parent est le DN sans le premier OU
        if (preg_match('/^OU=([^,]+),(.+)$/', $dn, $matches)) {
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

    /**
     * Récupère le nombre de machines dans cette OU et ses sous-OU
     * 
     * Les machines sont des objets Computer dans Active Directory
     * qui se trouvent dans cette OU ou dans ses sous-OU
     * 
     * @return int
     */
    public function getMachineCount(): int
    {
        try {
            $baseDn = MachineModel::baseDn();
            $ouDn = $this->getDn();

            // Rechercher toutes les machines dans cette OU et ses sous-OU
            // Utiliser une recherche subtree depuis cette OU
            $machines = MachineModel::in($ouDn)
                ->limit(1000) // Limite pour éviter les problèmes de mémoire
                ->get();

            return is_array($machines) ? count($machines) : $machines->count();
        } catch (\Exception $e) {
            // En cas d'erreur, retourner 0 plutôt que de faire planter l'affichage
            Log::warning('Erreur lors du comptage des machines dans l\'OU', [
                'ou_dn' => $this->getDn(),
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Accesseur magique pour $group->machine_count
     */
    public function getMachineCountAttribute(): int
    {
        return $this->getMachineCount();
    }

    /**
     * @deprecated Utiliser getGroupName() à la place
     * @return string
     */
    public function getSalleName(): string
    {
        return $this->getGroupName();
    }

    /**
     * @deprecated Utiliser getGroupDescription() à la place
     * @return string|null
     */
    public function getSalleDescription(): ?string
    {
        return $this->getGroupDescription();
    }

    /**
     * Vérifie si ce groupe est le groupe racine (Computers)
     * 
     * Le groupe racine est celui dont le DN correspond exactement au baseDn
     * 
     * @return bool
     */
    public function isRootGroup(): bool
    {
        $baseDn = static::baseDn();
        $groupDn = $this->getDn();
        return strcasecmp($groupDn, $baseDn) === 0;
    }

    /**
     * Récupère le groupe parent (OU parent)
     * 
     * @return static|null
     */
    public function parentGroup(): ?self
    {
        $parentDn = $this->extractParentDn($this->getDn());
        if (!$parentDn) {
            return null;
        }

        try {
            return static::findByDn($parentDn);
        } catch (\Exception $e) {
            Log::warning('Erreur lors de la récupération du groupe parent', [
                'parent_dn' => $parentDn,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Récupère les groupes enfants (OU enfants directement sous cette OU)
     * 
     * @return \LdapRecord\Models\Collection
     */
    public function children(): \LdapRecord\Models\Collection
    {
        try {
            $currentDn = $this->getDn();

            // Rechercher toutes les OU dans cette OU
            $results = static::in($currentDn)
                ->limit(1000)
                ->get();

            // Filtrer pour exclure l'OU courante (qui est incluse dans les résultats)
            return $results->filter(function ($child) use ($currentDn) {
                return strcasecmp($child->getDn(), $currentDn) !== 0;
            });
        } catch (\Exception $e) {
            Log::warning('Erreur lors de la récupération des groupes enfants', [
                'ou_dn' => $this->getDn(),
                'error' => $e->getMessage()
            ]);
            return new \LdapRecord\Models\Collection();
        }
    }

    /**
     * Récupère toutes les machines dans cette OU (directement, pas dans les sous-OU)
     * 
     * @return \LdapRecord\Models\Collection
     */
    public function directMachines(): \LdapRecord\Models\Collection
    {
        try {
            // Rechercher uniquement les machines directement dans cette OU (pas récursif)
            return MachineModel::in($this->getDn())
                ->limit(500)
                ->get();
        } catch (\Exception $e) {
            Log::warning('Erreur lors de la récupération des machines directes', [
                'ou_dn' => $this->getDn(),
                'error' => $e->getMessage()
            ]);
            return MachineModel::newCollection();
        }
    }

    /**
     * @deprecated Utiliser associatedTag() à la place
     * @return \LdapRecord\Models\Relations\HasOne|null
     */
    public function associatedGroup()
    {
        return $this->associatedTag();
    }
}

