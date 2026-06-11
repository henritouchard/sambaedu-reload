<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentResourceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 24.1 — État de conformité COURANT d'un type de ressource sur un
 * poste, rapporté par l'agent (`POST /api/v1/agent/report`, D3/FR9).
 *
 * Upsert par (workstation_id, type) — UNIQUE en base : le volume est borné
 * structurellement à postes × types. Écrit UNIQUEMENT par
 * {@see \App\Services\Agent\Reporting\ReportIngestService} ; lu par l'UI
 * conformité (24.5/FR10).
 *
 * `reported_at` est rafraîchi à CHAQUE rapport, même identique (fraîcheur
 * du dernier check-in de contenu — donnée UI) ; seul le journal
 * {@see AgentReportEvent} est conditionné au changement.
 */
class AgentResourceState extends Model
{
    protected $fillable = [
        'workstation_id',
        'type',
        'status',
        'hash',
        'detail',
        'reported_at',
    ];

    protected $casts = [
        'status' => AgentResourceStatus::class,
        'reported_at' => 'datetime',
    ];

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }
}
