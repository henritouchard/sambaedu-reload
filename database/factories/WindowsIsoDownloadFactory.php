<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus;
use App\Models\User;
use App\Models\WindowsIsoDownload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 3.6 — D9 / AC1.2 — Factory pour {@see \App\Models\WindowsIsoDownload}.
 *
 * Convention iso projet :
 *  - Par défaut, une row `pending` rattachée à un nouvel admin via
 *    `User::factory()`.
 *  - States dédiés `downloading()`, `extracting()`, `success()`, `failed()`,
 *    `cancelled()` pour les tests Feature/Unit.
 *
 * @extends Factory<WindowsIsoDownload>
 */
class WindowsIsoDownloadFactory extends Factory
{
    protected $model = WindowsIsoDownload::class;

    public function definition(): array
    {
        $version = $this->faker->randomElement(['Win10', 'Win11']);
        $isoName = $version . '_' . $this->faker->randomElement(['22H2', '23H2', '24H2']) . '.iso';

        return [
            'version'              => $version,
            'iso_name'             => $isoName,
            'source_url'           => 'https://software-static.download.prss.microsoft.com/dbazure/' . $isoName,
            'status'               => WindowsIsoDownloadStatus::Pending,
            'started_at'           => null,
            'completed_at'         => null,
            'exit_code'            => null,
            'error'                => null,
            'initiated_by_user_id' => User::factory(),
            'host_ip'              => $this->faker->ipv4(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => WindowsIsoDownloadStatus::Pending]);
    }

    public function downloading(): static
    {
        return $this->state(fn () => [
            'status'     => WindowsIsoDownloadStatus::Downloading,
            'started_at' => now(),
        ]);
    }

    public function extracting(): static
    {
        return $this->state(fn () => [
            'status'     => WindowsIsoDownloadStatus::Extracting,
            'started_at' => now()->subMinutes(15),
        ]);
    }

    public function success(): static
    {
        return $this->state(fn () => [
            'status'       => WindowsIsoDownloadStatus::Success,
            'started_at'   => now()->subMinutes(30),
            'completed_at' => now(),
            'exit_code'    => 0,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status'       => WindowsIsoDownloadStatus::Failed,
            'started_at'   => now()->subMinutes(5),
            'completed_at' => now(),
            'exit_code'    => 6,
            'error'        => '[curl-failed] Could not resolve host',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status'       => WindowsIsoDownloadStatus::Cancelled,
            'started_at'   => now()->subMinutes(2),
            'completed_at' => now(),
        ]);
    }

    public function forVersion(string $version): static
    {
        return $this->state(fn () => [
            'version'  => $version,
            'iso_name' => $version . '_24H2.iso',
        ]);
    }
}
