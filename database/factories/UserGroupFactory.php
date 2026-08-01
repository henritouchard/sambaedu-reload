<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UserGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

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

    /**
     * Story 49.1 (AC1) — état « ce groupe PORTE un profil de droits ».
     *
     * L'appartenance à un groupe dans cet état matérialise le rôle Spatie chez
     * ses membres (réconciliation `GroupRightsProfileService`).
     */
    public function carrying(Role|int $role): static
    {
        return $this->state(fn(array $attributes) => [
            'rights_profile_id' => $role instanceof Role ? $role->id : $role,
        ]);
    }
}
