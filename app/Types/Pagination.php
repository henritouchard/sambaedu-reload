<?php

namespace App\Types;

use Livewire\Wireable;

/**
 * Classe de données typée pour les métadonnées de pagination
 * Implémente Wireable pour être utilisable comme propriété Livewire
 */
class Pagination implements Wireable
{
    public function __construct(
        public readonly int $currentPage,
        public readonly int $perPage,
        public readonly int $total,
        public readonly int $lastPage,
        public readonly int $from,
        public readonly int $to,
        public readonly bool $hasMorePages,
    ) {
    }

    /**
     * Crée une instance depuis un tableau de données
     */
    public static function fromArray(array $data): self
    {
        return new self(
            currentPage: $data['current_page'] ?? $data['currentPage'] ?? 1,
            perPage: $data['per_page'] ?? $data['perPage'] ?? 20,
            total: $data['total'] ?? 0,
            lastPage: $data['last_page'] ?? $data['lastPage'] ?? 1,
            from: $data['from'] ?? 0,
            to: $data['to'] ?? 0,
            hasMorePages: $data['has_more_pages'] ?? $data['hasMorePages'] ?? false,
        );
    }

    /**
     * Convertit l'objet en tableau
     */
    public function toArray(): array
    {
        return [
            'current_page' => $this->currentPage,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'last_page' => $this->lastPage,
            'from' => $this->from,
            'to' => $this->to,
            'has_more_pages' => $this->hasMorePages,
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
        return self::fromArray($value);
    }
}
