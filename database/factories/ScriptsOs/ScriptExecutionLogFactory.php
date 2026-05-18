<?php

declare(strict_types=1);

namespace Database\Factories\ScriptsOs;

use App\ScriptsOs\Enums\ScriptExecutionAction;
use App\ScriptsOs\Enums\ScriptExecutionOs;
use App\ScriptsOs\Enums\ScriptExecutionSource;
use App\ScriptsOs\Enums\ScriptExecutionStatus;
use App\ScriptsOs\Models\ScriptExecutionLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Story 16.12 — AC1.4.
 *
 * Factory pour `ScriptExecutionLog`. Génère des fixtures riches avec états
 * conditionnels (`failed`, `timeout`, `skipped`, `archived`, etc.).
 *
 * @extends Factory<ScriptExecutionLog>
 */
class ScriptExecutionLogFactory extends Factory
{
    /** @var class-string<ScriptExecutionLog> */
    protected $model = ScriptExecutionLog::class;

    /**
     * @return array<string,mixed>
     */
    public function definition(): array
    {
        // Par défaut : exécution succeeded récente (< 24h), correlation_id
        // présent (dedupe testable).
        $startedAt = Carbon::now()->subMinutes($this->faker->numberBetween(1, 1440));

        return [
            'id' => (string) Str::uuid(),
            'workstation_uuid' => strtolower((string) Str::uuid()),
            'script_id' => null,
            'script_source' => ScriptExecutionSource::MANAGED_SCRIPT->value,
            'action' => $this->faker->randomElement(ScriptExecutionAction::values()),
            'os' => $this->faker->randomElement([
                ScriptExecutionOs::WINDOWS->value,
                ScriptExecutionOs::LINUX->value,
            ]),
            'status' => ScriptExecutionStatus::SUCCESS->value,
            'exit_code' => 0,
            'stdout_excerpt' => $this->faker->paragraph(3),
            'stderr_excerpt' => null,
            'started_at' => $startedAt,
            'duration_ms' => $this->faker->numberBetween(50, 30000),
            'reported_at' => Carbon::now(),
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    /**
     * Échec — exit_code random 1-127.
     */
    public function failed(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => ScriptExecutionStatus::FAILURE->value,
            'exit_code' => $this->faker->numberBetween(1, 127),
            'stderr_excerpt' => 'Error: ' . $this->faker->sentence(),
        ]);
    }

    /**
     * Timeout — exit_code 124 (convention `timeout` GNU).
     */
    public function timeout(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => ScriptExecutionStatus::TIMEOUT->value,
            'exit_code' => 124,
            'stderr_excerpt' => 'Killed: timeout exceeded',
        ]);
    }

    /**
     * Skipped — duration_ms 0 mais pas null.
     */
    public function skipped(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => ScriptExecutionStatus::SKIPPED->value,
            'exit_code' => null,
            'duration_ms' => 0,
            'stdout_excerpt' => null,
        ]);
    }

    /**
     * Sans correlation_id (cas legacy postes sans wrapper).
     */
    public function withoutCorrelation(): self
    {
        return $this->state(fn (array $attrs): array => [
            'correlation_id' => null,
        ]);
    }

    /**
     * Force le workstation_uuid (testing dedupe).
     */
    public function forWorkstation(string $uuid): self
    {
        return $this->state(fn (array $attrs): array => [
            'workstation_uuid' => strtolower($uuid),
        ]);
    }

    /**
     * Started_at < $hours.
     */
    public function recent(int $hours = 24): self
    {
        return $this->state(fn (array $attrs): array => [
            'started_at' => Carbon::now()->subMinutes($this->faker->numberBetween(1, $hours * 60 - 1)),
        ]);
    }

    /**
     * Started_at > $days jours (testing du job d'archivage).
     */
    public function archived(int $days = 95): self
    {
        return $this->state(fn (array $attrs): array => [
            'started_at' => Carbon::now()->subDays($days),
            'reported_at' => Carbon::now()->subDays($days),
        ]);
    }

    /**
     * Force le script_id (testing top failing scripts).
     */
    public function forScript(int $scriptId): self
    {
        return $this->state(fn (array $attrs): array => [
            'script_id' => $scriptId,
        ]);
    }

    /**
     * Force l'OS Windows.
     */
    public function windows(): self
    {
        return $this->state(fn (array $attrs): array => [
            'os' => ScriptExecutionOs::WINDOWS->value,
        ]);
    }

    /**
     * Force l'OS Linux.
     */
    public function linux(): self
    {
        return $this->state(fn (array $attrs): array => [
            'os' => ScriptExecutionOs::LINUX->value,
        ]);
    }
}
