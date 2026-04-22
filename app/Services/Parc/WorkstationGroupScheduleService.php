<?php

declare(strict_types=1);

namespace App\Services\Parc;

use App\Models\WorkstationGroupSchedule;
use App\Models\WorkstationGroupScheduleRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 4-4 — Service CRUD + executeDue pour les programmations WorkstationGroup.
 *
 * Architecture `tick → enqueue → worker` :
 *  - La commande artisan `parc:execute-group-schedules` (everyMinute) appelle
 *    `executeDue($now)`.
 *  - `executeDue` lit les candidats via `scopeDueAt()` + filtre PHP `isDueNow()`
 *    (minute-match + dst).
 *  - Pour chaque schedule dû : on appelle `WorkstationGroupService::executeGroupMachinesAction`
 *    avec `initiatedBy='schedule:<id>'` — qui enqueue N `DispatchMachinePowerActionJob`
 *    traités par le worker `laravel-queue-general` habituel (pas de nouveau worker).
 *  - On crée un `WorkstationGroupScheduleRun` par exécution (audit + idempotence).
 *  - Pour les one-shots : dans la même transaction que la création du run,
 *    on met à jour `enabled=false` + `completed_at=ran_at` pour éviter toute
 *    re-fire ultérieure (AC21, AC22).
 *
 * Idempotence multi-couches :
 *  1. Garde `exists()` côté service (ce fichier — méthode `runAlreadyExists`).
 *  2. Index unique DB `(schedule_id, ran_for_date, ran_for_time)` (migration).
 *  3. `withoutOverlapping(5)` côté scheduler (Kernel).
 *  4. Filtre 409 `WorkstationGroupService` (hérité 4.3 — machines déjà en cours).
 */
class WorkstationGroupScheduleService
{
    public function __construct(
        private WorkstationGroupService $groupService,
    ) {
    }

    // ========================================
    // CRUD
    // ========================================

