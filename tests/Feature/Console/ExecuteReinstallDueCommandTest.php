<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\DispatchMachinePowerActionJob;
use App\Models\MachinePowerActionTask;
use App\Models\Workstation;
use App\Models\WorkstationReinstallRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Story 3.11 — Tests feature du tick `parc:reinstall-due` (AC5/12).
 */
class ExecuteReinstallDueCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function armedRequest(?Carbon $scheduledAt = null): WorkstationReinstallRequest
    {
        $ws = Workstation::factory()->create();

        return WorkstationReinstallRequest::factory()->armed()->create([
            'workstation_id' => $ws->id,
            'triggered_at' => null,
            'scheduled_at' => $scheduledAt ?? Carbon::now(),
        ]);
    }

    public function test_due_request_is_triggered(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        $req = $this->armedRequest();

        Artisan::call('parc:reinstall-due');

        $fresh = $req->fresh();
        $this->assertNotNull($fresh->triggered_at);
        Queue::assertPushed(DispatchMachinePowerActionJob::class, 1);
        $task = MachinePowerActionTask::first();
        $this->assertSame('restart', $task->action);
        $this->assertSame('reinstall:' . $req->id, $task->initiated_by);
    }

    public function test_not_yet_due_is_ignored_then_triggered_when_due(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        $req = $this->armedRequest(Carbon::parse('2026-07-17 11:00:00'));

        Artisan::call('parc:reinstall-due');
        $this->assertNull($req->fresh()->triggered_at);

        Carbon::setTestNow('2026-07-17 11:00:05');
        Artisan::call('parc:reinstall-due');
        $this->assertNotNull($req->fresh()->triggered_at);
    }

    public function test_idempotent_not_retriggered(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        $req = $this->armedRequest();

        Artisan::call('parc:reinstall-due');
        Artisan::call('parc:reinstall-due');

        // Un seul job malgré deux ticks (idempotence via triggered_at).
        Queue::assertPushed(DispatchMachinePowerActionJob::class, 1);
    }

    public function test_concurrency_cap_limits_waves(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        config(['ipxe.reinstall.max_concurrent' => 3]);

        $requests = collect(range(1, 5))->map(fn () => $this->armedRequest());

        // Tick 1 : seulement 3 déclenchés (plafond), 2 restent armed non triggered.
        Artisan::call('parc:reinstall-due');
        Queue::assertPushed(DispatchMachinePowerActionJob::class, 3);

        $triggered = WorkstationReinstallRequest::whereNotNull('triggered_at')->count();
        $this->assertSame(3, $triggered);

        // Tick 2 : slots pleins (3 in-flight), rien de plus.
        Artisan::call('parc:reinstall-due');
        Queue::assertPushed(DispatchMachinePowerActionJob::class, 3);

        // Deux des in-flight passent `done` → 2 slots se libèrent.
        WorkstationReinstallRequest::whereNotNull('triggered_at')
            ->limit(2)
            ->get()
            ->each(fn ($r) => $r->update(['status' => WorkstationReinstallRequest::STATUS_DONE]));

        // Tick 3 : la vague suivante reprend (2 restants déclenchés).
        Artisan::call('parc:reinstall-due');
        Queue::assertPushed(DispatchMachinePowerActionJob::class, 5);

        $this->assertSame(0, WorkstationReinstallRequest::where('status', WorkstationReinstallRequest::STATUS_ARMED)
            ->whereNull('triggered_at')->count());
    }

    /**
     * Fix review #3 — sweep temporel : une machine réellement morte (requête en
     * vol, jamais bootée) voit son slot libéré par le TEMPS, pas par un boot. Le
     * tick lui-même passe la requête expirée `failed` (sans update manuel du
     * statut) ET promeut la vague suivante.
     */
    public function test_expired_request_frees_slot(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        config(['ipxe.reinstall.max_concurrent' => 1]);

        // Requête en vol (triggered) mais TTL dépassé — poste jamais revenu.
        $ws = Workstation::factory()->create();
        $stuck = WorkstationReinstallRequest::factory()->serving()->expired()->create([
            'workstation_id' => $ws->id,
        ]);

        // Vague suivante en attente (slot bloqué tant que $stuck compte in-flight).
        $req = $this->armedRequest();

        Artisan::call('parc:reinstall-due');

        // Le sweep du tick a failé la requête morte…
        $this->assertSame(
            WorkstationReinstallRequest::STATUS_FAILED,
            $stuck->fresh()->status,
            'Le tick lui-même doit passer failed la requête active expirée (sweep #3).',
        );
        // …libérant le slot pour la vague suivante.
        $this->assertNotNull($req->fresh()->triggered_at);
    }
}
