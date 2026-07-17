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
 * Story 3.11 — Tests Livewire fiche machine (AC2/3/8/9/11).
 */
class MachineReinstallTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.machines.[id].index';

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        Queue::fake();
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function grantInstall(bool $allow = true): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'computer.install' ? $allow : null);
    }

    public function test_arm_immediate_creates_request_and_no_reboot(): void
    {
        $this->grantInstall();
        $ws = Workstation::factory()->create();

        Livewire::test(self::COMPONENT, ['id' => $ws->id])
            ->call('openReinstallModal')
            ->assertSet('reinstallModalOpen', true)
            ->set('reinstallTarget', 'install_win11')
            ->set('reinstallWhen', 'now')
            ->call('armReinstall')
            ->assertSet('reinstallModalOpen', false);

        $req = WorkstationReinstallRequest::where('workstation_id', $ws->id)->first();
        $this->assertNotNull($req);
        $this->assertSame('install_win11', $req->target_action);
        $this->assertSame(WorkstationReinstallRequest::STATUS_ARMED, $req->status);
        Queue::assertNotPushed(DispatchMachinePowerActionJob::class);
    }

    public function test_arm_scheduled_future(): void
    {
        $this->grantInstall();
        Carbon::setTestNow('2026-07-17 10:00:00');
        $ws = Workstation::factory()->create();

        Livewire::test(self::COMPONENT, ['id' => $ws->id])
            ->call('openReinstallModal')
            ->set('reinstallTarget', 'install_deb_gnome')
            ->set('reinstallWhen', 'schedule')
            ->set('reinstallScheduledAt', '2026-07-18T22:00')
            ->call('armReinstall')
            ->assertSet('reinstallModalOpen', false);

        $req = WorkstationReinstallRequest::where('workstation_id', $ws->id)->first();
        $this->assertNotNull($req);
        $this->assertSame('2026-07-18 22:00:00', $req->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_arm_scheduled_past_rejected(): void
    {
        $this->grantInstall();
        Carbon::setTestNow('2026-07-17 10:00:00');
        $ws = Workstation::factory()->create();

        Livewire::test(self::COMPONENT, ['id' => $ws->id])
            ->call('openReinstallModal')
            ->set('reinstallWhen', 'schedule')
            ->set('reinstallScheduledAt', '2026-07-16T09:00')
            ->call('armReinstall');

        $this->assertSame(0, WorkstationReinstallRequest::where('workstation_id', $ws->id)->count());
    }

    public function test_cancel_reinstall(): void
    {
        $this->grantInstall();
        $ws = Workstation::factory()->create();
        WorkstationReinstallRequest::factory()->armed()->create(['workstation_id' => $ws->id]);

        Livewire::test(self::COMPONENT, ['id' => $ws->id])
            ->call('cancelReinstall');

        $this->assertSame(
            WorkstationReinstallRequest::STATUS_CANCELED,
            WorkstationReinstallRequest::where('workstation_id', $ws->id)->first()->status,
        );
    }

    public function test_protected_machine_blocks_open(): void
    {
        $this->grantInstall();
        $ws = Workstation::factory()->protected()->create();

        Livewire::test(self::COMPONENT, ['id' => $ws->id])
            ->call('openReinstallModal')
            ->assertSet('reinstallModalOpen', false);

        $this->assertSame(0, WorkstationReinstallRequest::where('workstation_id', $ws->id)->count());
    }

    public function test_permission_denied_blocks_arm(): void
    {
        $this->grantInstall(false);
        $ws = Workstation::factory()->create();

        Livewire::test(self::COMPONENT, ['id' => $ws->id])
            ->set('reinstallTarget', 'install_win11')
            ->call('armReinstall');

        $this->assertSame(0, WorkstationReinstallRequest::where('workstation_id', $ws->id)->count());
    }
}
