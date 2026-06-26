<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ControlHubContract;
use App\Models\ControlHubContractImposedGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 28.1 — Factory de groupe imposé d'un contrat amont controlHub.
 * Défaut : sans label réservé associé.
 *
 * @extends Factory<ControlHubContractImposedGroup>
 */
class ControlHubContractImposedGroupFactory extends Factory
{
    protected $model = ControlHubContractImposedGroup::class;

    public function definition(): array
    {
        return [
            'controlhub_contract_id' => ControlHubContract::factory(),
            'name' => 'group-'.$this->faker->unique()->slug(2),
            'label_name' => null,
        ];
    }

    /** Groupe imposé portant un label réservé. */
    public function withLabel(string $labelName): self
    {
        return $this->state(fn (): array => [
            'label_name' => $labelName,
        ]);
    }
}
