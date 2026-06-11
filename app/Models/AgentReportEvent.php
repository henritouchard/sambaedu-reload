<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentResourceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 24.1 — Journal des CHANGEMENTS d'état rapportés par l'agent
 * (D3 : dérive détectée, dérive corrigée, apply échoué — jamais un rapport
 * identique au précédent). Append-only : pas d'updated_at, jamais d'UPDATE.
 *
 * `previous_status` est null au premier rapport du type (pas d'état
 * antérieur). Rétention courte
 * (`config('agent.report_events_retention_days')`, 14 j) — purge
 * quotidienne `agent:reports:prune`.
 */
class AgentReportEvent extends Model
{
    /** Append-only : aucune colonne updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'workstation_id',
        'type',
        'previous_status',
        'status',
        'hash',
        'detail',
        'created_at',
    ];

    protected $casts = [
        'previous_status' => AgentResourceStatus::class,
        'status' => AgentResourceStatus::class,
        'created_at' => 'datetime',
    ];

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }
}
