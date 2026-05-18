<?php

declare(strict_types=1);

namespace Database\Factories\Auth\V1;

use App\Auth\V1\Models\WorkstationJwtRevocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Story 16.10 — AC3.3.
 *
 * Factory pour `WorkstationJwtRevocation`. Génère des entrées de révocation
 * cohérentes (`revoked_at = now`, `expires_at = +24h` parité TTL access).
 *
 * @extends Factory<WorkstationJwtRevocation>
 */
class WorkstationJwtRevocationFactory extends Factory
{
    /** @var class-string<WorkstationJwtRevocation> */
    protected $model = WorkstationJwtRevocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = Carbon::now();

        return [
            'id' => (string) Str::uuid(),
            'jti' => (string) Str::uuid(),
            'workstation_uuid' => (string) Str::uuid(),
            'revoked_at' => $now,
            'reason' => $this->faker->randomElement([
                'manual_admin',
                'lost_device',
                'replay_cascade',
                'rotation_kid',
            ]),
            'revoked_by' => 'system',
            'expires_at' => $now->copy()->addSeconds(86400),
        ];
    }

    /**
     * État : révocation pour un jti précis.
     */
    public function forJti(string $jti): self
    {
        return $this->state(fn (array $attrs): array => [
            'jti' => $jti,
        ]);
    }

    /**
     * État : révocation pour un workstation_uuid précis.
     */
    public function forWorkstation(string $uuid): self
    {
        return $this->state(fn (array $attrs): array => [
            'workstation_uuid' => $uuid,
        ]);
    }

    /**
     * État : déjà expirée (`expires_at < now`) — purgeable.
     */
    public function expired(): self
    {
        return $this->state(fn (array $attrs): array => [
            'revoked_at' => Carbon::now()->subDays(2),
            'expires_at' => Carbon::now()->subDays(1),
        ]);
    }
}
