<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DhcpReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DhcpReservation>
 */
class DhcpReservationFactory extends Factory
{
    protected $model = DhcpReservation::class;

    public function definition(): array
    {
        // MAC pseudo-aléatoire format `xx:xx:xx:xx:xx:xx` lowercase (compat
        // `DhcpService::validateMac()` qui exige ce format normalisé).
        $bytes = [];
        for ($i = 0; $i < 6; $i++) {
            $bytes[] = sprintf('%02x', fake()->numberBetween(0, 255));
        }

        return [
            'name' => 'host' . fake()->unique()->numerify('######'),
            'mac' => implode(':', $bytes),
            'ip' => '10.0.' . fake()->numberBetween(0, 254) . '.' . fake()->numberBetween(1, 254),
            'workstation_id' => null,
            'description' => null,
            'source' => DhcpReservation::SOURCE_MANUAL,
        ];
    }

    public function fromImport(): static
    {
        return $this->state(fn () => ['source' => DhcpReservation::SOURCE_IMPORT]);
    }

    public function fromLegacy(): static
    {
        return $this->state(fn () => ['source' => DhcpReservation::SOURCE_LEGACY_MIGRATION]);
    }
}
