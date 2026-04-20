<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WorkstationGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkstationGroup>
 */
class WorkstationGroupFactory extends Factory
{
    protected $model = WorkstationGroup::class;

    public function definition(): array
    {
        return [
            'name' => 'salle_' . fake()->unique()->numerify('###'),
            'display_name' => fake()->words(2, true),
            'is_physical' => true,
            'is_active' => true,
        ];
    }

    public function logical(): static
    {
        return $this->state(fn() => ['is_physical' => false]);
    }
}
