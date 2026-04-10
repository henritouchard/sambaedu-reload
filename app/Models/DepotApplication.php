<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Wireable;

/**
 * Modèle Eloquent pour les applications disponibles sur le dépôt distant
 * 
 * Ces applications ne sont PAS installées sur l'instance locale.
 * Elles représentent le catalogue du store distant.
 * 
 * @property int $id
 * @property int $depot_id ID du dépôt parent
 * @property string $app_id Identifiant technique de l'application
 * @property string $name Nom d'affichage
 * @property string|null $version Version/revision
 * @property string|null $category Catégorie
 * @property string|null $compatibility Compatibilité OS
 * @property string|null $branch Branche (stable, testing)
 * @property string|null $xml_url URL du fichier XML
 * @property string|null $xml_sha Hash SHA du XML
 * @property string|null $log_url URL du log
 * @property string|null $icon_url URL de l'icône
 * @property \DateTime|null $last_checked_at Dernière vérification
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class DepotApplication extends Model implements Wireable
{
    protected $table = 'depot_applications';

    protected $fillable = [
        'depot_id',
        'app_id',
        'name',
        'version',
        'category',
        'compatibility',
        'branch',
        'xml_url',
        'xml_sha',
        'log_url',
        'icon_url',
        'last_checked_at',
    ];

    protected $casts = [
        'depot_id' => 'integer',
        'last_checked_at' => 'datetime',
    ];

    /**
     * Relation avec le dépôt parent
     */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class, 'depot_id');
    }

    /**
     * Scope pour filtrer par catégorie
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope pour filtrer par branche
     */
    public function scopeByBranch(Builder $query, string $branch): Builder
    {
        return $query->where('branch', $branch);
    }

    /**
     * Scope pour rechercher par nom ou app_id
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('app_id', 'ILIKE', "%{$search}%");
        });
    }

    /**
     * Vérifie si l'application est déjà installée localement
     */
    public function isInstalledLocally(): bool
    {
        return Application::where('app_id', $this->app_id)->exists();
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
