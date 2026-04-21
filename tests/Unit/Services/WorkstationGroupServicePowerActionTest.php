<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Jobs\DispatchMachinePowerActionJob;
use App\Models\MachinePowerActionTask;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Repositories\WorkstationGroupRepository;
use App\Services\Parc\MachinePowerService;
use App\Services\Parc\RemoteAccessService;
use App\Services\Parc\WorkstationGroupService;
use App\Services\WorkstationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Tests unitaires du pipeline async de `WorkstationGroupService` (story 4-3).
 *
 * Couvre AC8 : dispatch 1 job par machine, contrat typé préservé, idempotence,
 * normalisation des IDs, flux synchrone `remote` conservé.
 *
 * Le service étant couplé à l'ORM Eloquent (création `MachinePowerActionTask`),
 * on utilise `DatabaseTransactions` + `createTablesIfNeeded()` pour garder les
 * assertions DB déterministes sans alourdir la CI avec `RefreshDatabase`.
 */
class WorkstationGroupServicePowerActionTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;
    private WorkstationGroupService $service;
    private WorkstationGroupRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createTablesIfNeeded();

        // Repository mocké pour contrôler finement les collections de machines
        // retournées par `findGroupMachinesByIds` sans aller toucher à la base.
        $this->repository = Mockery::mock(WorkstationGroupRepository::class);

        $workstationService = Mockery::mock(WorkstationService::class);
        $remoteAccessService = Mockery::mock(RemoteAccessService::class);

        $this->service = new WorkstationGroupService(
            $this->repository,
            $workstationService,
            $remoteAccessService,
        );
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
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
    }

    /**
     * Crée N workstations + un groupe qui les contient. Retourne [$group, $machines].
     *
     * @return array{0: WorkstationGroup, 1: \Illuminate\Support\Collection<int, Workstation>}
     */
    private function makeGroupWithMachines(int $count = 3): array
    {
        $group = WorkstationGroup::create([
            'name' => 'lab-batch-' . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);

        $machines = collect();
        for ($i = 1; $i <= $count; $i++) {
            $ws = Workstation::create([
                'name' => "pc-batch-{$i}-" . uniqid(),
                'os' => 'Windows 10',
                'ip' => "192.168.100.{$i}",
                'mac' => sprintf('aa:bb:cc:dd:ee:%02x', $i),
                'status' => 1,
            ]);
            $ws->groups()->attach($group->id, ['physical' => true]);
            $machines->push($ws);
        }

        return [$group, $machines];
    }

    // ─── Tests ──────────────────────────────────────────────────────────────

    public function test_execute_group_machines_action_dispatches_one_job_per_machine(): void
    {
        Queue::fake();

        [$group, $machines] = $this->makeGroupWithMachines(3);
        $ids = $machines->pluck('id')->all();

        $this->repository->shouldReceive('findGroupMachinesByIds')
            ->with($group->id, $ids)
            ->andReturn($machines);

        $result = $this->service->executeGroupMachinesAction($group->id, $ids, 'wake');

        $this->assertEquals(3, $result['requested_count']);
        $this->assertEquals(3, $result['success_count']);
        $this->assertEquals(0, $result['failed_count']);

        // 3 tasks créées
        $this->assertEquals(3, MachinePowerActionTask::count());
        foreach (MachinePowerActionTask::all() as $task) {
            $this->assertEquals('wake', $task->action);
            $this->assertEquals(MachinePowerActionTask::STATUS_QUEUED, $task->status);
        }

        Queue::assertPushed(DispatchMachinePowerActionJob::class, 3);
    }

    public function test_execute_group_machines_action_returns_typed_structure_with_correct_counts(): void
    {
        // Contrat public : {action, requested_count, success_count, failed_count, results[]}
        // doit rester strictement préservé même après le refactor async.
        Queue::fake();

        [$group, $machines] = $this->makeGroupWithMachines(2);
        $ids = $machines->pluck('id')->all();

        $this->repository->shouldReceive('findGroupMachinesByIds')
            ->with($group->id, $ids)
            ->andReturn($machines);

        $result = $this->service->executeGroupMachinesAction($group->id, $ids, 'shutdown');

        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('requested_count', $result);
        $this->assertArrayHasKey('success_count', $result);
        $this->assertArrayHasKey('failed_count', $result);
        $this->assertArrayHasKey('results', $result);

        $this->assertEquals('shutdown', $result['action']);
        $this->assertEquals(2, $result['requested_count']);
        $this->assertEquals(2, $result['success_count']);
        $this->assertEquals(0, $result['failed_count']);
        $this->assertCount(2, $result['results']);

        foreach ($result['results'] as $row) {
            $this->assertArrayHasKey('machine', $row);
            $this->assertArrayHasKey('success', $row);
            $this->assertArrayHasKey('code', $row);
            $this->assertTrue($row['success']);
            $this->assertEquals(202, $row['code']);
            // Enrichissement rétrocompat : les dispatchés ont un task_id.
            $this->assertArrayHasKey('task_id', $row);
        }
    }

    public function test_execute_group_machines_action_throws_on_unsupported_action(): void
    {
        Queue::fake();

        [$group, $machines] = $this->makeGroupWithMachines(1);
        $ids = $machines->pluck('id')->all();

        $this->repository->shouldReceive('findGroupMachinesByIds')
            ->andReturn($machines);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Action machine non supportée');

        $this->service->executeGroupMachinesAction($group->id, $ids, 'teleport');
    }

    public function test_execute_group_machines_action_filters_machines_with_active_tasks(): void
    {
        // AC7 — idempotence : une machine qui a déjà une task en ACTIVE_STATUSES
        // est filtrée du dispatch et apparaît en failed_count avec code=409.
        Queue::fake();

        [$group, $machines] = $this->makeGroupWithMachines(3);
        $ids = $machines->pluck('id')->all();

        // Crée une task active sur la première machine (simule un dispatch en vol).
        MachinePowerActionTask::create([
            'workstation_id' => $machines[0]->id,
            'action' => 'wake',
            'status' => MachinePowerActionTask::STATUS_RUNNING,
            'initiated_at' => now(),
        ]);

        $this->repository->shouldReceive('findGroupMachinesByIds')
            ->with($group->id, $ids)
            ->andReturn($machines);

        $result = $this->service->executeGroupMachinesAction($group->id, $ids, 'wake');

        $this->assertEquals(3, $result['requested_count']);
        $this->assertEquals(2, $result['success_count']);
        $this->assertEquals(1, $result['failed_count']);

        // 2 nouvelles tasks + 1 task pré-existante.
        $this->assertEquals(3, MachinePowerActionTask::count());

        // 2 jobs dispatchés seulement.
        Queue::assertPushed(DispatchMachinePowerActionJob::class, 2);

        // Le résultat de la machine skippée a code=409 + reason='already-running'.
        $skipped = collect($result['results'])->firstWhere('code', 409);
        $this->assertNotNull($skipped);
        $this->assertFalse($skipped['success']);
        $this->assertEquals('already-running', $skipped['reason']);
    }

    public function test_execute_group_machines_action_returns_empty_results_when_group_is_empty(): void
    {
        Queue::fake();

        $group = WorkstationGroup::create([
            'name' => 'empty-lab-' . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->repository->shouldReceive('findGroupMachinesByIds')
            ->with($group->id, [])
            ->andReturn(collect());

        $result = $this->service->executeGroupMachinesAction($group->id, [], 'wake');

        $this->assertEquals(0, $result['requested_count']);
        $this->assertEquals(0, $result['success_count']);
        $this->assertEquals(0, $result['failed_count']);
        $this->assertSame([], $result['results']);
        $this->assertEquals(0, MachinePowerActionTask::count());

        Queue::assertNotPushed(DispatchMachinePowerActionJob::class);
    }

    public function test_execute_group_machines_action_creates_task_with_restart_phase_waiting_down_for_restart(): void
    {
        // Une task créée pour 'restart' doit avoir restart_phase='waiting-down'
        // (parité avec la vue machine 4-2).
        Queue::fake();

        [$group, $machines] = $this->makeGroupWithMachines(1);
        $ids = $machines->pluck('id')->all();

        $this->repository->shouldReceive('findGroupMachinesByIds')
            ->andReturn($machines);

        $this->service->executeGroupMachinesAction($group->id, $ids, 'restart');

        $task = MachinePowerActionTask::first();
        $this->assertNotNull($task);
        $this->assertEquals('restart', $task->action);
        $this->assertEquals(MachinePowerActionTask::RESTART_PHASE_WAITING_DOWN, $task->restart_phase);

        Queue::assertPushed(DispatchMachinePowerActionJob::class, 1);
    }

    public function test_execute_group_machines_action_preserves_remote_sync_flow(): void
    {
        // action=remote doit rester synchrone (token Guacamole via
        // executeRemoteAccessAction), pas de MachinePowerActionTask créée,
        // pas de job dispatché.
        Queue::fake();

        [$group, $machines] = $this->makeGroupWithMachines(1);
        $ids = $machines->pluck('id')->all();

        $this->repository->shouldReceive('findGroupMachinesByIds')
            ->andReturn($machines);

        // Remocker le service avec un RemoteAccessService personnalisé pour
        // ce test — il doit répondre "hasRemoteAccessRights=true" et fournir
        // un token.
        $remoteAccess = Mockery::mock(RemoteAccessService::class);
        $remoteAccess->shouldReceive('hasRemoteAccessRights')->andReturn(true);
        $remoteAccess->shouldReceive('generateRemoteToken')
            ->andReturn('https://guacamole.local/#/token/abc123');

        $workstationService = Mockery::mock(WorkstationService::class);

        $service = new WorkstationGroupService(
            $this->repository,
            $workstationService,
            $remoteAccess,
        );

        $result = $service->executeGroupMachinesAction($group->id, $ids, 'remote');

        $this->assertEquals('remote', $result['action']);
        $this->assertEquals(1, $result['success_count']);
        $this->assertEquals(0, $result['failed_count']);
        $this->assertArrayHasKey('url', $result['results'][0]);
        $this->assertEquals('https://guacamole.local/#/token/abc123', $result['results'][0]['url']);

        // Aucune task async créée pour `remote`.
        $this->assertEquals(0, MachinePowerActionTask::count());
        Queue::assertNotPushed(DispatchMachinePowerActionJob::class);
    }

    public function test_execute_group_machines_action_normalizes_and_deduplicates_ids(): void
    {
        // IDs '1', 1, 1, '0' → normalisation : 1 (les zéros sont filtrés).
        Queue::fake();

        [$group, $machines] = $this->makeGroupWithMachines(1);
        $machine = $machines->first();

        // Le repository sera appelé avec la liste normalisée [machine->id].
        $this->repository->shouldReceive('findGroupMachinesByIds')
            ->with($group->id, [$machine->id])
            ->andReturn($machines);

        $rawIds = [(string) $machine->id, $machine->id, $machine->id, '0'];

        $result = $this->service->executeGroupMachinesAction($group->id, $rawIds, 'wake');

        // Une seule machine requested (dédoublonnée), 1 task créée, 1 job.
        $this->assertEquals(1, $result['requested_count']);
        $this->assertEquals(1, $result['success_count']);
        $this->assertEquals(0, $result['failed_count']);
        $this->assertEquals(1, MachinePowerActionTask::count());

        Queue::assertPushed(DispatchMachinePowerActionJob::class, 1);
    }
}
