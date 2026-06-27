<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Agent;

use App\Models\AgentRelease;
use App\Models\AgentReleaseRing;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
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

    private const COMPONENT = 'pages::admin.settings.agent._partials.deployment-progress';

    protected function setUp(): void
    {
        parent::setUp();

        // Désactive la sync AD des observers : créer un WorkstationGroup via
        // factory dispatcherait WorkstationGroupAdSyncJob inline (queue sync)
        // → LDAP injoignable sur l'hôte de test.
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        Permission::firstOrCreate(['name' => 'server.admin', 'guard_name' => 'web']);
        $admin = User::query()->create(['login' => 'prog-admin', 'role' => 'prof', 'is_active' => true]);
        $admin->givePermissionTo('server.admin');
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
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
    public function a_multi_ring_workstation_is_counted_once_in_its_most_recently_targeted_ring(): void
    {
        // Cas réel : un poste ∈ groupe physique (ring canari récent) ET groupe
        // logique (ring parc plus ancien). Le manifest ne lui sert qu'UNE
        // version = celle du ring le plus récemment ciblé (récence FR4). Il doit
        // être compté UNE seule fois, dans le ring canari — et SURTOUT pas
        // « en retard » dans le ring parc qui ne le gouverne pas.
        $relCanari = $this->release('2.3.0');
        $relParc = $this->release('2.2.0');

        $groupPhys = WorkstationGroup::factory()->create(['name' => 'salle-B12']);
        $groupLogic = WorkstationGroup::factory()->create(['name' => 'labo-SVT']);

        $ringParc = AgentReleaseRing::query()->create([
            'workstation_group_id' => $groupLogic->id,
            'agent_release_id' => $relParc->id,
        ]);
        $ringCanari = AgentReleaseRing::query()->create([
            'workstation_group_id' => $groupPhys->id,
            'agent_release_id' => $relCanari->id,
        ]);
        // Récence explicite (update direct = bypass timestamps) : canari > parc.
        AgentReleaseRing::query()->whereKey($ringParc->id)->update(['updated_at' => now()->subDay()]);
        AgentReleaseRing::query()->whereKey($ringCanari->id)->update(['updated_at' => now()]);

        $pc42 = $this->ws('2.3.0'); // a convergé vers la canari
        $pc42->groups()->attach([$groupPhys->id, $groupLogic->id]);

        $rings = Livewire::test(self::COMPONENT)->instance()->rings;
        $canari = $rings->firstWhere('id', $ringCanari->id);
        $parc = $rings->firstWhere('id', $ringParc->id);

        // Compté une seule fois, dans le ring effectif (canari), et à jour.
        self::assertSame(1, $canari['total']);
        self::assertSame(1, $canari['up_to_date']);
        self::assertSame(0, $canari['behind']);

        // Ring parc : le poste n'y est PAS recompté ni faussement « en retard ».
        self::assertSame(0, $parc['total']);
        self::assertSame(0, $parc['behind']);
    }

    #[Test]
    public function it_renders_empty_state_without_rings(): void
    {
        Livewire::test(self::COMPONENT)
            ->assertOk()
            ->assertSee('Aucun ring ciblé');
    }
}
