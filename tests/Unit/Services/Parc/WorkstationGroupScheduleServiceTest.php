<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Parc;

use App\Jobs\DispatchMachinePowerActionJob;
use App\Models\MachinePowerActionTask;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Models\WorkstationGroupSchedule;
use App\Models\WorkstationGroupScheduleRun;
use App\Services\Parc\WorkstationGroupScheduleService;
use App\Services\Parc\WorkstationGroupService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests unitaires du service WorkstationGroupScheduleService (story 4-4).
 *
 * 23 tests : 13 AC13 + 10 AC25 (mode one-shot D7).
 *
 * On utilise DatabaseTransactions + createTablesIfNeeded() (pattern 4.2/4.3)
 * pour cohabiter SQLite (tests) et Postgres (prod). Les CHECK contraintes pgsql
 * ne sont pas testées ici — elles sont défenses en profondeur testées en E2E VM.
 */
class WorkstationGroupScheduleServiceTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;
    private WorkstationGroupScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createTablesIfNeeded();
        Queue::fake();

        $this->service = app(WorkstationGroupScheduleService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->createdTables) {
            Schema::dropIfExists('workstation_group_schedule_runs');
            Schema::dropIfExists('workstation_group_schedules');
            Schema::dropIfExists('machine_power_action_tasks');
            Schema::dropIfExists('workstation_group_workstation');
            Schema::dropIfExists('workstation_groups');
            Schema::dropIfExists('workstations');
        }
        parent::tearDown();
    }

    private function createTablesIfNeeded(): void
    {
        if (!Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('os')->nullable();
                $table->string('ip')->nullable();
                $table->string('mac')->nullable();
                $table->integer('status')->default(0);
                $table->timestamp('last_report_at')->nullable();
                $table->timestamp('date_rapport_poste')->nullable();
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('is_physical')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('locked')->nullable();
                $table->text('description')->nullable();
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->string('app_profile_name')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('workstation_group_workstation')) {
            Schema::create('workstation_group_workstation', function (Blueprint $table) {
                $table->foreignId('workstation_id')->constrained('workstations')->cascadeOnDelete();
                $table->foreignId('workstation_group_id')->constrained('workstation_groups')->cascadeOnDelete();
                $table->boolean('physical')->default(false);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('machine_power_action_tasks')) {
            Schema::create('machine_power_action_tasks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workstation_id')->nullable();
                $table->string('action', 32);
                $table->string('status', 16)->default('queued');
                $table->string('initiated_by', 100)->nullable();
                $table->timestamp('initiated_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('result')->nullable();
                $table->text('error_message')->nullable();
                $table->string('restart_phase', 16)->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('workstation_group_schedules')) {
            Schema::create('workstation_group_schedules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workstation_group_id');
                $table->string('action', 16);
                $table->string('mode', 16)->default('recurring');
                $table->json('days_of_week')->nullable();
                $table->time('time_of_day')->nullable();
                $table->string('timezone', 64)->nullable();
                $table->timestamp('run_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->boolean('enabled')->default(true);
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('workstation_group_schedule_runs')) {
            Schema::create('workstation_group_schedule_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('schedule_id')->nullable();
                $table->timestamp('ran_at');
                $table->time('ran_for_time');
                $table->date('ran_for_date');
                $table->json('summary');
                $table->timestamps();
                $table->unique(['schedule_id', 'ran_for_date', 'ran_for_time'], 'wgsr_schedule_date_time_unique');
            });
            $this->createdTables = true;
        }
    }

    private function makeGroupWithMachines(int $count): array
    {
        $group = WorkstationGroup::create([
            'name' => 'lab-sched-' . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);

        $machines = [];
        for ($i = 1; $i <= $count; $i++) {
            $ws = Workstation::create([
                'name' => "pc-sched-{$i}-" . uniqid(),
                'os' => 'Windows 10',
                'ip' => "192.168.200.{$i}",
                'mac' => sprintf('aa:bb:cc:dd:ef:%02x', $i),
                'status' => 1,
            ]);
            $ws->groups()->attach($group->id, ['physical' => true]);
            $machines[] = $ws;
        }

        return [$group, $machines];
    }

    // ========================================
    // AC13 — CRUD + executeDue récurrent
    // ========================================

    public function test_create_schedule_persists_all_fields_with_defaults(): void
    {
        [$group] = $this->makeGroupWithMachines(1);

        $schedule = $this->service->createRecurring(
            $group->id,
            'wake',
            [1, 2, 3, 4, 5],
            '08:30:00'
        );

        $this->assertDatabaseHas('workstation_group_schedules', [
            'id' => $schedule->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'enabled' => true,
        ]);
        $this->assertEquals('Europe/Paris', $schedule->timezone);
        $this->assertEquals([1, 2, 3, 4, 5], $schedule->days_of_week);
        $this->assertNull($schedule->run_at);
        $this->assertNull($schedule->completed_at);
    }

    public function test_update_schedule_mutates_existing_row(): void
    {
        [$group] = $this->makeGroupWithMachines(1);
        $schedule = $this->service->createRecurring($group->id, 'wake', [1, 2, 3], '08:00');

        $updated = $this->service->update($schedule->id, [
            'time_of_day' => '09:15',
            'days_of_week' => [1, 2, 3, 4, 5],
        ]);

        $this->assertEquals('09:15:00', $updated->time_of_day->format('H:i:s'));
        $this->assertEquals([1, 2, 3, 4, 5], $updated->days_of_week);
        $this->assertEquals($schedule->id, $updated->id);
    }

    public function test_toggle_schedule_flips_enabled_flag(): void
    {
        [$group] = $this->makeGroupWithMachines(1);
        $schedule = $this->service->createRecurring($group->id, 'wake', [1], '08:00');

        $this->assertTrue($schedule->enabled);
        $toggled = $this->service->toggle($schedule->id);
        $this->assertFalse($toggled->enabled);
        $toggled2 = $this->service->toggle($schedule->id);
        $this->assertTrue($toggled2->enabled);
    }

    public function test_delete_schedule_removes_row_but_preserves_runs(): void
    {
        [$group] = $this->makeGroupWithMachines(1);
        $schedule = $this->service->createRecurring($group->id, 'wake', [1], '08:00');

        $run = WorkstationGroupScheduleRun::create([
            'schedule_id' => $schedule->id,
            'ran_at' => now(),
            'ran_for_time' => '08:00:00',
            'ran_for_date' => now()->toDateString(),
            'summary' => ['success_count' => 1, 'failed_count' => 0, 'skipped_count' => 0, 'task_ids' => [], 'errors' => []],
        ]);

        $this->service->delete($schedule->id);

        $this->assertDatabaseMissing('workstation_group_schedules', ['id' => $schedule->id]);
        // En prod (pgsql), FK nullOnDelete met schedule_id=null. En test SQLite
        // sans FK stricte, le run conserve son schedule_id — le point fonctionnel
        // crucial (le run survit au delete du schedule) est respecté dans les 2 cas.
        $this->assertDatabaseHas('workstation_group_schedule_runs', ['id' => $run->id]);
    }

    public function test_execute_due_triggers_matching_schedules_and_creates_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris')); // lundi

        [$group, $machines] = $this->makeGroupWithMachines(3);
        $schedule = $this->service->createRecurring(
            $group->id,
            'wake',
            [1, 2, 3, 4, 5],
            '08:30',
            'Europe/Paris'
        );

        $result = $this->service->executeDue(Carbon::now());

        $this->assertEquals(1, $result['executed_count']);
        $this->assertEquals(3, $result['total_tasks_dispatched']);
        $this->assertEquals(1, $result['recurring_count']);

        Queue::assertPushed(DispatchMachinePowerActionJob::class, 3);
        $this->assertDatabaseCount('workstation_group_schedule_runs', 1);

        $run = WorkstationGroupScheduleRun::first();
        $this->assertEquals(3, $run->summary['success_count']);
        $this->assertEquals(0, $run->summary['failed_count']);
        $this->assertEquals(0, $run->summary['skipped_count']);
        $this->assertCount(3, $run->summary['task_ids']);
    }

    public function test_execute_due_skips_disabled_schedules(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris'));

        [$group] = $this->makeGroupWithMachines(1);
        $schedule = $this->service->createRecurring($group->id, 'wake', [1], '08:30', 'Europe/Paris');
        $schedule->update(['enabled' => false]);

        $result = $this->service->executeDue();

        $this->assertEquals(0, $result['executed_count']);
        Queue::assertNotPushed(DispatchMachinePowerActionJob::class);
    }

    public function test_execute_due_skips_wrong_day_of_week(): void
    {
        // 2026-04-26 = dimanche (ISO 7)
        Carbon::setTestNow(Carbon::parse('2026-04-26 08:30:00', 'Europe/Paris'));

        [$group] = $this->makeGroupWithMachines(1);
        $this->service->createRecurring($group->id, 'wake', [1, 2, 3, 4, 5], '08:30', 'Europe/Paris');

        $result = $this->service->executeDue();
        $this->assertEquals(0, $result['executed_count']);
    }

    public function test_execute_due_skips_wrong_time_of_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 09:00:00', 'Europe/Paris'));

        [$group] = $this->makeGroupWithMachines(1);
        $this->service->createRecurring($group->id, 'wake', [1], '08:30', 'Europe/Paris');

        $result = $this->service->executeDue();
        $this->assertEquals(0, $result['executed_count']);
    }

    public function test_execute_due_is_idempotent_within_same_minute(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:30:15', 'Europe/Paris'));

        [$group] = $this->makeGroupWithMachines(2);
        $this->service->createRecurring($group->id, 'wake', [1], '08:30', 'Europe/Paris');

        $r1 = $this->service->executeDue();
        $r2 = $this->service->executeDue();

        $this->assertEquals(1, $r1['executed_count']);
        $this->assertEquals(0, $r2['executed_count'], 'Second tick dans la même minute ne doit rien refire');
        $this->assertDatabaseCount('workstation_group_schedule_runs', 1);
    }

    public function test_execute_due_respects_timezone_of_schedule(): void
    {
        // Tick en heure UTC équivalente à 08:30 Paris en heure d'été (UTC+2)
        Carbon::setTestNow(Carbon::parse('2026-04-27 06:30:00', 'UTC'));

        [$group] = $this->makeGroupWithMachines(1);
        $this->service->createRecurring($group->id, 'wake', [1], '08:30', 'Europe/Paris');

        $result = $this->service->executeDue(Carbon::now());
        $this->assertEquals(1, $result['executed_count'], 'Le match doit être tz-aware : 06:30 UTC = 08:30 Paris été');
    }

    public function test_execute_due_respects_timezone_in_winter(): void
    {
        // 2026-01-12 = lundi, UTC+1 (heure d'hiver) → 08:30 Paris = 07:30 UTC.
        // Complément au test été pour couvrir les deux bascules DST (AC11).
        Carbon::setTestNow(Carbon::parse('2026-01-12 07:30:00', 'UTC'));

        [$group] = $this->makeGroupWithMachines(1);
        $this->service->createRecurring($group->id, 'wake', [1], '08:30', 'Europe/Paris');

        $result = $this->service->executeDue(Carbon::now());
        $this->assertEquals(1, $result['executed_count'], 'Le match doit être tz-aware : 07:30 UTC = 08:30 Paris hiver');

        Queue::assertPushed(DispatchMachinePowerActionJob::class, 1);
    }

    public function test_execute_due_counts_skipped_machines_already_running(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris'));

        [$group, $machines] = $this->makeGroupWithMachines(3);

        // Simulate une task déjà active sur M2 (AC8)
        MachinePowerActionTask::create([
            'workstation_id' => $machines[1]->id,
            'action' => 'wake',
            'status' => MachinePowerActionTask::STATUS_RUNNING,
            'initiated_by' => 'user:42',
            'initiated_at' => now(),
        ]);

        $this->service->createRecurring($group->id, 'wake', [1], '08:30', 'Europe/Paris');

        $result = $this->service->executeDue();

        $this->assertEquals(1, $result['executed_count']);
        $run = WorkstationGroupScheduleRun::first();
        $this->assertEquals(2, $run->summary['success_count']);
        $this->assertEquals(1, $run->summary['skipped_count']);
        $this->assertEquals(0, $run->summary['failed_count']);
    }

    public function test_execute_due_handles_empty_group_gracefully(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris'));

        $group = WorkstationGroup::create([
            'name' => 'empty-group-' . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->service->createRecurring($group->id, 'wake', [1], '08:30', 'Europe/Paris');

        $result = $this->service->executeDue();

        $this->assertEquals(1, $result['executed_count']);
        $this->assertEquals(0, $result['total_tasks_dispatched']);

        $run = WorkstationGroupScheduleRun::first();
        $this->assertEquals(0, $run->summary['success_count']);
        $this->assertNotEmpty($run->summary['errors']);
    }

    public function test_execute_due_resolves_machines_at_tick_time_not_creation_time(): void
    {
        // Liveness D2 : on crée un schedule, puis on ajoute une machine au
        // groupe, puis on tick → la nouvelle machine DOIT être traitée.
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris'));

        [$group, $machines] = $this->makeGroupWithMachines(1);
        $schedule = $this->service->createRecurring($group->id, 'wake', [1], '08:30', 'Europe/Paris');

        // Ajout d'une 2e machine APRÈS la création du schedule
        $newMachine = Workstation::create([
            'name' => 'pc-sched-late-' . uniqid(),
            'os' => 'Windows 10',
            'ip' => '192.168.200.99',
            'mac' => 'aa:bb:cc:dd:ef:99',
            'status' => 1,
        ]);
        $newMachine->groups()->attach($group->id, ['physical' => true]);

        $result = $this->service->executeDue();

        $this->assertEquals(2, $result['total_tasks_dispatched'], 'Le scheduler doit relire le groupe au tick');
        Queue::assertPushed(DispatchMachinePowerActionJob::class, 2);
    }

    // ========================================
    // AC25 — One-shot (D7)
    // ========================================

    public function test_create_one_shot_persists_run_at_and_nullifies_recurring_fields(): void
    {
        [$group] = $this->makeGroupWithMachines(1);

        $runAt = Carbon::now()->addDay()->setTime(7, 45);
        $schedule = $this->service->createOneShot($group->id, 'wake', $runAt);

        $this->assertEquals('one_shot', $schedule->mode);
        $this->assertNotNull($schedule->run_at);
        $this->assertEquals(
            $runAt->toDateTimeString(),
            $schedule->run_at->toDateTimeString()
        );
        $this->assertNull($schedule->days_of_week);
        $this->assertNull($schedule->time_of_day);
        $this->assertNull($schedule->timezone);
        $this->assertNull($schedule->completed_at);
        $this->assertTrue($schedule->enabled);
    }

    public function test_create_rejects_one_shot_with_run_at_in_past(): void
    {
        [$group] = $this->makeGroupWithMachines(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->createOneShot($group->id, 'wake', Carbon::now()->subHour());
    }

    public function test_rejects_unsupported_action_on_create_recurring(): void
    {
        [$group] = $this->makeGroupWithMachines(1);

        // Action non supportée (D5 — whitelist wake/shutdown)
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createRecurring($group->id, 'shutdown-force', [1], '08:00');
    }

    public function test_create_validates_mode_exclusivity_at_service_level(): void
    {
        // AC20 — défense en profondeur côté service (la CHECK constraint pgsql
        // protège en prod ; SQLite ne la supporte pas, on valide via le service).
        [$group] = $this->makeGroupWithMachines(1);

        // Sous-cas 1 : createRecurring avec days_of_week vide → exception
        try {
            $this->service->createRecurring($group->id, 'wake', [], '08:00');
            $this->fail('createRecurring avec days_of_week vide aurait dû lever une exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('jour', strtolower($e->getMessage()));
        }

        // Sous-cas 2 : createOneShot avec run_at dans le passé → exception
        try {
            $this->service->createOneShot($group->id, 'wake', Carbon::now()->subHour());
            $this->fail('createOneShot avec run_at passé aurait dû lever une exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('futur', strtolower($e->getMessage()));
        }

        // Sous-cas 3 : createRecurring avec time_of_day invalide → exception
        try {
            $this->service->createRecurring($group->id, 'wake', [1], 'not-a-time');
            $this->fail('createRecurring avec time_of_day invalide aurait dû lever une exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('format', strtolower($e->getMessage()));
        }
    }

    public function test_execute_due_triggers_one_shot_when_run_at_reached(): void
    {
        $runAt = Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris');
        Carbon::setTestNow($runAt);

        [$group] = $this->makeGroupWithMachines(3);

        // Créer le one-shot manuellement car createOneShot exige run_at > now()
        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'one_shot',
            'days_of_week' => null,
            'time_of_day' => null,
            'timezone' => null,
            'run_at' => $runAt,
            'completed_at' => null,
            'enabled' => true,
        ]);

        $result = $this->service->executeDue($runAt);

        $this->assertEquals(1, $result['executed_count']);
        $this->assertEquals(1, $result['one_shot_count']);
        Queue::assertPushed(DispatchMachinePowerActionJob::class, 3);
    }

    public function test_execute_due_marks_one_shot_as_completed_and_disables_it(): void
    {
        $runAt = Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris');
        Carbon::setTestNow($runAt);

        [$group] = $this->makeGroupWithMachines(2);
        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'one_shot',
            'run_at' => $runAt,
            'enabled' => true,
        ]);

        $this->service->executeDue($runAt);

        $refreshed = $schedule->fresh();
        $this->assertFalse($refreshed->enabled);
        $this->assertNotNull($refreshed->completed_at);
    }

    public function test_execute_due_does_not_refire_completed_one_shot(): void
    {
        $runAt = Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris');
        Carbon::setTestNow($runAt);

        [$group] = $this->makeGroupWithMachines(1);
        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'one_shot',
            'run_at' => $runAt,
            'enabled' => true,
        ]);

        $this->service->executeDue($runAt);

        // Tick suivant — ne DOIT rien refire
        Carbon::setTestNow($runAt->copy()->addMinute());
        $r2 = $this->service->executeDue();

        $this->assertEquals(0, $r2['executed_count']);
        $this->assertDatabaseCount('workstation_group_schedule_runs', 1);
    }

    public function test_execute_due_catches_up_one_shot_after_downtime(): void
    {
        // Simule un one-shot prévu à 08:30, mais le tick arrive à 10:00 (downtime 1h30)
        $runAt = Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris');
        $lateTick = Carbon::parse('2026-04-27 10:00:00', 'Europe/Paris');
        Carbon::setTestNow($lateTick);

        [$group] = $this->makeGroupWithMachines(1);
        WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'one_shot',
            'run_at' => $runAt,
            'enabled' => true,
        ]);

        $result = $this->service->executeDue($lateTick);

        $this->assertEquals(1, $result['executed_count'], 'Le one-shot doit être rattrapé après downtime');
        $run = WorkstationGroupScheduleRun::first();
        $this->assertArrayHasKey('drift_seconds', $run->summary);
        $this->assertGreaterThan(60, $run->summary['drift_seconds']);
    }

    public function test_update_one_shot_future_is_allowed(): void
    {
        [$group] = $this->makeGroupWithMachines(1);
        $schedule = $this->service->createOneShot($group->id, 'wake', Carbon::now()->addDays(2));

        $updated = $this->service->update($schedule->id, [
            'run_at' => Carbon::now()->addDays(3),
        ]);

        $this->assertEquals('one_shot', $updated->mode);
        $this->assertNotNull($updated->run_at);
    }

    public function test_update_one_shot_completed_throws_domain_exception(): void
    {
        [$group] = $this->makeGroupWithMachines(1);

        $schedule = WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'one_shot',
            'run_at' => Carbon::now()->subHour(),
            'completed_at' => Carbon::now()->subMinute(),
            'enabled' => false,
        ]);

        $this->expectException(\DomainException::class);
        $this->service->update($schedule->id, ['action' => 'shutdown']);
    }

    public function test_scopes_recurring_and_one_shot_and_completed_filter_correctly(): void
    {
        [$group] = $this->makeGroupWithMachines(1);

        $this->service->createRecurring($group->id, 'wake', [1], '08:00');
        $this->service->createOneShot($group->id, 'wake', Carbon::now()->addDay());
        WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'one_shot',
            'run_at' => Carbon::now()->subDay(),
            'completed_at' => Carbon::now()->subHour(),
            'enabled' => false,
        ]);

        $this->assertEquals(1, WorkstationGroupSchedule::recurring()->count());
        $this->assertEquals(2, WorkstationGroupSchedule::oneShot()->count());
        $this->assertEquals(1, WorkstationGroupSchedule::completed()->count());
        $this->assertEquals(2, WorkstationGroupSchedule::pending()->count());
    }
}
