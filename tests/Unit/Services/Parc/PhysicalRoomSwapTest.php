<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Parc;

use App\Jobs\AdSync\WorkstationMembershipAdSyncJob;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Story 4.11 — Swap transactionnel de salle physique sur le pivot global.
 *
 * Couvre AC3 (swap transactionnel, 1-salle-max), AC7 (dispatch
 * `WorkstationMembershipAdSyncJob::move`) et AC2 (lecture `physicalRoom` pivot).
 */
class PhysicalRoomSwapTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;
    private WorkstationGroupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTablesIfNeeded();

        Queue::fake();
        WorkstationGroupObserver::disableSync();

        $this->service = app(WorkstationGroupService::class);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();

        if ($this->createdTables) {
            Schema::dropIfExists('workstation_application_status');
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
                $table->string('status')->default('active');
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
                $table->string('description')->nullable();
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->string('app_profile_name')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('workstation_group_workstation')) {
            Schema::create('workstation_group_workstation', function (Blueprint $table) {
                $table->foreignId('workstation_id')->constrained('workstations')->cascadeOnDelete();
                $table->foreignId('workstation_group_id')->constrained('workstation_groups')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['workstation_group_id', 'workstation_id'], 'wg_ws_unique');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('workstation_application_status')) {
            Schema::create('workstation_application_status', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workstation_id');
                $table->string('status', 32)->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }
    }

    private function makeMachine(string $name = 'pc-1'): Workstation
    {
        return Workstation::create(['name' => $name, 'status' => 'active']);
    }

    private function makeRoom(string $name): WorkstationGroup
    {
        return WorkstationGroup::create(['name' => $name, 'is_physical' => true, 'is_active' => true]);
    }

    private function makeParc(string $name): WorkstationGroup
    {
        return WorkstationGroup::create(['name' => $name, 'is_physical' => false, 'is_active' => true]);
    }

    public function test_assign_attaches_room_via_pivot_and_dispatches_move(): void
    {
        Bus::fake([WorkstationMembershipAdSyncJob::class]);

        $machine = $this->makeMachine();
        $room = $this->makeRoom('Salle A');

        $this->service->assignMachineToPhysicalRoom($machine->id, $room->id);

        $this->assertSame($room->id, $machine->fresh()->physicalRoom?->id);
        Bus::assertDispatched(WorkstationMembershipAdSyncJob::class, function ($job) use ($machine, $room) {
            return $job->workstationId === $machine->id
                && $job->targetSalleId === $room->id
                && $job->action === WorkstationMembershipAdSyncJob::ACTION_MOVE;
        });
    }

    public function test_swap_replaces_old_room_keeping_one_max(): void
    {
        $machine = $this->makeMachine();
        $roomA = $this->makeRoom('Salle A');
        $roomB = $this->makeRoom('Salle B');

        $this->service->assignMachineToPhysicalRoom($machine->id, $roomA->id);
        $this->service->assignMachineToPhysicalRoom($machine->id, $roomB->id);

        $physical = $machine->fresh()->physicalRooms()->get();
        $this->assertCount(1, $physical, 'Un poste ne doit avoir au plus qu\'une salle physique');
        $this->assertSame($roomB->id, $physical->first()->id);
    }

    public function test_swap_preserves_logical_parcs(): void
    {
        $machine = $this->makeMachine();
        $parc = $this->makeParc('Parc Windows');
        $room = $this->makeRoom('Salle A');

        $machine->groups()->attach($parc->id);
        $this->service->assignMachineToPhysicalRoom($machine->id, $room->id);

        $groupIds = $machine->fresh()->groups()->pluck('workstation_groups.id')->all();
        $this->assertContains($parc->id, $groupIds, 'Le parc logique ne doit pas être détaché par le swap salle');
        $this->assertContains($room->id, $groupIds);
    }

    public function test_null_room_detaches_without_dispatch(): void
    {
        Bus::fake([WorkstationMembershipAdSyncJob::class]);

        $machine = $this->makeMachine();
        $room = $this->makeRoom('Salle A');
        $machine->groups()->attach($room->id);

        $this->service->assignMachineToPhysicalRoom($machine->id, null);

        $this->assertNull($machine->fresh()->physicalRoom);
        Bus::assertNotDispatched(WorkstationMembershipAdSyncJob::class);
    }

    public function test_reassign_same_room_does_not_dispatch(): void
    {
        $machine = $this->makeMachine();
        $room = $this->makeRoom('Salle A');
        $this->service->assignMachineToPhysicalRoom($machine->id, $room->id);

        Bus::fake([WorkstationMembershipAdSyncJob::class]);
        $this->service->assignMachineToPhysicalRoom($machine->id, $room->id);

        Bus::assertNotDispatched(WorkstationMembershipAdSyncJob::class);
    }

    public function test_non_physical_target_throws(): void
    {
        $machine = $this->makeMachine();
        $parc = $this->makeParc('Parc');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->assignMachineToPhysicalRoom($machine->id, $parc->id);
    }

    public function test_check_conflict_reads_pivot_room(): void
    {
        $machine = $this->makeMachine();
        $roomA = $this->makeRoom('Salle A');
        $roomB = $this->makeRoom('Salle B');
        $this->service->assignMachineToPhysicalRoom($machine->id, $roomA->id);

        $conflict = $this->service->checkPhysicalRoomConflict($machine->id, $roomB->id);

        $this->assertIsArray($conflict);
        $this->assertSame($roomA->id, $conflict['current_room_id']);
        $this->assertSame($roomB->id, $conflict['target_room_id']);
    }
}
