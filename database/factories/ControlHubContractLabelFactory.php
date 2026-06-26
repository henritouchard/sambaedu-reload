<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ControlHubLabelMode;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractLabel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 28.1 — Factory de label d'un contrat amont controlHub.
 * Défaut : mode `free`.
 *
 * @extends Factory<ControlHubContractLabel>
 */
class ControlHubContractLabelFactory extends Factory
{
    protected $model = ControlHubContractLabel::class;

    public function definition(): array
    {
        return [
            'controlhub_contract_id' => ControlHubContract::factory(),
            'name' => 'label-'.$this->faker->unique()->slug(2),
            'mode' => ControlHubLabelMode::Free,
        ];
    }

    /** Label en mode réservé (porté par un groupe imposé). */
    public function reserved(): self
    {
        return $this->state(fn (): array => [
            'mode' => ControlHubLabelMode::Reserved,
        ]);
    }
}
