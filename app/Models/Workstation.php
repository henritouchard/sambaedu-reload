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
 * @property string|null $agent_token_hash SHA-256 hex du bearer agent (Story 23.2)
 * @property string|null $agent_previous_token_hash Fenêtre de grâce rotation D5 (Story 23.2)
 * @property \DateTimeInterface|null $agent_token_rotated_at Dernière émission/rotation token agent (Story 23.2)
 * @property \DateTimeInterface|null $agent_last_checkin_at Dernier check-in canal agent (Story 23.2)
 * @property \DateTimeInterface|null $agent_quarantined_at Quarantaine anti-clonage (Story 23.2)
 * @property string|null $agent_enroll_ticket_hash SHA-256 hex du ticket d'enrôlement one-time (Story 23.3)
 * @property \DateTimeInterface|null $agent_enroll_ticket_expires_at Expiration du ticket d'enrôlement (Story 23.3)
 * @property \DateTimeInterface|null $agent_sync_requested_at Demande de resynchronisation pendante (Story 24.7)
 * @property string|null $agent_reported_version Dernière version d'agent rapportée (Story 25.5)
 * @property \DateTimeInterface|null $agent_reported_version_at Fraîcheur de la version rapportée (Story 25.5)
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
        // Mode debug du poste : réglage de contrôle admin (≠ colonnes
        // `agent_*` hors $fillable). Exposé à l'agent dans l'enveloppe
        // desired-state ; pilote aussi les options WPKG debug/logdebug.
        'debug',
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
        // Mode debug du poste (exposé dans l'enveloppe desired-state).
        'debug' => 'boolean',
        // Story 23.2 — cycle de vie du token agent. Les colonnes `agent_*`
        // ne sont volontairement PAS dans $fillable : seules les écritures
        // explicites de TokenRotationService / AuthenticateAgentToken les
        // touchent (anti mass-assignment).
        'agent_token_rotated_at' => 'datetime',
        'agent_last_checkin_at' => 'datetime',
        'agent_quarantined_at' => 'datetime',
        // Story 23.3 — ticket d'enrôlement one-time (porte 1 iPXE). Hors
        // $fillable pour la même raison : seul EnrollmentService écrit.
        'agent_enroll_ticket_expires_at' => 'datetime',
        // Story 24.7 — demande de resynchronisation pendante. Hors $fillable :
        // exactement 2 écrivains (SyncRequestService::request/fulfill).
        'agent_sync_requested_at' => 'datetime',
        // Story 25.5 — version d'agent rapportée (greffe ReportController). Hors
        // $fillable : seul écrivain = ReportController::store() (forceFill). La
        // surface « progression du déploiement » lit ces colonnes (lecture seule).
        'agent_reported_version_at' => 'datetime',
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
     * Relation pivot filtrée vers les groupes logiques (parcs) du poste.
     *
     * Story 4.11 — pendant logique de {@see physicalRooms()} : depuis le pivot
     * global, la salle physique est aussi une ligne de `groups`. Les vues qui
     * affichent les parcs doivent passer par cette relation pour ne pas faire
     * remonter la salle parmi les groupes logiques.
     */
    public function logicalGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            WorkstationGroup::class,
            'workstation_group_workstation',
            'workstation_id',
            'workstation_group_id'
        )->where('workstation_groups.is_physical', false)
            ->withTimestamps();
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
     * Story 23.2 — Vrai si le poste détient un token agent actif
     * (canal desired-state, Epic 23).
     */
    public function isAgentEnrolled(): bool
    {
        return $this->agent_token_hash !== null;
    }

    /**
     * Story 23.2 — Vrai si le poste est en quarantaine anti-clonage
     * (403 AGENT_QUARANTINED sur le canal agent tant que non levée).
     */
    public function isAgentQuarantined(): bool
    {
        return $this->agent_quarantined_at !== null;
    }

    /**
     * Story 24.7 — États COURANTS de conformité par type de ressource,
     * rapportés par l'agent (`agent_resource_states`, upsert 24.1). Lus par
     * l'UI conformité (badge tableau, table « État rapporté par type »).
     */
    public function agentResourceStates(): HasMany
    {
        return $this->hasMany(AgentResourceState::class);
    }

    /**
     * Story 24.7 — Journal append-only des CHANGEMENTS d'état rapportés
     * (`agent_report_events`, 24.1, rétention 14 j). Lu par la sous-section
     * « Derniers événements » de la fiche poste.
     */
    public function agentReportEvents(): HasMany
    {
        return $this->hasMany(AgentReportEvent::class);
    }

    /**
     * Story 24.7 / AC5 — Vrai si une demande « forcer la synchro » est
     * pendante (timestamp posé par {@see \App\Services\Agent\SyncRequestService},
     * soldé au prochain `POST /report`).
     */
    public function hasAgentSyncPending(): bool
    {
        return $this->agent_sync_requested_at !== null;
    }

    /**
     * Story 24.7 / décision n° 7 — Vrai si le poste est « muet » : enrôlé
     * mais aucun check-in récent (dernier check-in > 2 × `agent.ttl_seconds`,
     * clé existante 23.5 — aucune nouvelle clé config). Un poste jamais
     * enrôlé ou jamais checké n'est PAS « muet » (état dérivé distinct :
     * non enrôlé / jamais rapporté).
     */
    public function isAgentSilent(): bool
    {
        if (! $this->isAgentEnrolled() || $this->agent_last_checkin_at === null) {
            return false;
        }

        $ttl = (int) (config('agent.ttl_seconds') ?? 3600);

        return $this->agent_last_checkin_at->lt(now()->subSeconds(2 * $ttl));
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
