<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Parc;

use App\Models\MachinePowerActionTask;
use App\Models\Workstation;
use App\Models\WorkstationReinstallRequest;
use App\Services\Parc\WorkstationReinstallService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Story 3.11 — Tests unit du service de réinstallation (AC2/3/7/8/11/12).
 */
class WorkstationReinstallServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkstationReinstallService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->service = app(WorkstationReinstallService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_arm_for_machine_immediate_creates_armed_request(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        $ws = Workstation::factory()->create();
        $user = \App\Models\User::factory()->create();

        $req = $this->service->armForMachine($ws, 'install_win11', null, 'user:' . $user->id, $user->id);

        $this->assertSame('install_win11', $req->target_action);
        $this->assertSame(WorkstationReinstallRequest::STATUS_ARMED, $req->status);
        $this->assertNull($req->triggered_at);
        $this->assertSame($user->id, $req->created_by_user_id);
        $this->assertEquals(Carbon::now(), $req->scheduled_at);
        // TTL 6h par défaut.
        $this->assertEquals(Carbon::now()->addHours(6), $req->expires_at);
    }

    public function test_arm_for_machine_scheduled_keeps_future_datetime(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        $ws = Workstation::factory()->create();
        $when = Carbon::parse('2026-07-18 22:00:00');

        $req = $this->service->armForMachine($ws, 'install_deb_gnome', $when, 'user:1');

        $this->assertEquals($when, $req->scheduled_at);
        $this->assertNull($req->triggered_at);
    }

    /**
     * Fix review #4 — l'échéance TTL est ancrée sur `max(now, scheduled_at)` :
     * une planification à J+1 ne doit pas expirer avant son heure.
     */
    public function test_scheduled_arm_anchors_expiry_on_scheduled_at(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        config(['ipxe.reinstall.ttl_hours' => 6]);
        $ws = Workstation::factory()->create();
        $when = Carbon::parse('2026-07-18 10:00:00'); // J+1

        $req = $this->service->armForMachine($ws, 'install_win11', $when, 'user:1');

        // expires_at ancré sur scheduled_at (futur), pas sur now.
        $this->assertTrue($req->expires_at->greaterThan($when));
        $this->assertEquals($when->copy()->addHours(6), $req->expires_at);
    }

    /**
     * Fix review #4 — même ancrage pour le fan-out `armForMachines`.
     */
    public function test_scheduled_fan_out_anchors_expiry_on_scheduled_at(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        config(['ipxe.reinstall.ttl_hours' => 6]);
        $machines = Workstation::factory()->count(2)->create();
        $when = Carbon::parse('2026-07-18 10:00:00');

        $this->service->armForMachines($machines, 'install_win11', $when, 'group:1');

        $req = WorkstationReinstallRequest::query()->firstOrFail();
        $this->assertEquals($when->copy()->addHours(6), $req->expires_at);
    }

    public function test_arm_for_machine_does_not_dispatch_reboot(): void
    {
        $ws = Workstation::factory()->create();

        $this->service->armForMachine($ws, 'install_win11', null, 'user:1');

        Queue::assertNothingPushed();
        $this->assertSame(0, MachinePowerActionTask::count());
    }

    public function test_arm_for_machine_rejects_protected(): void
    {
        $ws = Workstation::factory()->protected()->create();

        $this->expectException(\DomainException::class);
        $this->service->armForMachine($ws, 'install_win11', null, 'user:1');
    }

    public function test_arm_for_machine_rejects_duplicate_active(): void
    {
        $ws = Workstation::factory()->create();
        $this->service->armForMachine($ws, 'install_win11', null, 'user:1');

        $this->expectException(\DomainException::class);
        $this->service->armForMachine($ws, 'install_win10', null, 'user:1');
    }

    public function test_arm_for_machine_rejects_non_install_action(): void
    {
        $ws = Workstation::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->armForMachine($ws, 'factory_reset', null, 'user:1');
    }

    public function test_arm_for_machines_fan_out_bulk_insert(): void
    {
        $machines = Workstation::factory()->count(4)->create();

        $result = $this->service->armForMachines($machines, 'install_win11', null, 'group:3');

        $this->assertSame(4, $result['armed_count']);
        $this->assertSame(4, WorkstationReinstallRequest::count());
        $this->assertSame(0, $result['skipped_protected']);
        $this->assertSame(0, $result['skipped_duplicate']);
    }

    public function test_arm_for_machines_skips_protected_and_duplicates(): void
    {
        $normal = Workstation::factory()->count(2)->create();
        $protected = Workstation::factory()->protected()->create();
        $withActive = Workstation::factory()->create();
        $this->service->armForMachine($withActive, 'install_win10', null, 'user:1');

        $all = $normal->push($protected)->push($withActive);
        $result = $this->service->armForMachines($all, 'install_win11', null, 'group:1');

        $this->assertSame(2, $result['armed_count']);
        $this->assertSame(1, $result['skipped_protected']);
        $this->assertSame(1, $result['skipped_duplicate']);
    }

    public function test_cancel_moves_active_to_canceled(): void
    {
        $ws = Workstation::factory()->create();
        $req = $this->service->armForMachine($ws, 'install_win11', null, 'user:1');

        $this->service->cancel($req);

        $this->assertSame(WorkstationReinstallRequest::STATUS_CANCELED, $req->fresh()->status);
    }

    public function test_cancel_is_noop_on_terminal(): void
    {
        $ws = Workstation::factory()->create();
        $req = WorkstationReinstallRequest::factory()->create([
            'workstation_id' => $ws->id,
            'status' => WorkstationReinstallRequest::STATUS_DONE,
        ]);

        $this->service->cancel($req);

        $this->assertSame(WorkstationReinstallRequest::STATUS_DONE, $req->fresh()->status);
    }

    public function test_active_request_for_returns_only_active(): void
    {
        $ws = Workstation::factory()->create();
        WorkstationReinstallRequest::factory()->create([
            'workstation_id' => $ws->id,
            'status' => WorkstationReinstallRequest::STATUS_DONE,
        ]);

        $this->assertNull($this->service->activeRequestFor($ws));

        $active = WorkstationReinstallRequest::factory()->create([
            'workstation_id' => $ws->id,
            'status' => WorkstationReinstallRequest::STATUS_SERVING,
        ]);

        $this->assertSame($active->id, $this->service->activeRequestFor($ws)?->id);
    }

    public function test_mark_served_increments_and_promotes(): void
    {
        $ws = Workstation::factory()->create();
        $req = $this->service->armForMachine($ws, 'install_win11', null, 'user:1');

        $this->service->markServed($req);

        $fresh = $req->fresh();
        $this->assertSame(1, $fresh->boot_served_count);
        $this->assertSame(WorkstationReinstallRequest::STATUS_SERVING, $fresh->status);
        $this->assertNotNull($fresh->boot_served_at);
    }

    public function test_mark_done_for_workstation(): void
    {
        $ws = Workstation::factory()->create();
        $this->service->armForMachine($ws, 'install_win11', null, 'user:1');

        $this->service->markDoneForWorkstation($ws);

        $this->assertNull($this->service->activeRequestFor($ws));
    }

    public function test_mark_installing_for_workstation(): void
    {
        $ws = Workstation::factory()->create();
        $req = $this->service->armForMachine($ws, 'install_win11', null, 'user:1');

        $this->service->markInstallingForWorkstation($ws);

        // `installing` reste ACTIF : pas de réarmement en double possible, et le
        // sweep TTL peut encore rattraper une installation qui ne rapporte
        // jamais son OOBE.
        $this->assertSame(WorkstationReinstallRequest::STATUS_INSTALLING, $req->fresh()->status);
        $this->assertNotNull($this->service->activeRequestFor($ws));
    }

    public function test_mark_installing_for_workstation_is_noop_without_request(): void
    {
        $ws = Workstation::factory()->create();

        $this->service->markInstallingForWorkstation($ws);

        $this->assertNull($this->service->activeRequestFor($ws));
    }

    public function test_serve_cap_guard(): void
    {
        config(['ipxe.reinstall.max_boot_serves' => 3]);
        $ws = Workstation::factory()->create();
        $req = WorkstationReinstallRequest::factory()->serving()->create([
            'workstation_id' => $ws->id,
            'boot_served_count' => 3,
        ]);

        $this->assertTrue($req->hasExceededServeCap());
    }

    public function test_install_only_excludes_maintenance(): void
    {
        $actions = $this->service->installOnlyActions();

        $this->assertContains('install_win11', $actions);
        $this->assertContains('install_deb_gnome', $actions);
        $this->assertNotContains('factory_reset', $actions);
        $this->assertNotContains('clonezilla_restore_sda2_sda1', $actions);
        $this->assertNotContains('rescuecd', $actions);
        $this->assertNotContains('winpe', $actions);
    }

    public function test_label_for_from_menu_items(): void
    {
        $this->assertSame('Debian + GNOME (defaut)', $this->service->labelFor('install_deb_gnome'));
    }
}
