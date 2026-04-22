<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 4-4 — Programmation horaire sur un WorkstationGroup.
 *
 * Deux modes mutuellement exclusifs (D7, contrainte CHECK DB) :
 *  - `recurring` : triplet `days_of_week` (ISO 8601 SMALLINT[]) + `time_of_day` + `timezone`.
 *    Exécution à chaque minute où dayOfWeekIso ∈ days_of_week et now matche
 *    `time_of_day` à la minute près (dans la timezone du schedule).
 *  - `one_shot` : `run_at` TIMESTAMPTZ unique futur. Auto-complétion :
 *    `enabled=false` + `completed_at=ran_at` après exécution. Ne re-fire jamais.
 *
 * Actions MVP (D5) : `wake` + `shutdown` seulement.
 * Idempotence : 4 couches (CHECK DB + index unique sur runs + garde `exists()`
 * service + `withoutOverlapping(5)` scheduler).
 *
 * @property int $id
 * @property int $workstation_group_id
 * @property string $action
 * @property string $mode
 * @property array<int>|null $days_of_week
 * @property \Carbon\CarbonInterface|null $time_of_day
 * @property string|null $timezone
 * @property \Carbon\CarbonInterface|null $run_at
 * @property \Carbon\CarbonInterface|null $completed_at
 * @property bool $enabled
 * @property int|null $created_by_user_id
 * @property \Carbon\CarbonInterface $created_at
 * @property \Carbon\CarbonInterface $updated_at
 */
class WorkstationGroupSchedule extends Model
{
    use HasFactory;

    protected $table = 'workstation_group_schedules';

    /** @var list<string> */
    protected $fillable = [
        'workstation_group_id',
        'action',
        'mode',
        'days_of_week',
        'time_of_day',
        'timezone',
        'run_at',
        'completed_at',
        'enabled',
        'created_by_user_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'days_of_week' => \App\Casts\PostgresIntArray::class,
        'time_of_day' => 'datetime:H:i:s',
        'run_at' => 'datetime',
        'completed_at' => 'datetime',
        'enabled' => 'boolean',
    ];

    public const MODE_RECURRING = 'recurring';
    public const MODE_ONE_SHOT = 'one_shot';

    public const ACTION_WAKE = 'wake';
    public const ACTION_SHUTDOWN = 'shutdown';

    /** @var list<string> */
    public const SUPPORTED_ACTIONS = [self::ACTION_WAKE, self::ACTION_SHUTDOWN];

    /** @var list<string> */
    public const SUPPORTED_MODES = [self::MODE_RECURRING, self::MODE_ONE_SHOT];

    /**
     * Timezones autorisées : France métropolitaine + DOM-TOM + UTC.
     * Liste courte validée côté FormRequest via Rule::in() (Q3 review 4.4).
     *
     * @var list<string>
     */
    public const SUPPORTED_TIMEZONES = [
        'Europe/Paris',
        'America/Martinique',
        'America/Guadeloupe',
        'America/Cayenne',
        'America/Miquelon',
        'Indian/Reunion',
        'Indian/Mayotte',
        'Pacific/Noumea',
        'Pacific/Tahiti',
        'Pacific/Marquesas',
        'Pacific/Gambier',
        'Pacific/Wallis',
        'Antarctica/Kerguelen',
        'UTC',
    ];

    /**
     * Libellés FR des timezones autorisées (pour l'UI select).
     *
     * @return array<string, string>
     */
    public static function timezoneLabels(): array
    {
        return [
            'Europe/Paris' => 'France métropolitaine (Europe/Paris)',
            'America/Martinique' => 'Martinique',
            'America/Guadeloupe' => 'Guadeloupe',
            'America/Cayenne' => 'Guyane',
            'America/Miquelon' => 'Saint-Pierre-et-Miquelon',
            'Indian/Reunion' => 'La Réunion',
            'Indian/Mayotte' => 'Mayotte',
            'Pacific/Noumea' => 'Nouvelle-Calédonie',
            'Pacific/Tahiti' => 'Polynésie française (Tahiti)',
            'Pacific/Marquesas' => 'Polynésie française (Marquises)',
            'Pacific/Gambier' => 'Polynésie française (Gambier)',
            'Pacific/Wallis' => 'Wallis-et-Futuna',
            'Antarctica/Kerguelen' => 'TAAF (Kerguelen)',
            'UTC' => 'UTC',
        ];
    }

    // ========================================
    // Relations
    // ========================================

