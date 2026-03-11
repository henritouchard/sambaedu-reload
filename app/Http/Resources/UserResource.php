<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource API pour les utilisateurs
 * 
 * Projection simplifiée du DTO User pour les APIs externes.
 * Expose uniquement les champs nécessaires pour les consommateurs externes.
 * 
 * Usage:
 *   return new UserResource($user);
 *   return UserResource::collection($users);
 * 
 * @see \App\Types\User DTO source
 * @property-read \App\Types\User $resource
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'login' => $this->login,
            'fullname' => $this->fullname,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'role' => $this->role,
            'isActive' => $this->isActive,
            'etabCode' => $this->etabCode,
            'etabName' => $this->etabName,
            'groups' => $this->groups,
        ];
    }

    /**
     * Version étendue avec plus de détails
     * Utilisable via: UserResource::make($user)->extended()
     */
    public function extended(): array
    {
        return array_merge($this->toArray(request()), [
            'phone' => $this->resource->phone,
            'description' => $this->resource->description,
            'rights' => $this->resource->rights,
            'createdAt' => $this->resource->createdAt,
            'updatedAt' => $this->resource->updatedAt,
            'lastLogon' => $this->resource->lastLogon,
        ]);
    }

    /**
     * Version minimale pour les listes/autocomplete
     * Utilisable via: UserResource::make($user)->minimal()
     */
    public function minimal(): array
    {
        return [
            'login' => $this->resource->login,
            'fullname' => $this->resource->fullname,
            'role' => $this->resource->role,
        ];
    }
}
