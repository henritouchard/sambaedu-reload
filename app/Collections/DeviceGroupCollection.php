<?php

namespace App\Collections;

use Illuminate\Support\Collection;
use App\Types\DeviceGroup;

/**
 * Collection personnalisée pour les groupes de machines
 * 
 * Ajoute des méthodes expressives et chainables pour manipuler
 * les collections de groupes de façon élégante et lisible.
 */
class DeviceGroupCollection extends Collection
{
    /**
     * Filtre par établissement
     */
    public function filterByEtab(string $etab): self
    {
        return $this->filter(fn(DeviceGroup $group) => $group->etab === $etab);
    }

    /**
     * Recherche par nom ou description
     */
    public function searchByName(string $query): self
    {
        $query = strtolower(trim($query));

        if (empty($query)) {
            return $this;
        }

        return $this->filter(function (DeviceGroup $group) use ($query) {
            return str_contains(strtolower($group->name), $query) ||
                str_contains(strtolower($group->cn), $query) ||
                str_contains(strtolower($group->description ?? ''), $query);
        });
    }

    /**
     * Construit la hiérarchie des groupes (arbre parent/enfant)
     */
    public function buildHierarchy(): self
    {
        $indexed = [];
        $hierarchy = [];

        // Indexation par DN pour accès rapide
        foreach ($this->items as $group) {
            if ($group->dn) {
                $indexed[$group->dn] = $group;
            }
        }

        // Construction de la hiérarchie
        foreach ($this->items as $group) {
            if ($group->parentDn && isset($indexed[$group->parentDn])) {
                // A un parent dans la collection
                if (!isset($indexed[$group->parentDn]->children)) {
                    $indexed[$group->parentDn]->children = new self([]);
                }
                $indexed[$group->parentDn]->children->push($group);
            } else {
                // Groupe racine
                $hierarchy[] = $group;
            }
        }

        return new self($hierarchy);
    }

    /**
     * Récupère les groupes racines (sans parent)
     */
    public function getRoots(): self
    {
        return $this->filter(fn(DeviceGroup $group) => empty($group->parentDn));
    }

    /**
     * Récupère les enfants d'un groupe donné
     */
    public function getChildren(DeviceGroup $parent): self
    {
        return $this->filter(fn(DeviceGroup $group) => $group->isChildOf($parent));
    }

    /**
     * Récupère tous les descendants d'un groupe donné
     */
    public function getDescendants(DeviceGroup $ancestor): self
    {
        return $this->filter(fn(DeviceGroup $group) => $group->isDescendantOf($ancestor));
    }

    /**
     * Tri par hiérarchie (niveau puis nom)
     */
    public function sortByHierarchy(): self
    {
        return $this->sortBy([
            fn(DeviceGroup $group) => $group->getHierarchyLevel(),
            fn(DeviceGroup $group) => $group->getDisplayName()
        ]);
    }

    /**
     * Tri par nom d'affichage
     */
    public function sortByName(): self
    {
        return $this->sortBy(fn(DeviceGroup $group) => $group->getDisplayName());
    }

    /**
     * Groupe les groupes par établissement
     */
    public function groupByEtab(): Collection
    {
        return $this->groupBy(fn(DeviceGroup $group) => $group->etab ?? 'unknown');
    }

    /**
     * Groupe les groupes par niveau hiérarchique
     */
    public function groupByLevel(): Collection
    {
        return $this->groupBy(fn(DeviceGroup $group) => $group->getHierarchyLevel());
    }

    /**
     * Calcule les statistiques de la collection
     */
    public function getStats(): array
    {
        $total = $this->count();

        return [
            'total_groups' => $total,
            'by_etab' => $this->countByEtab(),
            'hierarchy_levels' => $this->getHierarchyLevels(),
        ];
    }

    /**
     * Compte les groupes par établissement
     */
    public function countByEtab(): array
    {
        return $this->groupByEtab()
            ->map(fn($group) => $group->count())
            ->toArray();
    }

    /**
     * Récupère les niveaux de hiérarchie présents
     */
    public function getHierarchyLevels(): array
    {
        return $this->groupByLevel()
            ->map(fn($group) => $group->count())
            ->toArray();
    }

    /**
     * Convertit la collection en tableau pour l'affichage
     */
    public function toDisplayArray(): array
    {
        return $this->map(fn(DeviceGroup $group) => $group->toArray())->values()->toArray();
    }

    /**
     * Trouve un groupe par son CN
     */
    public function findByCn(string $cn): ?DeviceGroup
    {
        return $this->first(fn(DeviceGroup $group) => $group->cn === $cn);
    }

    /**
     * Trouve un groupe par son DN
     */
    public function findByDn(string $dn): ?DeviceGroup
    {
        return $this->first(fn(DeviceGroup $group) => $group->dn === $dn);
    }
}