    public function workstationGroup(): BelongsTo
    {
        return $this->belongsTo(WorkstationGroup::class, 'workstation_group_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkstationGroupScheduleRun::class, 'schedule_id');
    }

    // ========================================
    // Scopes (D7)
    // ========================================

    public function scopeRecurring(Builder $query): Builder
    {
        return $query->where('mode', self::MODE_RECURRING);
    }

    public function scopeOneShot(Builder $query): Builder
    {
        return $query->where('mode', self::MODE_ONE_SHOT);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('completed_at');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    /**
     * Candidats potentiellement dus à l'instant `$now`.
     *
     * SQL-level filter :
     *  - `enabled = true`
     *  - (mode = recurring) OU (mode = one_shot AND completed_at IS NULL AND run_at <= now)
     *
     * Le matching minute-précis pour les récurrents (jour de semaine + heure)
     * se fait côté PHP via `isDueNow()` — TIME + timezone par schedule n'est
     * pas trivial à exprimer en SQL portable.
     */
    public function scopeDueAt(Builder $query, Carbon $now): Builder
    {
        return $query->where('enabled', true)
            ->where(function (Builder $q) use ($now) {
                $q->where('mode', self::MODE_RECURRING)
                    ->orWhere(function (Builder $qq) use ($now) {
                        $qq->where('mode', self::MODE_ONE_SHOT)
                            ->whereNull('completed_at')
                            ->where('run_at', '<=', $now);
                    });
            });
    }

    // ========================================
    // Helpers (D7)
    // ========================================

    public function isRecurring(): bool
    {
        return $this->mode === self::MODE_RECURRING;
    }

    public function isOneShot(): bool
    {
        return $this->mode === self::MODE_ONE_SHOT;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /** Un one-shot terminé n'est plus éditable (AC23). */
    public function isEditable(): bool
    {
        return !($this->isOneShot() && $this->isCompleted());
    }

    /**
     * Matching fin minute-courante selon le mode.
     *
     * Recurring : dayOfWeekIso ∈ days_of_week ET `now ∈ [time_of_day, time_of_day + 1min)`
     * dans la timezone du schedule (robuste DST — AC11).
     *
     * One-shot : run_at <= now (+ pas complété + enabled true) — ramassé au
     * premier tick qui voit la fenêtre. Catch-up post-downtime automatique.
     */
    public function isDueNow(Carbon $now): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->isOneShot()) {
            return $this->run_at !== null
                && $this->completed_at === null
                && $this->run_at->lessThanOrEqualTo($now);
        }

        // Récurrent — tz-aware.
        if (empty($this->days_of_week) || $this->time_of_day === null || empty($this->timezone)) {
            return false;
        }

        $localNow = $now->copy()->setTimezone($this->timezone);

        if (!in_array($localNow->dayOfWeekIso, $this->days_of_week, true)) {
            return false;
        }

        // On reconstruit la date cible "aujourd'hui à time_of_day" dans la timezone
        // du schedule pour éviter les pièges DST (on ne compare pas des strings
        // "H:i" sur un now UTC — bug classique).
        $schedTime = Carbon::parse(
            $this->time_of_day->format('H:i:s'),
            $this->timezone
        )->setDate($localNow->year, $localNow->month, $localNow->day);

        return $localNow->greaterThanOrEqualTo($schedTime)
            && $localNow->lessThan($schedTime->copy()->addMinute());
    }

    /**
     * Clé d'idempotence `ran_for_time` pour `WorkstationGroupScheduleRun`.
     *
     * Récurrent : heure du créneau (time_of_day).
     * One-shot : heure extraite de run_at.
     */
    public function getRanForTimeForRun(Carbon $now): string
    {
        if ($this->isOneShot()) {
            return $this->run_at !== null
                ? $this->run_at->copy()->setTimezone($this->timezone ?? config('app.timezone'))->format('H:i:s')
                : $now->format('H:i:s');
        }

        return $this->time_of_day !== null
            ? $this->time_of_day->format('H:i:s')
            : $now->format('H:i:s');
    }

    /**
     * Clé d'idempotence `ran_for_date`.
     *
     * Récurrent : date du tick (dans tz du schedule).
     * One-shot  : date extraite de run_at (dans tz du schedule ou app).
     */
    public function getRanForDateForRun(Carbon $now): string
    {
        if ($this->isOneShot()) {
            return $this->run_at !== null
                ? $this->run_at->copy()->setTimezone($this->timezone ?? config('app.timezone'))->toDateString()
                : $now->toDateString();
        }

        return $now->copy()
            ->setTimezone($this->timezone ?? config('app.timezone'))
            ->toDateString();
    }
}