    /**
     * Crée un schedule récurrent.
     *
     * @param list<int> $daysOfWeek ISO 8601 (1=lun … 7=dim)
     */
    public function createRecurring(
        int $groupId,
        string $action,
        array $daysOfWeek,
        string $timeOfDay,
        ?string $timezone = null,
        ?int $createdByUserId = null,
    ): WorkstationGroupSchedule {
        $this->assertActionSupported($action);
        $this->assertDaysOfWeekValid($daysOfWeek);
        $this->assertTimeOfDayValid($timeOfDay);

        $timezone ??= (string) config('app.timezone', 'Europe/Paris');
        $this->assertTimezoneSupported($timezone);

        return WorkstationGroupSchedule::create([
            'workstation_group_id' => $groupId,
            'action' => $action,
            'mode' => WorkstationGroupSchedule::MODE_RECURRING,
            'days_of_week' => array_values(array_map('intval', $daysOfWeek)),
            'time_of_day' => $timeOfDay,
            'timezone' => $timezone,
            'run_at' => null,
            'completed_at' => null,
            'enabled' => true,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    /**
     * Crée un schedule one-shot (date/heure unique, futur).
     *
     * @throws \InvalidArgumentException si run_at <= now
     */
    public function createOneShot(
        int $groupId,
        string $action,
        Carbon|string $runAt,
        ?int $createdByUserId = null,
    ): WorkstationGroupSchedule {
        $this->assertActionSupported($action);

        $carbon = $runAt instanceof Carbon ? $runAt->copy() : Carbon::parse($runAt);

        if ($carbon->lessThanOrEqualTo(Carbon::now())) {
            throw new \InvalidArgumentException("La date d'exécution doit être dans le futur");
        }

        return WorkstationGroupSchedule::create([
            'workstation_group_id' => $groupId,
            'action' => $action,
            'mode' => WorkstationGroupSchedule::MODE_ONE_SHOT,
            'days_of_week' => null,
            'time_of_day' => null,
            'timezone' => null,
            'run_at' => $carbon,
            'completed_at' => null,
            'enabled' => true,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    /**
     * Met à jour un schedule existant.
     *
     * Refuse la mutation sur un one-shot déjà complété (AC23).
     *
     * @param array<string, mixed> $attributes
     * @throws \DomainException si schedule one-shot terminé
     * @throws \InvalidArgumentException si schedule introuvable ou validation KO
     */
    public function update(int $scheduleId, array $attributes): WorkstationGroupSchedule
    {
        $schedule = WorkstationGroupSchedule::findOrFail($scheduleId);

        if (!$schedule->isEditable()) {
            throw new \DomainException('Cette programmation one-shot est terminée et ne peut plus être modifiée.');
        }

        // Validation conditionnelle au mode courant (ou au mode demandé si muté).
        $targetMode = $attributes['mode'] ?? $schedule->mode;

        if (isset($attributes['action'])) {
            $this->assertActionSupported((string) $attributes['action']);
        }

        if ($targetMode === WorkstationGroupSchedule::MODE_RECURRING) {
            if (isset($attributes['days_of_week'])) {
                $this->assertDaysOfWeekValid($attributes['days_of_week']);
                $attributes['days_of_week'] = array_values(array_map('intval', $attributes['days_of_week']));
            }
            if (isset($attributes['time_of_day'])) {
                $this->assertTimeOfDayValid((string) $attributes['time_of_day']);
            }
            if (isset($attributes['timezone'])) {
                $this->assertTimezoneSupported((string) $attributes['timezone']);
            }
        } elseif ($targetMode === WorkstationGroupSchedule::MODE_ONE_SHOT) {
            if (isset($attributes['run_at'])) {
                $runAt = $attributes['run_at'] instanceof Carbon
                    ? $attributes['run_at']
                    : Carbon::parse((string) $attributes['run_at']);
                if ($runAt->lessThanOrEqualTo(Carbon::now())) {
                    throw new \InvalidArgumentException("La date d'exécution doit être dans le futur");
                }
                $attributes['run_at'] = $runAt;
            }
        }

        $schedule->update($attributes);

        return $schedule->fresh();
    }

    /**
     * Toggle enabled (flip). Refuse sur one-shot terminé (AC23).
     *
     * @throws \DomainException si schedule one-shot terminé
     */
    public function toggle(int $scheduleId): WorkstationGroupSchedule
    {
        $schedule = WorkstationGroupSchedule::findOrFail($scheduleId);

        if (!$schedule->isEditable()) {
            throw new \DomainException('Cette programmation one-shot est terminée et ne peut plus être modifiée.');
        }

        $schedule->update(['enabled' => !$schedule->enabled]);

        return $schedule->fresh();
    }

    public function delete(int $scheduleId): void
    {
        WorkstationGroupSchedule::findOrFail($scheduleId)->delete();
    }

    /**
     * Duplique un one-shot (passé ou futur) en nouveau schedule one-shot avec
     * `run_at=null, completed_at=null, enabled=true` — l'UI doit renseigner run_at.
     *
     * @throws \InvalidArgumentException si le schedule source n'est pas un one-shot
     */
    public function cloneOneShot(int $scheduleId, ?int $createdByUserId = null): WorkstationGroupSchedule
    {
        $source = WorkstationGroupSchedule::findOrFail($scheduleId);

        if (!$source->isOneShot()) {
            throw new \InvalidArgumentException('Seuls les schedules one-shot peuvent être dupliqués.');
        }

        // Clone créé désactivé : l'utilisateur doit confirmer la date dans la modale avant d'activer,
        // sinon le placeholder run_at=now+1h firerait spontanément l'action source.
        return WorkstationGroupSchedule::create([
            'workstation_group_id' => $source->workstation_group_id,
            'action' => $source->action,
            'mode' => WorkstationGroupSchedule::MODE_ONE_SHOT,
            'days_of_week' => null,
            'time_of_day' => null,
            'timezone' => null,
            'run_at' => Carbon::now()->addHour(),
            'completed_at' => null,
            'enabled' => false,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    // ========================================
    // Execution (tick scheduler)
    // ========================================

    /**
     * Exécute tous les schedules dus à l'instant `$now`.
     *
     * Algorithme :
     *  1. SELECT candidats via scopeDueAt (enabled + (recurring OR one_shot_due))
     *  2. Filtre PHP isDueNow (minute-match tz-aware pour recurring)
     *  3. Pour chaque schedule :
     *     - Guard exists() : skip si déjà joué pour ce (date, time)
     *     - Résolution machines du groupe au tick (D2 liveness)
     *     - Appel WorkstationGroupService::executeGroupMachinesAction(..., initiatedBy)
     *     - Transaction : create Run + (si one_shot) update enabled=false, completed_at
     *
     * @return array{executed_count: int, total_tasks_dispatched: int, recurring_count: int, one_shot_count: int}
     */
    public function executeDue(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $candidates = WorkstationGroupSchedule::query()
            ->dueAt($now)
            ->with('workstationGroup.workstations')
            ->get();

        $due = $candidates->filter(fn (WorkstationGroupSchedule $s) => $s->isDueNow($now));

        $executed = 0;
        $totalDispatched = 0;
        $recurringCount = 0;
        $oneShotCount = 0;

        foreach ($due as $schedule) {
            $ranForDate = $schedule->getRanForDateForRun($now);
            $ranForTime = $schedule->getRanForTimeForRun($now);

            // Couche 1 — garde exists() (anti double-fire même tick)
            if ($this->runAlreadyExists($schedule->id, $ranForDate, $ranForTime)) {
                continue;
            }

            $group = $schedule->workstationGroup;
            $machineIds = $group
                ? $group->workstations->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [];

            $summary = [
                'success_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0,
                'task_ids' => [],
                'errors' => [],
            ];

            // Drift pour one-shots rattrapés après downtime
            if ($schedule->isOneShot() && $schedule->run_at !== null) {
                $drift = (int) $schedule->run_at->diffInSeconds($now, false);
                if ($drift > 60) {
                    // Drift significatif — consigner (ignorer les < 60s qui sont
                    // le bruit normal de l'ordonnanceur).
                    $summary['drift_seconds'] = $drift;
                }
            }

            if (empty($machineIds)) {
                // Groupe vide — on trace quand même un run (audit) et on marque
                // le one-shot comme complété pour éviter toute re-fire.
                $summary['errors'][] = [
                    'machine_id' => null,
                    'machine_name' => '—',
                    'error_message' => 'Groupe vide — aucune machine à traiter',
                ];
            } else {
                try {
                    $result = $this->groupService->executeGroupMachinesAction(
                        $schedule->workstation_group_id,
                        $machineIds,
                        $schedule->action,
                        'schedule:' . $schedule->id,
                    );

                    foreach ($result['results'] as $row) {
                        $code = (int) ($row['code'] ?? 0);
                        $machineName = (string) ($row['machine'] ?? 'unknown');

                        if ($code === 202) {
                            $summary['success_count']++;
                            if (isset($row['task_id'])) {
                                $summary['task_ids'][] = (int) $row['task_id'];
                            }
                        } elseif ($code === 409) {
                            $summary['skipped_count']++;
                        } else {
                            $summary['failed_count']++;
                            $summary['errors'][] = [
                                'machine_id' => null,
                                'machine_name' => $machineName,
                                'error_message' => (string) ($row['reason'] ?? 'unknown'),
                            ];
                        }
                    }

                    $totalDispatched += $summary['success_count'];
                } catch (\Throwable $e) {
                    Log::error('[WorkstationGroupScheduleService] executeGroupMachinesAction a échoué', [
                        'schedule_id' => $schedule->id,
                        'group_id' => $schedule->workstation_group_id,
                        'action' => $schedule->action,
                        'error' => $e->getMessage(),
                    ]);
                    $summary['errors'][] = [
                        'machine_id' => null,
                        'machine_name' => '—',
                        'error_message' => 'Exception service : ' . $e->getMessage(),
                    ];
                    $summary['failed_count'] = count($machineIds);
                }
            }

            // Transaction : create run + (si one_shot) update enabled=false, completed_at
            DB::transaction(function () use ($schedule, $now, $ranForDate, $ranForTime, $summary) {
                try {
                    WorkstationGroupScheduleRun::create([
                        'schedule_id' => $schedule->id,
                        'ran_at' => $now,
                        'ran_for_time' => $ranForTime,
                        'ran_for_date' => $ranForDate,
                        'summary' => $summary,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Couche 2 — violation unique index = double-fire intercepté
                    // en race (autre worker), on log et on continue.
                    Log::info('[WorkstationGroupScheduleService] Run en double évité par index unique', [
                        'schedule_id' => $schedule->id,
                        'ran_for_date' => $ranForDate,
                        'ran_for_time' => $ranForTime,
                    ]);
                    return;
                }

                if ($schedule->isOneShot()) {
                    $schedule->update([
                        'enabled' => false,
                        'completed_at' => $now,
                    ]);
                }
            });

            $executed++;
            if ($schedule->isRecurring()) {
                $recurringCount++;
            } else {
                $oneShotCount++;
            }
        }

        return [
            'executed_count' => $executed,
            'total_tasks_dispatched' => $totalDispatched,
            'recurring_count' => $recurringCount,
            'one_shot_count' => $oneShotCount,
        ];
    }

    /**
     * Purge des runs > N jours (défaut 30). Utilisé par `parc:prune-group-schedule-runs`.
     */
    public function pruneRuns(int $retentionDays = 30): int
    {
        return WorkstationGroupScheduleRun::query()
            ->where('created_at', '<', Carbon::now()->subDays($retentionDays))
            ->delete();
    }

    // ========================================
    // Helpers privés
    // ========================================

    private function runAlreadyExists(int $scheduleId, string $ranForDate, string $ranForTime): bool
    {
        // whereDate/whereTime pour portabilité SQLite (qui stocke DATE comme
        // TIMESTAMP "YYYY-MM-DD 00:00:00") et PostgreSQL (DATE natif).
        return WorkstationGroupScheduleRun::query()
            ->where('schedule_id', $scheduleId)
            ->whereDate('ran_for_date', $ranForDate)
            ->where('ran_for_time', $ranForTime)
            ->exists();
    }

    private function assertActionSupported(string $action): void
    {
        if (!in_array($action, WorkstationGroupSchedule::SUPPORTED_ACTIONS, true)) {
            throw new \InvalidArgumentException(sprintf(
                "Action non supportée: %s (autorisées: %s)",
                $action,
                implode(', ', WorkstationGroupSchedule::SUPPORTED_ACTIONS)
            ));
        }
    }

    /**
     * @param list<int> $daysOfWeek
     */
    private function assertDaysOfWeekValid(array $daysOfWeek): void
    {
        if (count($daysOfWeek) < 1 || count($daysOfWeek) > 7) {
            throw new \InvalidArgumentException('Au moins un jour de la semaine est requis (max 7).');
        }
        foreach ($daysOfWeek as $day) {
            $d = (int) $day;
            if ($d < 1 || $d > 7) {
                throw new \InvalidArgumentException('Chaque jour doit être entre 1 (lundi) et 7 (dimanche).');
            }
        }
    }

    private function assertTimeOfDayValid(string $time): void
    {
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            throw new \InvalidArgumentException('Le format horaire doit être HH:MM ou HH:MM:SS.');
        }
        [$h, $m] = array_map('intval', explode(':', $time));
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            throw new \InvalidArgumentException('Heure invalide (00:00 — 23:59).');
        }
    }

    private function assertTimezoneSupported(string $timezone): void
    {
        if (!in_array($timezone, WorkstationGroupSchedule::SUPPORTED_TIMEZONES, true)) {
            throw new \InvalidArgumentException(sprintf(
                "Timezone non supportée: %s (France métropole et DOM-TOM uniquement).",
                $timezone
            ));
        }
    }
}
