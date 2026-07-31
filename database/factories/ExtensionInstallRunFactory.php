<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Extension;
use App\Models\ExtensionInstallRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 56.3 — Fabrique de runs d'opération d'extension.
 *
 * @extends Factory<ExtensionInstallRun>
 */
class ExtensionInstallRunFactory extends Factory
{
    protected $model = ExtensionInstallRun::class;

    public function definition(): array
    {
        return [
            'extension_id' => Extension::factory(),
            'operation' => ExtensionInstallRun::OPERATION_INSTALL,
            'status' => ExtensionInstallRun::STATUS_PENDING,
            'current_step' => '',
            'steps' => [],
            'error' => '',
            'requested_by_user_id' => null,
            'requested_by_login' => 'qa-admin',
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => ExtensionInstallRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function success(): static
    {
        return $this->state(fn (): array => [
            'status' => ExtensionInstallRun::STATUS_SUCCESS,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function failed(string $error = 'échec à l\'étape apt_install'): static
    {
        return $this->state(fn (): array => [
            'status' => ExtensionInstallRun::STATUS_FAILED,
            'error' => $error,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function operation(string $operation): static
    {
        return $this->state(fn (): array => ['operation' => $operation]);
    }

    /**
     * Run ACTIF mais figé depuis longtemps : le worker a été tué. La fabrique
     * force `updated_at` APRÈS création (les timestamps sont écrasés à
     * l'insertion).
     */
    public function stale(int $secondsAgo = 100_000): static
    {
        return $this->state(fn (): array => [
            'status' => ExtensionInstallRun::STATUS_RUNNING,
            'started_at' => now()->subSeconds($secondsAgo),
        ])->afterCreating(function (ExtensionInstallRun $run) use ($secondsAgo): void {
            ExtensionInstallRun::query()
                ->where('id', $run->id)
                ->update([
                    'created_at' => now()->subSeconds($secondsAgo),
                    'updated_at' => now()->subSeconds($secondsAgo),
                ]);
            $run->refresh();
        });
    }
}
