<?php

namespace App\Models;

use App\Models\Concerns\HasAppCustomizations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Wireable;
use App\Models\AppProfile;
use App\Enums\LockReason;

/**
 * Modèle Eloquent pour les groupes de postes de travail
 * 
 * Utilise la table PostgreSQL 'workstation_groups'.
 * Représente un groupe de postes.
 * Les règles (GPO, WPKG) sont gérées séparément via AppProfile.
 * 
 * @property int $id
 * @property string|null $controlhub_id UUID universel généré par le ControlHub
 * @property string $name Identifiant unique (slug)
 * @property bool $is_physical true = groupe physique (OU dans Computers), false = groupe logique (CN dans Parcs)
 * @property string|null $display_name Nom d'affichage
 * @property string|null $description
 * @property string|null $app_profile_name Nom du AppProfile à créer (si rempli)
 * @property int|null $parent_id Parent pour les groupes physiques (hiérarchie GPO)
 * @property string|null $ad_dn Distinguished Name dans AD
 * @property string|null $ad_guid objectGUID dans AD
 * @property bool $is_active
 * @property string|null $locked Raison du verrouillage (si non-null, empêche modification/suppression)
 * @property bool $managed_by_control_hub
 * @property \DateTimeInterface|null $archived_at Archivage logique (Story 15.3, AC3.4)
 * @property \DateTime|null $created_at
 * @property \DateTime|null $updated_at
 */
class WorkstationGroup extends Model implements Wireable
{
    use HasFactory;
    use HasAppCustomizations;

    /**
     * La table associée au modèle
     */
    protected $table = 'workstation_groups';

    /**
     * La clé primaire de la table
     */
    protected $primaryKey = 'id';

    /**
     * Les attributs qui peuvent être assignés en masse
     */
    protected $fillable = [
        'controlhub_id',
        'name',
        'is_physical',
        'display_name',
        'description',
        'app_profile_name',
        'parent_id',
        'ad_dn',
        'ad_guid',
        'is_active',
        'locked',
        'managed_by_control_hub',
        'controlhub_id',
        'controlhub_version',
        'archived_at',
    ];

    /**
     * Les attributs qui doivent être castés
     */
    protected $casts = [
        'controlhub_id' => 'string',
        'is_physical' => 'boolean',
        'parent_id' => 'integer',
        'is_active' => 'boolean',
        'locked' => 'string',
        'managed_by_control_hub' => 'boolean',
        'controlhub_version' => 'datetime',
        'archived_at' => 'datetime',
    ];

    /**
     * Vérifie si le groupe est verrouillé
     */
    public function isLocked(): bool
    {
        return !empty($this->locked);
    }

    /**
     * Retourne la raison de verrouillage sous forme d'enum (ou null)
     */
    public function getLockReason(): ?LockReason
    {
        if (empty($this->locked)) {
            return null;
        }
        return LockReason::tryFrom($this->locked);
    }

    /**
     * Retourne la description de la raison de verrouillage
     */
    public function getLockDescription(): ?string
    {
        $reason = $this->getLockReason();
        return $reason?->description() ?? $this->locked;
    }

    /**
     * Verrouille le groupe avec une raison (enum ou string)
     */
    public function lock(LockReason|string $reason): void
    {
        $this->locked = $reason instanceof LockReason ? $reason->value : $reason;
        $this->save();
    }

    /**
     * Déverrouille le groupe
     */
    public function unlock(): void
    {
        $this->locked = null;
        $this->save();
    }


    /**
     * Relation avec le groupe parent
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(WorkstationGroup::class, 'parent_id', 'id');
    }

    /**
     * Relation avec les groupes enfants
     */
    public function children(): HasMany
    {
        return $this->hasMany(WorkstationGroup::class, 'parent_id', 'id');
    }

    /**
     * Relation N:N avec les postes de travail
     */
    public function workstations(): BelongsToMany
    {
        return $this->belongsToMany(
            Workstation::class,
            'workstation_group_workstation',
            'workstation_group_id',
            'workstation_id'
        )->withTimestamps();
    }

    /**
     * Relation 1:N avec les postes physiquement dans cette salle
     * Une machine ne peut être que dans une seule salle physique
     */
    public function physicalWorkstations(): HasMany
    {
        return $this->hasMany(Workstation::class, 'physical_room_id');
    }

    public function wallpapers(): MorphMany
    {
        return $this->morphMany(Wallpaper::class, 'owner');
    }

    /**
     * Ajoute une ou plusieurs machines au groupe.
     *
     * Note Story 4.9 (D4) : les hooks pivot audit-only ont été supprimés
     * (code mort depuis 2026-05-20).
     */
    public function attachWorkstations(int|array $workstationIds): void
    {
        $workstationIds = is_array($workstationIds) ? $workstationIds : [$workstationIds];
        $this->workstations()->attach($workstationIds);
    }

    /**
     * Retire une ou plusieurs machines du groupe.
     *
     * Note Story 4.9 (D4) : voir {@see attachWorkstations()}.
     */
    public function detachWorkstations(int|array $workstationIds): void
    {
        $workstationIds = is_array($workstationIds) ? $workstationIds : [$workstationIds];
        $this->workstations()->detach($workstationIds);
    }

    /**
     * Synchronise les machines du groupe.
     *
     * Note Story 4.9 (D4) : voir {@see attachWorkstations()}.
     *
     * @param array $workstationIds IDs des machines à synchroniser
     * @return array Les changements effectués
     */
    public function syncWorkstations(array $workstationIds): array
    {
        return $this->workstations()->sync($workstationIds);
    }

