<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 28.1 — Factory d'application du catalogue d'un contrat amont controlHub.
 *
 * @extends Factory<ControlHubContractCatalogApp>
 */
class ControlHubContractCatalogAppFactory extends Factory
{
    protected $model = ControlHubContractCatalogApp::class;

    public function definition(): array
    {
        return [
            'controlhub_contract_id' => ControlHubContract::factory(),
            'app_key' => 'app-'.$this->faker->unique()->slug(2),
            'display_name' => $this->faker->words(2, true),
        ];
    }

    /** App de catalogue sans nom d'affichage. */
    public function withoutDisplayName(): self
    {
        return $this->state(fn (): array => [
            'display_name' => null,
        ]);
    }
}
