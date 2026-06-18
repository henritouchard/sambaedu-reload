<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentResourceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 27.5 — AC4 : inventaire PAR POSTE d'une application WPKG, rapporté par
 * l'agent (champ additif `inventory` sur l'item `applications`,
 * {@see \App\Services\Agent\Reporting\ReportIngestService}).
 *
 * Upsert par (workstation_id, app_id) — UNIQUE en base : volume borné à
 * postes × apps affectées. Écrit UNIQUEMENT par le ReportIngestService ;
 * fondation des LICENCES À POOL (lecture future — pas d'UI en 27.5).
 *
 * `status` ∈ {compliant, drift, error} : compliant/drift = installé,
 * error = non installé (comptage de sièges = lignes compliant/drift).
 * `reported_at` rafraîchi à CHAQUE rapport (fraîcheur). DONNÉE additive sous la
 * ligne d'état par type ({@see AgentResourceState}), JAMAIS un verdict per-app
 * (grain 27.8 intact — Décision D1).
 */
class AgentApplicationInventory extends Model
{
    protected $table = 'agent_application_inventory';

    protected $fillable = [
        'workstation_id',
        'app_id',
        'status',
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
