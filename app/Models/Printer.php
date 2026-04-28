<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Story 6.1 — Modèle SER pour les imprimantes CUPS.
 *
 * Couche métier complémentaire à CUPS (pas un remplacement) :
 *  - PK string `cups_name` (15 chars max, `[a-zA-Z0-9_-]{1,15}`).
 *  - Audit : `created_at`/`updated_at`/`created_by_user_id`.
 *  - Drift : `orphan` (true = SER seul, CUPS l'a perdue).
 *  - Métadata métier : `description_ser` distincte de la description CUPS.
 *  - Rattachement N:N à `WorkstationGroup` via pivot `printer_workstation_group`.
 *
 * Source de vérité runtime (nom, URI, état, file, PPD) : CUPS via
 * `App\Services\Print\CupsPrinterService`. Cette table porte uniquement les
 * dimensions que CUPS ne sait pas exposer (audit + rattachement + drift).
 */
class Printer extends Model
{
    use HasFactory;

    protected $table = 'printers';
    protected $primaryKey = 'cups_name';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'cups_name',
        'created_by_user_id',
        'orphan',
        'description_ser',
    ];

    protected $casts = [
        'orphan' => 'boolean',
    ];

    // ========================================================================
    // RELATIONS
    // ========================================================================

    /**
     * Parcs rattachés à cette imprimante (N:N).
     */
    public function workstationGroups(): BelongsToMany
    {
        // Pas de `withTimestamps()` : la table pivot porte uniquement
        // `attached_at` (timestamp explicite) et `attached_by_user_id`, pas
        // les colonnes `created_at`/`updated_at` standard. Voir migration
        // `2026_04_27_120100_create_printer_workstation_group_table.php`.
        return $this->belongsToMany(
            WorkstationGroup::class,
            'printer_workstation_group',
            'cups_name',
            'workstation_group_id',
        )
            ->withPivot('attached_at', 'attached_by_user_id');
    }

    /**
     * Utilisateur ayant créé l'entrée SER (null si créée par `printers:sync`).
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Imprimantes non-orphan (présentes dans CUPS au dernier sync).
     */
    public function scopeNonOrphan(Builder $query): Builder
    {
        return $query->where('orphan', false);
    }

    /**
     * Imprimantes orphan (en SER mais absentes de CUPS).
     */
    public function scopeOrphans(Builder $query): Builder
    {
        return $query->where('orphan', true);
    }

    /**
     * Filtre les imprimantes visibles par un utilisateur donné.
     *
     * Hiérarchie :
     *  - `server.admin` global → toutes (no-op).
     *  - Sinon → uniquement celles ayant ≥ 1 rattachement à un `WorkstationGroup`
     *    autorisé par `PermissionService::getAuthorizedWorkstationGroups($user, 'server.admin')`.
     *
     * Note : la matrice profiles-rights-matrix.md ne définit pas de permission
     * `printer.manage` distincte. La policy 7.2 a couché toutes les actions sur
     * `server.admin` (aligné legacy `SE_ADMIN`). Pour le scope délégué, on
     * réutilise donc `server.admin` comme nom de permission scopable.
     */
    public function scopeForUser(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0'); // aucun accès
        }

        if ($user->can('server.admin')) {
            return $query;
        }

        $authorized = app(PermissionService::class)
            ->getAuthorizedWorkstationGroups($user, 'server.admin');

        if ($authorized->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $authorizedIds = $authorized->pluck('id')->all();

        return $query->whereHas(
            'workstationGroups',
            fn(Builder $q) => $q->whereIn('workstation_groups.id', $authorizedIds),
        );
    }
}