    /**
     * Relation N:N avec les profils applicatifs
     */

    /**
     * Raccourcis associés à ce groupe de postes
     */
    public function shortcuts(): MorphToMany
    {
        return $this->morphToMany(
            Shortcut::class,
            'assignable',
            'shortcut_assignables',
            'assignable_id',
            'shortcut_id'
        )->withTimestamps();
    }
    public function appProfiles(): BelongsToMany
    {
        return $this->belongsToMany(
            AppProfile::class,
            'app_profile_workstation_group',
            'workstation_group_id',
            'app_profile_id',
            'id',
            'id'
        )->withTimestamps();
    }

    /**
     * Story 15.2 — Apps WPKG rattachées directement à ce parc (pivot
     * `application_workstation_group`, équivalent legacy
     * `applications_profile.type_entite='parc'`).
     */
    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(
            Application::class,
            'application_workstation_group',
            'workstation_group_id',
            'application_id'
        )->withTimestamps();
    }

    public function printers(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\Printer::class,
            'printer_workstation_group',
            'workstation_group_id',
            'cups_name',
            'id',
            'cups_name'
        )->withPivot('attached_at', 'attached_by_user_id');
    }

    /**
     * Scope pour filtrer les groupes actifs
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope pour les groupes physiques (OU dans OU=Computers)
     */
    public function scopePhysical(Builder $query): Builder
    {
        return $query->where('is_physical', true);
    }

    /**
     * Scope pour les groupes logiques (CN dans OU=Parcs)
     */
    public function scopeLogical(Builder $query): Builder
    {
        return $query->where('is_physical', false);
    }

    /**
     * Vérifie si c'est un groupe physique
     */
    public function isPhysical(): bool
    {
        return $this->is_physical === true;
    }

    /**
     * Vérifie si c'est un groupe logique
     */
    public function isLogical(): bool
    {
        return $this->is_physical === false;
    }

    /**
     * Scope pour les groupes avec un AppProfile associé
     */
    public function scopeWithAppProfile(Builder $query): Builder
    {
        return $query->whereNotNull('app_profile_name');
    }

    /**
     * Scope pour les groupes sans AppProfile associé
     */
    public function scopeWithoutAppProfile(Builder $query): Builder
    {
        return $query->whereNull('app_profile_name');
    }

    /**
     * Vérifie si ce groupe a un AppProfile associé
     */
    public function hasAppProfile(): bool
    {
        return !empty($this->app_profile_name);
    }

    /**
     * Récupère tous les descendants (enfants, petits-enfants, etc.)
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Récupère tous les ancêtres (parent, grand-parent, etc.)
     */
    public function ancestors(): BelongsTo
    {
        return $this->parent()->with('ancestors');
    }

    /**
     * Scope pour les groupes racine (sans parent)
     */
    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope pour les groupes synchronisés avec AD
     */
    public function scopeSyncedWithAd(Builder $query): Builder
    {
        return $query->whereNotNull('ad_guid');
    }

    /**
     * Story 15.3 / AC3.4 — Scope pour exclure les groupes archivés.
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Scope pour les groupes non synchronisés avec AD
     */
    public function scopeNotSyncedWithAd(Builder $query): Builder
    {
        return $query->whereNull('ad_guid');
    }

    /**
     * Scope pour rechercher par nom
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('display_name', 'ILIKE', "%{$search}%");
        });
    }

    /**
     * Scope pour les groupes gérés par ControlHub
     */
    public function scopeManagedByControlHub(Builder $query): Builder
    {
        return $query->where('managed_by_control_hub', true);
    }

    /**
     * Vérifie si le groupe est synchronisé avec AD
     */
    public function isSyncedWithAd(): bool
    {
        return !empty($this->ad_guid);
    }

    /**
     * Vérifie si le groupe a des enfants
     */
    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Vérifie si le groupe a un parent
     */
    public function hasParent(): bool
    {
        return !is_null($this->parent_id);
    }

    /**
     * Retourne le nombre de postes dans ce groupe
     */
    public function getWorkstationCountAttribute(): int
    {
        return $this->workstations()->count();
    }

    /**
     * Retourne le nom d'affichage ou le nom technique
     */
    public function getDisplayNameOrNameAttribute(): string
    {
        return $this->display_name ?? $this->name;
    }

    /**
     * Retourne le chemin complet du groupe (parent > enfant > ...)
     */
    public function getFullPathAttribute(): string
    {
        $path = [$this->display_name ?? $this->name];
        $current = $this;

        while ($current->parent) {
            $current = $current->parent;
            array_unshift($path, $current->display_name ?? $current->name);
        }

        return implode(' > ', $path);
    }

    /**
     * Retourne le niveau de profondeur dans l'arborescence
     */
    public function getDepthAttribute(): int
    {
        $depth = 0;
        $current = $this;

        while ($current->parent) {
            $current = $current->parent;
            $depth++;
        }

        return $depth;
    }

    /**
     * Retourne le statut AD sous forme lisible
     */
    public function getAdStatusLabel(): string
    {
        if ($this->isSyncedWithAd()) {
            return 'CN';
        }
        return 'Non synchronisé';
    }

    /**
     * Trouve un groupe par son nom
     */
    public static function findByName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }

    /**
     * Trouve un groupe par son AD GUID
     */
    public static function findByAdGuid(string $adGuid): ?self
    {
        return static::where('ad_guid', $adGuid)->first();
    }

    /**
     * Trouve un groupe par son AD DN
     */
    public static function findByAdDn(string $adDn): ?self
    {
        return static::where('ad_dn', $adDn)->first();
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
