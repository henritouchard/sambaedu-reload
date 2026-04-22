<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 4-4 — Audit / historique d'exécution d'une programmation.
 *
 * Shape JSONB summary :
 *  - success_count, failed_count, skipped_count : compteurs par run
 *  - task_ids: int[]  — IDs des MachinePowerActionTask créés (drill-down)
 *  - errors: [{machine_id, machine_name, error_message}]
 *  - drift_seconds?: int — uniquement pour one-shot rattrapé après downtime
 *
 * Rétention 30 jours via commande `parc:prune-group-schedule-runs`.
 *
 * @property int $id
 * @property int|null $schedule_id
 * @property \Carbon\CarbonInterface $ran_at
 * @property \Carbon\CarbonInterface $ran_for_time
 * @property \Carbon\CarbonInterface $ran_for_date
 * @property array $summary
 */
class WorkstationGroupScheduleRun extends Model
{
    use HasFactory;

    protected $table = 'workstation_group_schedule_runs';

    /** @var list<string> */
    protected $fillable = [
        'schedule_id',
        'ran_at',
        'ran_for_time',
        'ran_for_date',
        'summary',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'ran_at' => 'datetime',
        'ran_for_date' => 'date',
        'summary' => 'array',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkstationGroupSchedule::class, 'schedule_id');
    }
}
