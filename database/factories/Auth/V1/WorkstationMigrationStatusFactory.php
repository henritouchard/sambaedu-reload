<?php

declare(strict_types=1);

namespace Database\Factories\Auth\V1;

use App\Auth\V1\Models\WorkstationMigrationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Story 16.11 — AC6.3.
 *
 * Factory pour `WorkstationMigrationStatus`. Génère un row valide :
 *
 *  - `workstation_uuid` UUID v4 aléatoire.
 *  - `migrated_at` = now.
 *  - `os` aléatoire `windows|linux`.
 *  - `bootstrap_token_hash_prefix` : 16 premiers chars sha256 d'un UUID jetable (traçabilité).
 *
 * États :
 *  - `forUuid(string $uuid)` : fige le workstation_uuid (utile assertion).
 *
 * @extends Factory<WorkstationMigrationStatus>
 */
class WorkstationMigrationStatusFactory extends Factory
{
    /** @var class-string<WorkstationMigrationStatus> */
    protected $model = WorkstationMigrationStatus::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workstation_uuid' => (string) Str::uuid(),
            'migrated_at' => Carbon::now(),
            'access_token_emitted_jti' => (string) Str::uuid(),
            'bootstrap_token_hash_prefix' => substr(hash('sha256', fake()->uuid()), 0, 16),
            'os' => $this->faker->randomElement(['windows', 'linux']),
            'se4fs_name' => 'se4fs-test001',
        ];
    }

    /**
     * État : workstation_uuid figé.
     */
    public function forUuid(string $uuid): self
    {
        return $this->state(fn (array $attrs): array => [
            'workstation_uuid' => $uuid,
        ]);
    }

    /**
     * État : OS figé.
     */
    public function forOs(string $os): self
    {
        return $this->state(fn (array $attrs): array => [
            'os' => $os,
        ]);
    }
}
