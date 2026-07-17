<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workstation;
use App\Models\WorkstationReinstallRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Story 3.11 — Factory pour les tests du service / boot / tick.
 *
 * @extends Factory<WorkstationReinstallRequest>
 */
class WorkstationReinstallRequestFactory extends Factory
{
    protected $model = WorkstationReinstallRequest::class;

    public function definition(): array
    {
        $ttlHours = (int) config('ipxe.reinstall.ttl_hours', 6);

        return [
            'workstation_id' => Workstation::factory(),
            'target_action' => 'install_win11',
            'status' => WorkstationReinstallRequest::STATUS_ARMED,
            'boot_served_count' => 0,
            'initiated_by' => 'user:1',
            'created_by_user_id' => null,
            'scheduled_at' => Carbon::now(),
            'triggered_at' => null,
            'boot_served_at' => null,
            'expires_at' => Carbon::now()->addHours($ttlHours),
        ];
    }

    public function armed(): self
    {
        return $this->state(['status' => WorkstationReinstallRequest::STATUS_ARMED]);
    }

    public function serving(): self
    {
        return $this->state([
            'status' => WorkstationReinstallRequest::STATUS_SERVING,
            'triggered_at' => Carbon::now(),
        ]);
    }

    public function scheduledAt(Carbon $when): self
    {
        return $this->state(['scheduled_at' => $when]);
    }

    public function expired(): self
    {
        return $this->state(['expires_at' => Carbon::now()->subHour()]);
    }
}
