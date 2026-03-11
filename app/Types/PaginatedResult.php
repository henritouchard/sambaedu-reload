<?php

namespace App\Types;

use Livewire\Wireable;

/**
 * Classe générique pour encapsuler des résultats paginés avec leurs métadonnées
 * Implémente Wireable pour être utilisable comme propriété Livewire
 * 
 * @template T Type des éléments de la collection (doit implémenter Wireable)
 */
class PaginatedResult implements Wireable
{
    /**
     * @param array<T> $items Liste des éléments
     * @param Pagination $pagination Métadonnées de pagination
     * @param string $itemClass Nom de la classe des éléments (pour la désérialisation)
     */
    public function __construct(
        public readonly array $items,
        public readonly Pagination $pagination,
        private readonly string $itemClass,
    ) {
    }

    /**
     * Crée une instance depuis des données brutes
     * 
     * @param array $items Liste des éléments (tableaux ou objets Wireable)
     * @param array|Pagination $pagination Métadonnées de pagination
     * @param string $itemClass Nom de classe des éléments (doit implémenter Wireable)
     * @return self
     */
    public static function create(array $items, array|Pagination $pagination, string $itemClass): self
    {
        $paginationObj = $pagination instanceof Pagination
            ? $pagination
            : Pagination::fromArray($pagination);

        return new self(
            items: $items,
            pagination: $paginationObj,
            itemClass: $itemClass,
        );
    }

    /**
     * Retourne le nombre d'éléments dans la page courante
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Vérifie si le résultat est vide
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Vérifie si le résultat contient des éléments
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Retourne le total d'éléments (toutes pages confondues)
     */
    public function total(): int
    {
        return $this->pagination->total;
    }

    /**
     * Convertit l'objet en tableau
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(
                fn($item) => $item instanceof Wireable ? $item->toLivewire() : $item,
                $this->items
            ),
            'pagination' => $this->pagination->toArray(),
            'itemClass' => $this->itemClass,
        ];
    }

    /**
     * Sérialise l'objet pour Livewire (interface Wireable)
     */
    public function toLivewire(): array
    {
        return $this->toArray();
    }

    /**
     * Désérialise depuis Livewire (interface Wireable)
     */
    public static function fromLivewire($value): static
    {
        $itemClass = $value['itemClass'] ?? null;

        if (!$itemClass || !class_exists($itemClass)) {
            throw new \InvalidArgumentException("Invalid or missing itemClass: {$itemClass}");
        }

        if (!in_array(Wireable::class, class_implements($itemClass))) {
            throw new \InvalidArgumentException("itemClass must implement Wireable interface: {$itemClass}");
        }

        $items = array_map(
            fn($itemData) => $itemClass::fromLivewire($itemData),
            $value['items'] ?? []
        );

        return new static(
            items: $items,
            pagination: Pagination::fromLivewire($value['pagination'] ?? []),
            itemClass: $itemClass,
        );
    }
}
