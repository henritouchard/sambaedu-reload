<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WorkstationGroupSchedule;
use App\Models\WorkstationGroupScheduleRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkstationGroupScheduleRun>
 */
class WorkstationGroupScheduleRunFactory extends Factory
{
    protected $model = WorkstationGroupScheduleRun::class;

    public function definition(): array
    {
        $ranAt = now();

        return [
            'schedule_id' => WorkstationGroupSchedule::factory(),
            'ran_at' => $ranAt,
            'ran_for_time' => $ranAt->format('H:i:s'),
            'ran_for_date' => $ranAt->toDateString(),
            'summary' => [
                'success_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0,
                'task_ids' => [],
                'errors' => [],
            ],
        ];
    }
}
