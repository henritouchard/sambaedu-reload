<?php

namespace App\Types;

use Illuminate\Support\Collection;

/**
 * Résultat typé d'une recherche d'utilisateurs
 */
class UserSearchResult
{
    public function __construct(
        /** @var Collection<User> */
        public readonly Collection $users,

        public readonly int $total,
        public readonly int $currentPage,
        public readonly int $perPage,
        public readonly int $lastPage,
        public readonly int $from,
        public readonly int $to,
        public readonly bool $hasMorePages
    ) {
    }

    /**
     * Convertit le résultat en tableau (pour compatibilité)
     */
    public function toArray(): array
    {
        return [
            'users' => $this->users,
            'total' => $this->total,
            'pagination' => [
                'current_page' => $this->currentPage,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'last_page' => $this->lastPage,
                'from' => $this->from,
                'to' => $this->to,
                'has_more_pages' => $this->hasMorePages
            ]
        ];
    }
}
