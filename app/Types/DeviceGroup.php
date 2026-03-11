<?php

namespace App\Types;

use JsonSerializable;
use Livewire\Wireable;
use Log;

/**
 * DTO (Data Transfer Object) typé pour un groupe de machines du parc
 * 
 * Représente un groupe (OU) dans la hiérarchie Active Directory
 * avec des méthodes métier pour faciliter la manipulation des données.
 * 
 * Implémente Wireable pour être utilisable comme propriété Livewire.
 */
class DeviceGroup implements JsonSerializable, Wireable
{
    public function __construct(
        public readonly string $cn,                    // Nom technique (OU LDAP)
        public readonly string $name,                  // Nom d'affichage
        public readonly ?string $description = null,   // Description du groupe
        public readonly ?string $parentDn = null,      // DN du parent dans la hiérarchie
        public readonly ?string $dn = null,           // Distinguished Name complet
        public readonly ?string $location = null,      // Localisation physique
        public readonly ?string $etab = null,         // Code établissement (UAI)
        public readonly array $rawData = [],          // Données brutes d'Active Directory
        public $children = null,                      // Collection des enfants (mutable pour buildHierarchy)
        public readonly int $machineCount = 0,        // Nombre de machines dans le groupe
    ) {
    }

    /**
     * Récupère le nom d'affichage préféré
     * Utilise le nom si disponible, sinon le CN
     */
    public function getDisplayName(): string
    {
        return $this->name ?: $this->cn;
    }

    /**
     * Récupère l'identifiant unique du groupe
     */
    public function getId(): string
    {
        return $this->cn;
    }

    /**
     * Récupère le nom du parent depuis le DN
     */
    public function getParentName(): ?string
    {
        if (!$this->parentDn) {
            return null;
        }

        // Extrait le OU du DN parent
        if (preg_match('/^OU=([^,]+),/', $this->parentDn, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Construit le fil d'Ariane (breadcrumb) depuis le DN
     */
    public function getBreadcrumb(): array
    {
        if (!$this->dn) {
            return [$this->getDisplayName()];
        }

        $breadcrumb = [];
        $dnParts = explode(',', $this->dn);

        // Parcourt les composants du DN en ordre inverse (racine → feuille)
        $reversedParts = array_reverse(array_slice($dnParts, 1)); // Exclut le OU actuel

        foreach ($reversedParts as $part) {
            if (preg_match('/^OU=([^,]+)$/', trim($part), $matches)) {
                $breadcrumb[] = $matches[1];
            }
        }

        // Ajoute le groupe actuel
        $breadcrumb[] = $this->getDisplayName();

        return $breadcrumb;
    }

    /**
     * Récupère l'icône SVG path pour le groupe
     */
    public function getIcon(): string
    {
        // Icône de dossier/groupe par défaut
        return 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4';
    }

    /**
     * Récupère le niveau hiérarchique basé sur le DN
     * Compte uniquement les OU dans la hiérarchie des groupes (exclut computers)
     */
    public function getHierarchyLevel(): int
    {
        if (!$this->dn) {
            return 0;
        }

        // Extraire tous les OU du DN (OU=monGroupe,OU=MonUAI,OU=computers,DC=athena,DC=moncollege95,DC=fr)
        if (preg_match_all('/OU=([^,]+)/i', $this->dn, $matches)) {
            $ous = $matches[1];

            // Trouver l'index de "computers" 
            $computersIndex = array_search('computers', array_map('strtolower', $ous));

            if ($computersIndex !== false && $computersIndex > 1) {
                // Garder seulement les OU avant "computers" en excluant le dernier (établissement)
                $groupOus = array_slice($ous, 0, $computersIndex - 1);
                return count($groupOus);
            }
        }

        return 0;
    }

    /**
     * Vérifie si ce groupe est un enfant du groupe donné
     */
    public function isChildOf(DeviceGroup $potentialParent): bool
    {
        if (!$this->parentDn || !$potentialParent->dn) {
            return false;
        }

        return $this->parentDn === $potentialParent->dn;
    }

    /**
     * Vérifie si ce groupe est un descendant du groupe donné
     */
    public function isDescendantOf(DeviceGroup $potentialAncestor): bool
    {
        if (!$this->dn || !$potentialAncestor->dn) {
            return false;
        }

        return str_ends_with($this->dn, $potentialAncestor->dn);
    }

    /**
     * Crée une instance depuis les données brutes d'Active Directory
     */
    public static function fromAdData(array $adData): self
    {
        // Extraction du DN parent
        $parentDn = self::extractParentDn($adData['dn'] ?? '');

        return new self(
            cn: $adData['cn'] ?? $adData['ou'] ?? $adData['name'] ?? '',
            name: $adData['name'] ?? $adData['ou'] ?? $adData['cn'] ?? '',
            description: $adData['description'] ?? null,
            parentDn: $parentDn,
            dn: $adData['dn'] ?? null,
            location: $adData['location'] ?? $adData['l'] ?? null,
            etab: $adData['etab'] ?? null,
            rawData: $adData,
            machineCount: $adData['machineCount'] ?? $adData['machine_count'] ?? 0,
        );
    }

    /**
     * Extrait le DN parent depuis un DN complet
     */
    private static function extractParentDn(string $dn): ?string
    {
        if (empty($dn)) {
            return null;
        }

        $parts = explode(',', $dn, 2);
        return isset($parts[1]) ? trim($parts[1]) : null;
    }

    /**
     * Convertit en tableau pour compatibilité avec le code legacy
     */
    public function toArray(): array
    {
        return [
            'cn' => $this->cn,
            'name' => $this->name,
            'description' => $this->description,
            'dn' => $this->dn,
            'location' => $this->location,
            'etab' => $this->etab,
            // Champs calculés
            'display_name' => $this->getDisplayName(),
            'icon' => $this->getIcon(),
            'hierarchy_level' => $this->getHierarchyLevel(),
            'breadcrumb' => $this->getBreadcrumb(),
            'machine_count' => $this->machineCount,
        ];
    }

    /**
     * Sérialisation JSON
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Sérialisation pour Livewire (Wireable)
     */
    public function toLivewire(): array
    {
        return [
            'cn' => $this->cn,
            'name' => $this->name,
            'description' => $this->description,
            'parentDn' => $this->parentDn,
            'dn' => $this->dn,
            'location' => $this->location,
            'etab' => $this->etab,
            'machineCount' => $this->machineCount,
        ];
    }

    /**
     * Désérialisation depuis Livewire (Wireable)
     */
    public static function fromLivewire($value): static
    {
        return new static(
            cn: $value['cn'] ?? '',
            name: $value['name'] ?? '',
            description: $value['description'] ?? null,
            parentDn: $value['parentDn'] ?? null,
            dn: $value['dn'] ?? null,
            location: $value['location'] ?? null,
            etab: $value['etab'] ?? null,
            machineCount: $value['machineCount'] ?? 0,
        );
    }

    /**
     * Représentation string pour debug
     */
    public function __toString(): string
    {
        return sprintf(
            'DeviceGroup[%s] %s',
            $this->cn,
            $this->getDisplayName()
        );
    }
}
