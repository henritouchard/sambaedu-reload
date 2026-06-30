<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NetworkShare;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkShare>
 *
 * Story 34.1 — fabrique de répertoires réseau (tests + tinker en l'absence
 * d'UI 34.2). `letter` null par défaut → le provider auto-assigne ; surcharger
 * avec `->state(['letter' => 'P:'])` pour forcer une lettre.
 */
class NetworkShareFactory extends Factory
{
    protected $model = NetworkShare::class;

    public function definition(): array
    {
        $slug = 'echange_' . fake()->unique()->numerify('####');

        return [
            'name' => ucfirst(fake()->words(2, true)),
            'directory_name' => $slug,
            'label' => null,
            'letter' => null,
            'created_by_user_id' => null,
        ];
    }

    /**
     * Force une lettre de lecteur explicite (ex. `P:`).
     */
    public function withLetter(string $letter): static
    {
        return $this->state(fn (): array => ['letter' => $letter]);
    }
}
