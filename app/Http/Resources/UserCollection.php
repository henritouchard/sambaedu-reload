<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Collection Resource pour les listes d'utilisateurs paginées
 * 
 * Usage dans un controller API:
 *   return new UserCollection($paginatedResult->items);
 * 
 * @see \App\Types\PaginatedResult
 */
class UserCollection extends ResourceCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = UserResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }

    /**
     * Ajoute les métadonnées de pagination
     * 
     * @param array $pagination Données de pagination depuis PaginatedResult
     */
    public function withPagination(array $pagination): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'current_page' => $pagination['current_page'] ?? 1,
                'per_page' => $pagination['per_page'] ?? 20,
                'total' => $pagination['total'] ?? 0,
                'last_page' => $pagination['last_page'] ?? 1,
                'from' => $pagination['from'] ?? 0,
                'to' => $pagination['to'] ?? 0,
            ],
        ];
    }
}
