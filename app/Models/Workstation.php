<?php

namespace App\Models;

use App\Auth\V1\Models\WorkstationMigrationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property string|null $ad_dn Distinguished Name dans AD
 * @property string|null $ad_guid objectGUID dans AD
 * @property bool $managed_by_control_hub
 * @property \DateTimeInterface|null $archived_at Archivage logique (Story 15.3, AC3.4)
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class Workstation extends Model implements Wireable
{
    use HasFactory;

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
        'progress',
        'programmed_action',
        'last_report_at',
        'report_sha',
        'log_path',
        'report_path',
        'ad_dn',
        'ad_guid',
        'managed_by_control_hub',
        'archived_at',
    ];

    /**
     * Les attributs qui doivent être castés
     *
     * Story 3.8 — D3 / AC7.3 : `programmed_action` cast en `array` —
     * sérialisation JSON applicative (compatible JSONB Postgres + fallback
     * text SQLite/MySQL via migration 2026_05_22_120000).
     */
    protected $casts = [
        'last_report_at' => 'datetime',
        'managed_by_control_hub' => 'boolean',
        'archived_at' => 'datetime',
        'programmed_action' => 'array',
    ];

    /**
     * Mutator : force `mac` en lowercase canonique iso
     * {@see \App\Ipxe\Support\MacAddressNormalizer::normalize()}.
     * Précondition du fallback MAC lookup indexé (cf. migration
     * `2026_05_20_120000_normalize_workstations_mac_lowercase`).
     */
    public function setMacAttribute(?string $value): void
    {
        $this->attributes['mac'] = $value !== null ? strtolower($value) : null;
    }

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
    /**
     * Relation pivot filtrée vers la (les) salle(s) physique(s) du poste.
     *
     * Story 4.11 — l'appartenance « salle » vit désormais dans le pivot global
     * `workstation_group_workstation`, plus dans une FK dédiée. La salle est un
     * groupe `is_physical = true` ; l'invariant « 1 salle max par poste » est
     * une règle de service (swap transactionnel `WorkstationGroupService`), pas
     * une contrainte DB (D3). Cette relation retourne donc *techniquement* une
     * collection, dont l'accessor singulier {@see getPhysicalRoomAttribute}
     * extrait l'unique salle (ou null).
     */
    public function physicalRooms(): BelongsToMany
    {
        return $this->belongsToMany(
            WorkstationGroup::class,
            'workstation_group_workstation',
            'workstation_id',
            'workstation_group_id'
        )->where('workstation_groups.is_physical', true)
            ->withTimestamps();
    }

    /**
     * Accessor singulier : LA salle physique du poste (ou null).
     *
     * Story 4.11 — remplace l'ancienne relation `belongsTo` FK. API de lecture
     * inchangée pour les consommateurs (`$ws->physicalRoom`, `?->ad_dn`,
     * `?->id`, `?->name`). Réutilise la relation eager-loadée `physicalRooms`
     * si présente pour éviter le N+1.
     */
    public function getPhysicalRoomAttribute(): ?WorkstationGroup
    {
        if ($this->relationLoaded('physicalRooms')) {
            return $this->getRelation('physicalRooms')->first();
        }

        return $this->physicalRooms()->first();
    }

    /**
     * Vérifie si la machine est assignée à une salle physique.
     *
     * Story 4.11 — lecture via le pivot (`is_physical = true`).
     */
    public function hasPhysicalRoom(): bool
    {
        if ($this->relationLoaded('physicalRooms')) {
            return $this->getRelation('physicalRooms')->isNotEmpty();
        }

        return $this->physicalRooms()->exists();
    }

    /**
     * Retourne l'OU AD du poste pour le join domain (unattend.xml
     * `MachineObjectOU`). Source de vérité = `physicalRoom->ad_dn` (alimenté
     * par l'enrollment 3-3 ou la sync AD). Le caller utilise un fallback
     * `config('sambaedu.computers_rdn', 'CN=Computers')` si null.
     */
    public function getAdOu(): ?string
    {
        return $this->physicalRoom?->ad_dn;
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
     * Ajoute la machine à un ou plusieurs groupes.
     *
     * Note Story 4.9 (D4) : les hooks pivot audit-only `onGroupAttached` ont
     * été supprimés (code mort depuis 2026-05-20 — la sync AD machine→groupe
     * a été retirée, le pivot SQL est la source de vérité).
     */
    public function attachGroups(int|array $groupIds): void
    {
        $groupIds = is_array($groupIds) ? $groupIds : [$groupIds];
        $this->groups()->attach($groupIds);
    }

    /**
     * Retire la machine d'un ou plusieurs groupes.
     *
     * Note Story 4.9 (D4) : voir {@see attachGroups()}.
     */
    public function detachGroups(int|array $groupIds): void
    {
        $groupIds = is_array($groupIds) ? $groupIds : [$groupIds];
        $this->groups()->detach($groupIds);
    }

    /**
     * Synchronise les groupes de la machine.
     *
     * Note Story 4.9 (D4) : voir {@see attachGroups()}.
     *
     * @param array $groupIds IDs des groupes à synchroniser
     * @return array Les changements effectués
     */
    public function syncGroups(array $groupIds): array
    {
        return $this->groups()->sync($groupIds);
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
     * Story 15.3 / AC3.4 — Scope pour exclure les postes archivés.
     *
     * Filtre par défaut à appliquer dans les listings UI (Story 15.4) et
     * dans le pipeline de déploiement (`WorkstationPackagesResolver`,
     * décision D8 actée pendant T1).
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
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

    /**
     * Statuts d'application liés à ce poste
     */
    public function applicationStatuses(): HasMany
    {
        return $this->hasMany(WorkstationApplicationStatus::class);
    }

    /**
     * Story 15.2 — AppProfiles assignés directement à ce poste (pivot
     * `app_profile_workstation`).
     */
    public function appProfiles(): BelongsToMany
    {
        return $this->belongsToMany(
            AppProfile::class,
            'app_profile_workstation',
            'workstation_id',
            'app_profile_id'
        )->withTimestamps();
    }

    /**
     * Story 15.2 — Apps WPKG rattachées directement à ce poste (pivot
     * `application_workstation`, équivalent legacy
     * `applications_profile.type_entite='poste'`).
     */
    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(
            Application::class,
            'application_workstation',
            'workstation_id',
            'application_id'
        )->withTimestamps();
    }

    /**
     * Story 15.2 — Overrides des options `.ini` WPKG pour ce poste.
     */
    public function wpkgOptions(): HasMany
    {
        return $this->hasMany(\App\Wpkg\Deployment\Models\WpkgWorkstationOption::class);
    }

    /**
     * Story 16.13bis — relation HasOne vers `workstations_migration_status`
     * (table livrée par 16.11). FK `workstation_uuid` ↔ PK locale `uuid`.
     *
     * Note : pas de FK SQL formelle côté DB (cf. 16.11 D7) — un poste peut
     * apparaître dans `workstations_migration_status` avant d'exister dans
     * `workstations` (cas d'un poste qui s'enrôle avant d'être déclaré
     * côté admin).
     */
    public function migrationStatus(): HasOne
    {
        return $this->hasOne(WorkstationMigrationStatus::class, 'workstation_uuid', 'uuid');
    }

    /**
     * Story 16.13bis — Accessor booléen : poste basculé SE5 si présence
     * d'une row `workstation_migration_status` matching son UUID.
     */
    public function getMigratedAttribute(): bool
    {
        if (empty($this->uuid)) {
            return false;
        }

        // Si la relation est déjà eager-loadée, on évite la requête.
        if (array_key_exists('migrationStatus', $this->relations)) {
            return $this->getRelation('migrationStatus') !== null;
        }

        return $this->migrationStatus()->exists();
    }

    /**
     * Story 16.13bis — Scope : postes ayant une row migration_status.
     */
    public function scopeMigrated(Builder $query): Builder
    {
        return $query->whereHas('migrationStatus');
    }

    /**
     * Story 16.13bis — Scope : postes sans row migration_status.
     */
    public function scopeNotMigrated(Builder $query): Builder
    {
        return $query->whereDoesntHave('migrationStatus');
    }
}
