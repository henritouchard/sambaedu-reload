<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UserGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserGroup>
 */
class UserGroupFactory extends Factory
{
    protected $model = UserGroup::class;

    public function definition(): array
    {
        return [
            'name' => 'classe_' . fake()->unique()->numerify('###'),
            'display_name' => fake()->words(2, true),
            'type' => 'classe',
        ];
    }
}
