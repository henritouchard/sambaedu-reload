<?php

declare(strict_types=1);

namespace Database\Factories\Auth\V1;

use App\Auth\V1\Models\WorkstationMigrationAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Story 16.11 — AC6.3.
 *
 * Factory pour `WorkstationMigrationAttempt`. Génère un row valide :
 *  - status par défaut = `enrolled` (cas heureux le plus fréquent).
 *  - started_at = now.
 *
 * États :
 *  - `started()`        : status='started', uuid nullable.
 *  - `succeeded()`      : status='enrolled', finished_at=now.
 *  - `failed()`         : status='failed', error_code default.
 *  - `forUuid($uuid)`   : fige le workstation_uuid.
 *  - `withErrorCode($c)`: fige un error_code.
 *
 * @extends Factory<WorkstationMigrationAttempt>
 */
class WorkstationMigrationAttemptFactory extends Factory
{
    /** @var class-string<WorkstationMigrationAttempt> */
    protected $model = WorkstationMigrationAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = Carbon::now();

        return [
            'workstation_uuid' => (string) Str::uuid(),
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy(),
            'status' => WorkstationMigrationAttempt::STATUS_ENROLLED,
            'error_code' => null,
            'error_message' => null,
            'client_ip' => $this->faker->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Test)',
            'os' => $this->faker->randomElement(['windows', 'linux']),
        ];
    }

    /**
     * État : status=started (workstation_uuid nullable car pas encore connu).
     */
    public function started(): self
    {
        return $this->state(fn (array $attrs): array => [
            'workstation_uuid' => null,
            'status' => WorkstationMigrationAttempt::STATUS_STARTED,
            'finished_at' => null,
        ]);
    }

    /**
     * État : status=enrolled (cas heureux explicite).
     */
    public function succeeded(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => WorkstationMigrationAttempt::STATUS_ENROLLED,
            'finished_at' => Carbon::now(),
        ]);
    }

    /**
     * État : status=failed avec code d'erreur par défaut.
     */
    public function failed(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => WorkstationMigrationAttempt::STATUS_FAILED,
            'finished_at' => Carbon::now(),
            'error_code' => 'bootstrap_token.uuid_mismatch',
            'error_message' => 'Sample failure',
        ]);
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
     * État : error_code figé.
     */
    public function withErrorCode(string $code): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => WorkstationMigrationAttempt::STATUS_FAILED,
            'error_code' => $code,
            'finished_at' => Carbon::now(),
        ]);
    }
}
