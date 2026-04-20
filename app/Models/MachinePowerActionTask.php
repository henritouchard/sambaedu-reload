<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suivi d'état d'une action power dispatchée en asynchrone (story 4-2, review #1/#2).
 *
 * Utilisé par :
 *  - le composant Livewire MachineShow (pages/parc/machines/[id]/index.blade.php)
 *    qui crée une ligne `status=queued` avant de dispatcher le job, puis
 *    interroge le statut via `wire:poll.{N}s="pollMachineReadiness"` ;
 *  - le job App\Jobs\DispatchMachinePowerActionJob qui transitionne l'état
 *    queued → dispatched → running → completed|failed ;
 *  - l'audit trail (les lignes sont conservées indéfiniment, elles ne sont pas
 *    purgées automatiquement — à faire dans une story de maintenance dédiée).
 *
 * Pour `action = 'restart'`, la colonne `restart_phase` porte la machine à états :
 *  - 'waiting-down' (valeur initiale) : on attend que la machine cesse de répondre
 *  - 'waiting-up'                     : la machine a cessé de répondre, on attend son retour
 *  - null                             : non applicable (autres actions)
 *
 * @property int $id
 * @property int|null $workstation_id
 * @property string $action
 * @property string $status
 * @property string|null $initiated_by
 * @property \Illuminate\Support\Carbon $initiated_at
 * @property \Illuminate\Support\Carbon|null $dispatched_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property array|null $result
 * @property string|null $error_message
 * @property string|null $restart_phase
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class MachinePowerActionTask extends Model
{
    protected $table = 'machine_power_action_tasks';

    /** @var list<string> */
    protected $fillable = [
        'workstation_id',
        'action',
        'status',
        'initiated_by',
        'initiated_at',
        'dispatched_at',
        'completed_at',
        'result',
        'error_message',
        'restart_phase',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'initiated_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
        'result' => 'array',
    ];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const RESTART_PHASE_WAITING_DOWN = 'waiting-down';
    public const RESTART_PHASE_WAITING_UP = 'waiting-up';

    /** Valeurs actives (pour lesquelles on continue à poller). */
    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_DISPATCHED,
        self::STATUS_RUNNING,
    ];

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
