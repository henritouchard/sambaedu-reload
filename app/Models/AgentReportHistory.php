<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 24.1 — Historique de DÉBOGAGE des rapports agent : payload brut
 * complet, append-only, écrit uniquement quand le flag
 * `config('agent.report_history')` (`AGENT_REPORT_HISTORY`, défaut off)
 * est actif.
 *
 * Table temporaire par design (D3 : retrait prévu à la sortie de
 * débogage) — supprimable d'un bloc sans toucher l'état courant ni le
 * journal. Rétention `config('agent.report_history_retention_days')`
 * (30 j), purge quotidienne `agent:reports:prune`.
 */
class AgentReportHistory extends Model
{
    /** Nom singulier voulu (D3) — pas le pluriel Eloquent `histories`. */
    protected $table = 'agent_report_history';

    /** Append-only : aucune colonne updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'workstation_id',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }
}
