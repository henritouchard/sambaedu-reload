<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Jobs\DispatchMachinePowerActionJob;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationReinstallRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Story 3.11 — Réinstallation multi-sélection depuis l'inventaire (AC7/9/11/12).
 */
class InventoryReinstallTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.index';

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        Queue::fake();
        Livewire::withoutLazyLoading();
        $this->actingAs(User::factory()->create());
    }

    private function grantInstall(bool $allow = true): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'computer.install' ? $allow : null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ids($collection): array
    {
        return $collection->pluck('id')->map(fn ($i) => (string) $i)->all();
    }

    public function test_bulk_arm_immediate_no_reboot(): void
    {
        $this->grantInstall();
        $machines = Workstation::factory()->count(3)->create();

        Livewire::test(self::COMPONENT)
            ->set('selectedMachines', $this->ids($machines))
            ->call('openReinstallModal')
            ->assertSet('reinstallModalOpen', true)
            ->set('reinstallTarget', 'install_win11')
            ->call('armReinstall')
            ->assertSet('reinstallModalOpen', false)
            ->assertSet('selectedMachines', []);

        $this->assertSame(3, WorkstationReinstallRequest::where('target_action', 'install_win11')->count());
        Queue::assertNotPushed(DispatchMachinePowerActionJob::class);
    }

    public function test_bulk_arm_skips_protected_and_duplicates(): void
    {
        $this->grantInstall();
        $normal = Workstation::factory()->count(2)->create();
        $protected = Workstation::factory()->protected()->create();
        $withActive = Workstation::factory()->create();
        WorkstationReinstallRequest::factory()->armed()->create(['workstation_id' => $withActive->id, 'target_action' => 'install_win10']);

        $all = $normal->push($protected)->push($withActive);

        Livewire::test(self::COMPONENT)
            ->set('selectedMachines', $this->ids($all))
            ->set('reinstallTarget', 'install_win11')
            ->call('armReinstall');

        // 2 armés seulement (protégé + doublon ignorés).
        $this->assertSame(2, WorkstationReinstallRequest::where('target_action', 'install_win11')->count());
        $this->assertSame(0, WorkstationReinstallRequest::where('workstation_id', $protected->id)->count());
    }

    public function test_permission_denied_blocks_bulk(): void
    {
        $this->grantInstall(false);
        $machines = Workstation::factory()->count(2)->create();

        Livewire::test(self::COMPONENT)
            ->set('selectedMachines', $this->ids($machines))
            ->set('reinstallTarget', 'install_win11')
            ->call('armReinstall');

        $this->assertSame(0, WorkstationReinstallRequest::count());
    }

    public function test_large_selection_bulk_inserts(): void
    {
        // AC12 — grande sélection, insert bulk, pas de reboot en masse.
        $this->grantInstall();
        $machines = Workstation::factory()->count(120)->create();

        Livewire::test(self::COMPONENT)
            ->set('selectedMachines', $this->ids($machines))
            ->set('reinstallTarget', 'install_win11')
            ->call('armReinstall');

        $this->assertSame(120, WorkstationReinstallRequest::count());
        Queue::assertNotPushed(DispatchMachinePowerActionJob::class);
    }
}
