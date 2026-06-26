<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 28.1 — Factory d'item imposé d'un contrat amont controlHub.
 * Défaut : type `capabilities`, état `locked`, cible `instance`.
 *
 * @extends Factory<ControlHubContractItem>
 */
class ControlHubContractItemFactory extends Factory
{
    protected $model = ControlHubContractItem::class;

    public function definition(): array
    {
        return [
            'controlhub_contract_id' => ControlHubContract::factory(),
            'type' => 'capabilities',
            'key' => 'cap_'.$this->faker->unique()->slug(2),
            'value' => 'on',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            // '' = cible instance (NOT NULL pour que la clé naturelle NFR4 soit effective). [Review 28.1 #1]
            'target_label' => '',
        ];
    }

    /** Item ciblant un label spécifique. */
    public function forLabel(string $labelName): self
    {
        return $this->state(fn (): array => [
            'target_type' => ControlHubContractTarget::Label,
            'target_label' => $labelName,
        ]);
    }

    /** Item en mode permissif. */
    public function permissive(): self
    {
        return $this->state(fn (): array => [
            'enforcement_state' => ControlHubEnforcementState::Permissive,
        ]);
    }

    /** Item absent (explicitement retiré par l'autorité amont). */
    public function absent(): self
    {
        return $this->state(fn (): array => [
            'enforcement_state' => ControlHubEnforcementState::Absent,
            'value' => null,
        ]);
    }
}
