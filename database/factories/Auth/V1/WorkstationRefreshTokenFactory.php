<?php

declare(strict_types=1);

namespace Database\Factories\Auth\V1;

use App\Auth\V1\Models\WorkstationRefreshToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Story 16.10 — AC3.3.
 *
 * Factory pour `WorkstationRefreshToken`. Génère des fixtures valides pour
 * les tests :
 *
 *  - `issued_at` = now, `expires_at` = +30j (TTL refresh par défaut)
 *  - `refresh_token_hash` = sha256 d'un random unique (jamais le clear)
 *  - `client_meta` = mac + hostname + os synthétiques
 *  - `revoked_at` = null par défaut (état actif)
 *
 * États additionnels exposés :
 *  - `revoked(string $reason = 'manual_admin')` → marque revoked_at = now
 *  - `expired()` → expires_at dans le passé
 *
 * @extends Factory<WorkstationRefreshToken>
 */
class WorkstationRefreshTokenFactory extends Factory
{
    /** @var class-string<WorkstationRefreshToken> */
    protected $model = WorkstationRefreshToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issuedAt = Carbon::now();
        $clear = bin2hex(random_bytes(32));

        return [
            'id' => (string) Str::uuid(),
            'workstation_uuid' => (string) Str::uuid(),
            'refresh_token_hash' => hash('sha256', $clear),
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->copy()->addSeconds(2592000), // 30 jours
            'revoked_at' => null,
            'revocation_reason' => null,
            'last_used_at' => null,
            'client_meta' => [
                'mac' => $this->fakeMac(),
                'hostname' => 'host-' . substr((string) Str::uuid(), 0, 8),
                'os' => $this->faker->randomElement(['windows', 'linux']),
                'enroll_ip' => $this->faker->ipv4(),
            ],
        ];
    }

    /**
     * État : token révoqué.
     */
    public function revoked(string $reason = 'manual_admin'): self
    {
        return $this->state(fn (array $attrs): array => [
            'revoked_at' => Carbon::now(),
            'revocation_reason' => $reason,
        ]);
    }

    /**
     * État : token expiré.
     */
    public function expired(): self
    {
        return $this->state(fn (array $attrs): array => [
            'issued_at' => Carbon::now()->subDays(35),
            'expires_at' => Carbon::now()->subDays(5),
        ]);
    }

    /**
     * État : token avec un hash explicite (pour assertions de lookup).
     */
    public function withHash(string $hash): self
    {
        return $this->state(fn (array $attrs): array => [
            'refresh_token_hash' => $hash,
        ]);
    }

    /**
     * État : token attaché à un workstation_uuid précis.
     */
    public function forWorkstation(string $uuid): self
    {
        return $this->state(fn (array $attrs): array => [
            'workstation_uuid' => $uuid,
        ]);
    }

    /**
     * Génère une MAC fictive ([0-9A-F]{2}:){5}[0-9A-F]{2}.
     */
    private function fakeMac(): string
    {
        $bytes = [];
        for ($i = 0; $i < 6; $i++) {
            $bytes[] = sprintf('%02X', random_int(0, 255));
        }

        return implode(':', $bytes);
    }
}
