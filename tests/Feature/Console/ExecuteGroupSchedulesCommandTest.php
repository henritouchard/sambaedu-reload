<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\DispatchMachinePowerActionJob;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Models\WorkstationGroupSchedule;
use App\Models\WorkstationGroupScheduleRun;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests feature de la commande artisan `parc:execute-group-schedules` (story 4-4).
 *
 * 7 tests : 5 AC14 + 2 AC14 one-shot.
 */
class ExecuteGroupSchedulesCommandTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->createTablesIfNeeded();
        Queue::fake();
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
                $table->unsignedBigInteger('physical_room_id')->nullable();
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
            'name' => 'lab-cmd-' . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);

        $machines = [];
        for ($i = 1; $i <= $count; $i++) {
            $ws = Workstation::create([
                'name' => "pc-cmd-{$i}-" . uniqid(),
                'os' => 'Windows 10',
                'ip' => "192.168.210.{$i}",
                'mac' => sprintf('aa:bb:dd:ee:ff:%02x', $i),
                'status' => 1,
            ]);
            $ws->groups()->attach($group->id, ['physical' => true]);
            $machines[] = $ws;
        }

        return [$group, $machines];
    }

    public function test_command_dispatches_due_schedules_with_faked_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris'));

        [$group] = $this->makeGroupWithMachines(3);
        WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:30:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        $exitCode = Artisan::call('parc:execute-group-schedules');

        $this->assertEquals(0, $exitCode);
        Queue::assertPushed(DispatchMachinePowerActionJob::class, 3);
    }

    public function test_command_is_idempotent_on_double_tick_within_same_minute(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris'));

        [$group] = $this->makeGroupWithMachines(2);
        WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:30:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        Artisan::call('parc:execute-group-schedules');
        Artisan::call('parc:execute-group-schedules');

        $this->assertEquals(1, WorkstationGroupScheduleRun::count());
    }

    public function test_command_produces_schedule_run_row_with_summary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris'));

        [$group] = $this->makeGroupWithMachines(2);
        WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:30:00',
            'timezone' => 'Europe/Paris',
            'enabled' => true,
        ]);

        Artisan::call('parc:execute-group-schedules');

        $run = WorkstationGroupScheduleRun::first();
        $this->assertNotNull($run);
        $this->assertArrayHasKey('success_count', $run->summary);
        $this->assertArrayHasKey('task_ids', $run->summary);
        $this->assertEquals(2, $run->summary['success_count']);
    }

    public function test_command_handles_no_due_schedules_silently(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris'));

        $exitCode = Artisan::call('parc:execute-group-schedules');

        $this->assertEquals(0, $exitCode);
        Queue::assertNotPushed(DispatchMachinePowerActionJob::class);
        $this->assertEquals(0, WorkstationGroupScheduleRun::count());
    }

    public function test_command_respects_enabled_flag(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris'));

        [$group] = $this->makeGroupWithMachines(1);
        WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'recurring',
            'days_of_week' => [1],
            'time_of_day' => '08:30:00',
            'timezone' => 'Europe/Paris',
            'enabled' => false,
        ]);

        Artisan::call('parc:execute-group-schedules');

        Queue::assertNotPushed(DispatchMachinePowerActionJob::class);
        $this->assertEquals(0, WorkstationGroupScheduleRun::count());
    }

    public function test_command_dispatches_due_one_shot_and_marks_completed(): void
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

        Artisan::call('parc:execute-group-schedules');

        Queue::assertPushed(DispatchMachinePowerActionJob::class, 2);
        $refreshed = $schedule->fresh();
        $this->assertFalse($refreshed->enabled);
        $this->assertNotNull($refreshed->completed_at);
    }

    public function test_command_does_not_refire_completed_one_shot_on_next_tick(): void
    {
        $runAt = Carbon::parse('2026-04-27 08:30:00', 'Europe/Paris');
        Carbon::setTestNow($runAt);

        [$group] = $this->makeGroupWithMachines(1);
        WorkstationGroupSchedule::create([
            'workstation_group_id' => $group->id,
            'action' => 'wake',
            'mode' => 'one_shot',
            'run_at' => $runAt,
            'enabled' => true,
        ]);

        Artisan::call('parc:execute-group-schedules');
        // Tick suivant
        Carbon::setTestNow($runAt->copy()->addMinute());
        Queue::fake(); // Reset des assertions
        Artisan::call('parc:execute-group-schedules');

        Queue::assertNotPushed(DispatchMachinePowerActionJob::class);
        $this->assertEquals(1, WorkstationGroupScheduleRun::count());
    }
}
