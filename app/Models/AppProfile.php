<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Wireable;
use App\Models\Application;
use App\Models\WorkstationGroup;
use App\Models\Workstation;

/**
 * Modèle Eloquent pour les profils applicatifs
 * 
 * Un AppProfile est un groupe d'applications WPKG qui peut être assigné
 * à plusieurs WorkstationGroups (parcs). Cela permet de définir des ensembles
 * d'applications réutilisables.
 * 
 * Relations :
 * - ManyToMany avec WorkstationGroup (via app_profile_workstation_group)
 * - ManyToMany avec Application (via app_profile_application)
 * 
 * @property int $id
 * @property string|null $controlhub_id UUID universel généré par le ControlHub
 * @property string $name
 * @property string|null $display_name
 * @property string|null $description
 * @property string|null $ad_guid GUID dans AD (après synchronisation)
 * @property string|null $ad_dn Distinguished Name dans AD (Story 15.3)
 * @property bool $is_active
 * @property \DateTimeInterface|null $archived_at Archivage logique (Story 15.3, AC3.4)
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class AppProfile extends Model implements Wireable
{
    /**
     * La table associée au modèle
     */
    protected $table = 'app_profiles';

    /**
     * Les attributs qui peuvent être assignés en masse
     */
    protected $fillable = [
        'controlhub_id',
        'controlhub_version',
        'name',
        'display_name',
        'description',
        'ad_guid',
        'ad_dn',
        'is_active',
        'archived_at',
    ];

    /**
     * Les attributs qui doivent être castés
     */
    protected $casts = [
        'controlhub_id' => 'string',
        'controlhub_version' => 'datetime',
        'is_active' => 'boolean',
        'archived_at' => 'datetime',
    ];

    /**
     * Relation ManyToMany avec les WorkstationGroups (parcs)
     */
    public function workstationGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            WorkstationGroup::class,
            'app_profile_workstation_group',
            'app_profile_id',
            'workstation_group_id'
        )->withTimestamps();
    }

    /**
     * Relation ManyToMany avec les Applications
     */
    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(
            Application::class,
            'app_profile_application',
            'app_profile_id',
            'application_id'
        )->withTimestamps();
    }

    /**
     * Relation ManyToMany avec les Workstations (postes)
     */
    public function workstations(): BelongsToMany
    {
        return $this->belongsToMany(
            Workstation::class,
            'app_profile_workstation',
            'app_profile_id',
            'workstation_id'
        )->withTimestamps();
    }

    /**
     * Scope pour les profils actifs
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope pour rechercher par nom
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('display_name', 'ILIKE', "%{$search}%")
                ->orWhere('description', 'ILIKE', "%{$search}%");
        });
    }

    /**
     * Retourne le nom d'affichage ou le nom technique
     */
    public function getDisplayNameOrNameAttribute(): string
    {
        return $this->display_name ?? $this->name;
    }

    /**
     * Retourne le nombre d'applications dans ce profil
     */
    public function getApplicationsCountAttribute(): int
    {
        return $this->applications()->count();
    }

    /**
     * Retourne le nombre de groupes utilisant ce profil
     */
    public function getWorkstationGroupsCountAttribute(): int
    {
        return $this->workstationGroups()->count();
    }

    /**
     * Vérifie si le profil est utilisé par au moins un groupe
     */
    public function isUsed(): bool
    {
        return $this->workstationGroups()->exists();
    }

    /**
     * Trouve un profil par son nom
     */
    public static function findByName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }

    /**
     * Story 15.3 — Trouve un profil par son AD DN (symétrie
     * `WorkstationGroup::findByAdDn()`).
     */
    public static function findByAdDn(string $adDn): ?self
    {
        return static::where('ad_dn', $adDn)->first();
    }

    /**
     * Story 15.3 / AC3.4 — Scope pour exclure les profils archivés.
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
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
