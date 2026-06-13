<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Agent;

use App\Models\AgentRelease;
use App\Models\AgentReleaseRing;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 25.5 — surface progression du déploiement (AC1, AC4).
 *
 * Agrégation LECTURE SEULE `rings × workstation_group_workstation ×
 * workstations` : par ring, version ciblée vs `agent_reported_version` des
 * postes (à jour / en retard / jamais vu). Zéro écriture.
 */
class DeploymentProgressSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc-settings.agent._partials.deployment-progress';

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'computer.install', 'guard_name' => 'web']);
        $admin = User::query()->create(['login' => 'prog-admin', 'role' => 'prof', 'is_active' => true]);
        $admin->givePermissionTo('computer.install');
        $this->actingAs($admin);
    }

    private function release(string $version): AgentRelease
    {
        return AgentRelease::query()->create([
            'version' => $version,
            'hash' => str_repeat('a', 64),
            'filename' => "sambaedu-agent-{$version}.exe",
            'is_stable' => false,
        ]);
    }

    private function ws(?string $reported): Workstation
    {
        $ws = Workstation::factory()->create();
        if ($reported !== null) {
            $ws->forceFill([
                'agent_reported_version' => $reported,
                'agent_reported_version_at' => now(),
            ])->save();
        }

        return $ws;
    }

    #[Test]
    public function it_aggregates_workstations_per_ring_by_reported_version(): void
    {
        $rel = $this->release('2.2.0');
        $group = WorkstationGroup::factory()->create(['name' => 'salle-A']);
        AgentReleaseRing::query()->create([
            'workstation_group_id' => $group->id,
            'agent_release_id' => $rel->id,
        ]);

        $upToDate = $this->ws('2.2.0');      // à jour
        $behind = $this->ws('2.1.0');        // en retard
        $neverSeen = $this->ws(null);        // jamais vu
        $group->workstations()->attach([$upToDate->id, $behind->id, $neverSeen->id]);

        $component = Livewire::test(self::COMPONENT);
        $rings = $component->instance()->rings;
        $row = $rings->firstWhere('id', AgentReleaseRing::query()->sole()->id);

        self::assertSame('2.2.0', $row['target_version']);
        self::assertSame(3, $row['total']);
        self::assertSame(1, $row['up_to_date']);
        self::assertSame(1, $row['behind']);
        self::assertSame(1, $row['never_seen']);
    }

    #[Test]
    public function it_renders_empty_state_without_rings(): void
    {
        Livewire::test(self::COMPONENT)
            ->assertOk()
            ->assertSee('Aucun ring ciblé');
    }
}
