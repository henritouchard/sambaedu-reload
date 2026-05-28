<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workstation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 4.9 — Factory minimale pour Workstation, utilisée par les tests
 * d'observation ({@see \Tests\Feature\Observers\WorkstationObserverTest}).
 *
 * @extends Factory<Workstation>
 */
class WorkstationFactory extends Factory
{
    protected $model = Workstation::class;

    public function definition(): array
    {
        return [
            'name' => 'PC-' . $this->faker->unique()->numberBetween(1000, 99999),
            'uuid' => $this->faker->uuid(),
            'mac' => strtolower($this->faker->macAddress()),
            'status' => 'active',
        ];
    }

    public function inactive(): self
    {
        return $this->state(['status' => 'inactive']);
    }

    public function protected(): self
    {
        return $this->state(['status' => 'protected']);
    }
}
