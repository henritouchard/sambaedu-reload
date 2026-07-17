<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Models\WorkstationReinstallRequest;
use App\Services\Parc\WorkstationReinstallService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Jobs\DispatchMachinePowerActionJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Story 3.11 — Réinstallation salle/groupe (fan-out) — AC7/8/11/12.
 *
 * NOTE ENVIRONNEMENT : la page groupe (`pages::parc.groups.[id].index`) ne peut
 * pas compléter un rendu HTML complet ni un second roundtrip Livewire sur
 * l'hôte de test (fragilité pré-existante partagée par `GroupShowPageTest`,
 * rouge sur cet hôte — composants enfants SFC #[Lazy]). On exerce donc le
 * fan-out EXACTEMENT comme le fait la méthode `armReinstall` du composant :
 * `armForMachines($group->members, ...)` — mêmes entrées, mêmes services, même
 * table. La liaison UI (bouton « Réinstaller la salle », modale, toasts) est
 * couverte identiquement par {@see MachineReinstallTest} (même partial + même
 * pattern de méthode).
 */
class GroupReinstallTest extends TestCase
{
    use RefreshDatabase;

    private WorkstationReinstallService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->actingAs(User::factory()->create());
        $this->service = app(WorkstationReinstallService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fan_out_over_group_members_skips_protected_and_duplicates(): void
    {
        $group = WorkstationGroup::factory()->create();
        $normal = Workstation::factory()->count(2)->create();
        $protected = Workstation::factory()->protected()->create();
        $withActive = Workstation::factory()->create();
        WorkstationReinstallRequest::factory()->armed()->create(['workstation_id' => $withActive->id]);

        $group->workstations()->attach(
            $normal->pluck('id')->push($protected->id)->push($withActive->id)->all()
        );

        // Reproduit `armReinstall()` de la page groupe.
        $result = $this->service->armForMachines(
            $group->members,
            'install_win11',
            null,
            'group:' . $group->id,
            auth()->id(),
        );

        $this->assertSame(2, $result['armed_count']);
        $this->assertSame(1, $result['skipped_protected']);
        $this->assertSame(1, $result['skipped_duplicate']);
        $this->assertSame(0, WorkstationReinstallRequest::where('workstation_id', $protected->id)->count());
    }

    public function test_group_fan_out_uses_group_initiated_by(): void
    {
        $group = WorkstationGroup::factory()->create();
        $machines = Workstation::factory()->count(3)->create();
        $group->workstations()->attach($machines->pluck('id')->all());

        $this->service->armForMachines($group->members, 'install_deb_gnome', null, 'group:' . $group->id, auth()->id());

        $this->assertSame(3, WorkstationReinstallRequest::where('initiated_by', 'group:' . $group->id)->count());
    }

    public function test_group_fan_out_scheduled(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        $group = WorkstationGroup::factory()->create();
        $machines = Workstation::factory()->count(3)->create();
        $group->workstations()->attach($machines->pluck('id')->all());
        $when = Carbon::parse('2026-07-18 22:00:00');

        $this->service->armForMachines($group->members, 'install_deb_gnome', $when, 'group:' . $group->id);

        $this->assertSame(3, WorkstationReinstallRequest::count());
        $this->assertEquals($when, WorkstationReinstallRequest::first()->scheduled_at);
        Queue::assertNotPushed(DispatchMachinePowerActionJob::class);
    }

    public function test_added_member_after_arming_is_not_reinstalled(): void
    {
        // D3 — liste figée à l'armement.
        $group = WorkstationGroup::factory()->create();
        $initial = Workstation::factory()->count(2)->create();
        $group->workstations()->attach($initial->pluck('id')->all());

        $this->service->armForMachines($group->fresh()->members, 'install_win11', null, 'group:' . $group->id);
        $this->assertSame(2, WorkstationReinstallRequest::count());

        $late = Workstation::factory()->create();
        $group->workstations()->attach($late->id);

        $this->assertSame(0, WorkstationReinstallRequest::where('workstation_id', $late->id)->count());
    }
}
