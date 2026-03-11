<?php

namespace App\Models;

use App\Observers\WorkstationObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Livewire\Wireable;

/**
 * Modèle Eloquent pour les postes de travail (workstations)
 * 
 * Utilise la nouvelle table PostgreSQL 'workstations' avec un schéma moderne.
 * Ce modèle permet d'interagir avec les postes du parc informatique.
 * 
 * @property int $id
 * @property string $name Nom du poste (hostname)
 * @property string|null $os Système d'exploitation
 * @property string|null $ip Adresse IP
 * @property string|null $mac Adresse MAC
 * @property string|null $uuid UUID matériel
 * @property string $status active, inactive, protected
 * @property \DateTime|null $last_report_at Date du dernier rapport
 * @property string|null $report_sha Hash SHA du dernier rapport
 * @property string|null $log_path Chemin du fichier log
 * @property string|null $report_path Chemin du fichier rapport
 * @property int|null $physical_room_id ID de la salle physique
 * @property string|null $ad_dn Distinguished Name dans AD
 * @property string|null $ad_guid objectGUID dans AD
 * @property bool $managed_by_control_hub
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class Workstation extends Model implements Wireable
{
    /**
     * La table associée au modèle
     */
    protected $table = 'workstations';

    /**
     * Les attributs qui peuvent être assignés en masse
     */
    protected $fillable = [
        'name',
        'os',
        'ip',
        'mac',
        'uuid',
        'status',
        'last_report_at',
        'report_sha',
        'log_path',
        'report_path',
        'physical_room_id',
        'ad_dn',
        'ad_guid',
        'managed_by_control_hub',
    ];

    /**
     * Les attributs qui doivent être castés
     */
    protected $casts = [
        'last_report_at' => 'datetime',
        'physical_room_id' => 'integer',
        'managed_by_control_hub' => 'boolean',
    ];

    /**
     * Relation N:1 avec la salle physique où se trouve la machine
     * Une machine ne peut être que dans une seule salle physique
     */

    /**
     * Raccourcis associés à ce poste de travail
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
    public function physicalRoom(): BelongsTo
    {
        return $this->belongsTo(WorkstationGroup::class, 'physical_room_id');
    }

    /**
     * Vérifie si la machine est assignée à une salle physique
     */
    public function hasPhysicalRoom(): bool
    {
        return !is_null($this->physical_room_id);
    }

    /**
     * Assigne la machine à une salle physique
     * 
     * @param int|null $roomId ID de la salle physique (null pour retirer)
     * @return bool
     */
    public function assignToPhysicalRoom(?int $roomId): bool
    {
        $this->physical_room_id = $roomId;
        return $this->save();
    }

    /**
     * Relation N:N avec les groupes de machines
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            WorkstationGroup::class,
            'workstation_group_workstation',
            'workstation_id',
            'workstation_group_id'
        )->withTimestamps();
    }

    /**
     * Ajoute la machine à un ou plusieurs groupes avec synchronisation AD
     * 
     * @param int|array $groupIds ID(s) du/des groupe(s)
     * @return void
     */
    public function attachGroups(int|array $groupIds): void
    {
        $groupIds = is_array($groupIds) ? $groupIds : [$groupIds];

        $this->groups()->attach($groupIds);

        foreach ($groupIds as $groupId) {
            WorkstationObserver::onGroupAttached($this, (int) $groupId);
        }
    }

    /**
     * Retire la machine d'un ou plusieurs groupes avec synchronisation AD
     * 
     * @param int|array $groupIds ID(s) du/des groupe(s)
     * @return void
     */
    public function detachGroups(int|array $groupIds): void
    {
        $groupIds = is_array($groupIds) ? $groupIds : [$groupIds];

        $this->groups()->detach($groupIds);

        foreach ($groupIds as $groupId) {
            WorkstationObserver::onGroupDetached($this, (int) $groupId);
        }
    }

    /**
     * Synchronise les groupes de la machine avec synchronisation AD
     * 
     * @param array $groupIds IDs des groupes à synchroniser
     * @return array Les changements effectués
     */
    public function syncGroups(array $groupIds): array
    {
        $changes = $this->groups()->sync($groupIds);

        WorkstationObserver::onGroupsSynced($this, $changes);

        return $changes;
    }


    /**
     * Scope pour filtrer les machines actives
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope pour filtrer les machines inactives
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope pour filtrer les machines protégées
     */
    public function scopeProtected(Builder $query): Builder
    {
        return $query->where('status', 'protected');
    }

    /**
     * Scope pour filtrer par OS
     */
    public function scopeByOs(Builder $query, string $os): Builder
    {
        return $query->where('os', $os);
    }

    /**
     * Scope pour rechercher par nom
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where('name', 'ILIKE', "%{$search}%");
    }

    /**
     * Scope pour filtrer les machines avec UUID
     */
    public function scopeWithUuid(Builder $query): Builder
    {
        return $query->whereNotNull('uuid');
    }

    /**
     * Scope pour filtrer les machines sans UUID
     */
    public function scopeWithoutUuid(Builder $query): Builder
    {
        return $query->whereNull('uuid');
    }

    /**
     * Scope pour les machines synchronisées avec AD
     */
    public function scopeSyncedWithAd(Builder $query): Builder
    {
        return $query->whereNotNull('ad_guid');
    }

    /**
     * Vérifie si la machine a un UUID
     */
    public function hasUuid(): bool
    {
        return !empty($this->uuid);
    }

    /**
     * Vérifie si la machine est active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Vérifie si la machine est protégée
     */
    public function isProtected(): bool
    {
        return $this->status === 'protected';
    }

    /**
     * Vérifie si la machine est synchronisée avec AD
     */
    public function isSyncedWithAd(): bool
    {
        return !empty($this->ad_guid);
    }

    /**
     * Retourne le statut de la machine sous forme lisible
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'inactive' => 'Inactif',
            'active' => 'Actif',
            'protected' => 'Protégé',
            default => 'Inconnu',
        };
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
