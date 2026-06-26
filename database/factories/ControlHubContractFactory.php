<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ControlHubLinkState;
use App\Models\ControlHubContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 28.1 — Factory de contrat amont controlHub (état `active` par défaut).
 *
 * @extends Factory<ControlHubContract>
 */
class ControlHubContractFactory extends Factory
{
    protected $model = ControlHubContract::class;

    public function definition(): array
    {
        return [
            'link_state' => ControlHubLinkState::Active,
            'received_at' => now(),
        ];
    }

    /** Contrat avec lien rompu (severed). */
    public function severed(): self
    {
        return $this->state(fn (): array => [
            'link_state' => ControlHubLinkState::Severed,
        ]);
    }

    /** Contrat sans date de réception (jamais reçu). */
    public function notYetReceived(): self
    {
        return $this->state(fn (): array => [
            'received_at' => null,
        ]);
    }
}
