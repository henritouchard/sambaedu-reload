<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 27.12 — factory de projection registry (windows). Par défaut une seule
 * clé HKCU à map on/off ; `keys()` permet de poser une `spec` arbitraire (bundle,
 * littéral, MULTI_SZ…).
 *
 * @extends Factory<CapabilityProjection>
 */
class CapabilityProjectionFactory extends Factory
{
    protected $model = CapabilityProjection::class;

    public function definition(): array
    {
        return [
            'capability_id' => Capability::factory(),
            'os' => 'windows',
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY,
            'spec' => [
                'keys' => [
                    [
                        'hive' => 'HKCU',
                        'path' => 'Software\\Test\\Path',
                        'name' => 'TestValue',
                        'type' => 'REG_DWORD',
                        'value' => ['on' => 1, 'off' => 0],
                    ],
                ],
            ],
        ];
    }

    /**
     * Pose une `spec.keys` arbitraire.
     *
     * @param  list<array<string,mixed>>  $keys
     */
    public function keys(array $keys): self
    {
        return $this->state(fn (): array => ['spec' => ['keys' => $keys]]);
    }
}
