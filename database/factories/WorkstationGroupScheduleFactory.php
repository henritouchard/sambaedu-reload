<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WorkstationGroup;
use App\Models\WorkstationGroupSchedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 4-4 — Factory schedule avec états recurring / oneShot / completedOneShot.
 *
 * @extends Factory<WorkstationGroupSchedule>
 */
class WorkstationGroupScheduleFactory extends Factory
{
    protected $model = WorkstationGroupSchedule::class;

    public function definition(): array
    {
        // Par défaut : schedule récurrent Lun-Ven 08:30 Europe/Paris, wake.
        return [
            'workstation_group_id' => WorkstationGroup::factory(),
            'action' => WorkstationGroupSchedule::ACTION_WAKE,
            'mode' => WorkstationGroupSchedule::MODE_RECURRING,
            'days_of_week' => [1, 2, 3, 4, 5],
            'time_of_day' => '08:30:00',
            'timezone' => 'Europe/Paris',
            'run_at' => null,
            'completed_at' => null,
            'enabled' => true,
            'created_by_user_id' => null,
        ];
    }

    /**
     * @param list<int> $daysOfWeek ISO 8601 (1=lun … 7=dim)
     */
    public function recurring(
        array $daysOfWeek = [1, 2, 3, 4, 5],
        string $time = '08:30:00',
        string $tz = 'Europe/Paris'
    ): static {
        return $this->state(fn () => [
            'mode' => WorkstationGroupSchedule::MODE_RECURRING,
            'days_of_week' => $daysOfWeek,
            'time_of_day' => $time,
            'timezone' => $tz,
            'run_at' => null,
            'completed_at' => null,
        ]);
    }

    /**
     * Schedule one-shot futur (par défaut +1h).
     */
    public function oneShot(Carbon|string $runAt = '+1 hour'): static
    {
        $carbon = $runAt instanceof Carbon ? $runAt : Carbon::parse($runAt);

        return $this->state(fn () => [
            'mode' => WorkstationGroupSchedule::MODE_ONE_SHOT,
            'days_of_week' => null,
            'time_of_day' => null,
            'timezone' => null,
            'run_at' => $carbon,
            'completed_at' => null,
            'enabled' => true,
        ]);
    }

    /**
     * One-shot déjà exécuté (completed_at renseigné, enabled=false).
     */
    public function completedOneShot(Carbon|string $ranAt = '-1 hour'): static
    {
        $carbon = $ranAt instanceof Carbon ? $ranAt : Carbon::parse($ranAt);

        return $this->state(fn () => [
            'mode' => WorkstationGroupSchedule::MODE_ONE_SHOT,
            'days_of_week' => null,
            'time_of_day' => null,
            'timezone' => null,
            'run_at' => $carbon,
            'completed_at' => $carbon,
            'enabled' => false,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    public function shutdown(): static
    {
        return $this->state(fn () => ['action' => WorkstationGroupSchedule::ACTION_SHUTDOWN]);
    }
}
