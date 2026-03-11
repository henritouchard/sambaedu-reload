<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Wireable;

/**
 * Modèle Eloquent pour les dépôts d'applications WPKG
 * 
 * Utilise la table PostgreSQL 'depots'.
 * Représente un dépôt contenant des applications WPKG importables.
 * 
 * @property int $id
 * @property string $name Nom du dépôt
 * @property string $url URL du dépôt
 * @property bool $is_primary Dépôt principal
 * @property bool $is_active Dépôt actif
 * @property string|null $xml_hash Hash du fichier XML
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class Depot extends Model implements Wireable
{
    /**
     * La table associée au modèle
     */
    protected $table = 'depots';

    /**
     * Les attributs qui peuvent être assignés en masse
     */
    protected $fillable = [
        'name',
        'url',
        'is_primary',
        'is_active',
        'xml_hash',
    ];

    /**
     * Les attributs qui doivent être castés
     */
    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Relation avec les applications du dépôt
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'depot_id');
    }

    /**
     * Scope pour les dépôts actifs
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope pour le dépôt principal
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    /**
     * Vérifie si le dépôt est actif
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Vérifie si c'est le dépôt principal
     */
    public function isPrimary(): bool
    {
        return $this->is_primary;
    }

    /**
     * Sérialise le modèle pour Livewire
     */
    public function toLivewire(): array
    {
        return ['id' => $this->id];
    }

    /**
     * Désérialise depuis Livewire
     */
    public static function fromLivewire($value): static
    {
        return static::findOrFail($value['id']);
    }
}
