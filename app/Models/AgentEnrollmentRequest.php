<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 25.3 — Demande d'enrôlement porte 2 (poste migré sans ticket, FR16).
 *
 * Une ligne = une demande d'un poste migré qui rejoue son
 * `POST /v1/agent/enrollment` sans ticket. Le faisceau de preuves
 * (`mac`/`hostname`/`uuid`) est ce que le poste présente ; `matched_workstation_id`
 * est le poste connu rapproché par {@see \App\Services\Agent\Enrollment\EnrollmentMatchService}
 * (null si inconnu). `status` est un domaine fermé (`pending`|`approved`|`rejected`)
 * validé en code (varchar non appliqué par SQLite).
 *
 * Écrit UNIQUEMENT par {@see \App\Services\Agent\Enrollment\EnrollmentService}
 * (création/refresh au redeem porte 2, approbation/rejet manuel ou auto,
 * consommation à la naissance du token). Lu par la surface UI d'approbation
 * (Livewire `admin/settings/agent`). Le rapprochement LIT `workstations`
 * (lecture seule) ; cette table n'écrit jamais dans AD.
 *
 * @property int $id
 * @property string|null $mac
 * @property string|null $hostname
 * @property string|null $uuid
 * @property int|null $matched_workstation_id
 * @property string $status
 * @property bool $auto_approved
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property int|null $resolved_by
 */
class AgentEnrollmentRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'mac',
        'hostname',
        'uuid',
        'matched_workstation_id',
        'status',
        'auto_approved',
        'last_seen_at',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'auto_approved' => 'boolean',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Poste connu rapproché par le faisceau (null si demande non rapprochée).
     */
    public function matchedWorkstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class, 'matched_workstation_id');
    }

    /**
     * Demandes en attente d'une décision admin (file d'approbation de l'UI).
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
