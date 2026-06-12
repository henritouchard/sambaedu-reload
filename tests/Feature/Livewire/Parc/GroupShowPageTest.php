<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Enums\AgentResourceStatus;
use App\Jobs\DispatchMachinePowerActionJob;
use App\Models\AgentResourceState;
use App\Models\MachinePowerActionTask;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Parc\MachinePowerService;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;
use Tests\Traits\MocksAdminUser;

/**
 * Tests Feature du composant Livewire `pages::parc.groups.[id].index`.
 *
 * Couvre les corrections review #4/#10 de la story 4-2 :
 *  - l'action shutdown-force est bien exposée dans le dropdown unitaire ET batch
 *    de la vue groupe (avec wire:confirm renforcé pour éviter les drames).
 *  - le dispatch du shutdown-force transite bien par
 *    WorkstationGroupService::executeGroupMachinesAction (même sémantique que
 *    les autres actions batch).
 *
 * Le setup réutilise le pattern createTablesIfNeeded() de MachineShowPageTest —
 * dette infra connue (cf. review #8, ticket `tech-debt-test-infra-cleanup`).
 */
class GroupShowPageTest extends TestCase
{
    use DatabaseTransactions;
    use MocksAdminUser;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        $this->createTablesIfNeeded();

        // Story 4-3 : Queue::fake() par défaut pour TOUS les tests de cette
        // suite — sinon l'observer WorkstationGroupObserver dispatche un
        // WorkstationGroupAdSyncJob qui tente d'écrire réellement dans l'AD
        // (LDAP absent en CI/local). Neutraliser aussi les jobs avant les
        // assertions DispatchMachinePowerActionJob est transparent (on compte
        // les jobs par classe, pas par total).
        Queue::fake();

        // Le composant Livewire guard ses actions power via
        // Gate::allows('computer.control'). MocksAdminUser pousse un
        // Authenticatable mocké qui ->can() renvoie true (donc Gate::allows
        // renvoie true pour toutes les abilities). On complète avec un
        // Gate::before par sécurité (user=null dans certains flux).
        $this->actAsAdmin();
        Gate::before(fn ($user, string $ability) => $ability === 'computer.control' ? true : null);
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('agent_report_events');
            Schema::dropIfExists('agent_resource_states');
            Schema::dropIfExists('printer_workstation_group');
            Schema::dropIfExists('printers');
            Schema::dropIfExists('workstation_group_schedule_runs');
            Schema::dropIfExists('workstation_group_schedules');
            Schema::dropIfExists('machine_power_action_tasks');
            Schema::dropIfExists('workstation_group_workstation');
            Schema::dropIfExists('workstation_groups');
            Schema::dropIfExists('workstations');
        }
        Carbon::setTestNow();
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
                // Story 23.2 / 24.7 — colonnes du canal agent.
                $table->string('agent_token_hash', 64)->nullable();
                $table->timestamp('agent_token_rotated_at')->nullable();
                $table->timestamp('agent_last_checkin_at')->nullable();
                $table->timestamp('agent_quarantined_at')->nullable();
                $table->timestamp('agent_sync_requested_at')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        // Story 24.7 — tables D3 (24.1) lues par ConformityService.
        if (!Schema::hasTable('agent_resource_states')) {
            Schema::create('agent_resource_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
                $table->string('type', 64);
                $table->string('status', 32);
                $table->string('hash', 64);
                $table->text('detail')->nullable();
                $table->timestamp('reported_at')->nullable();
                $table->timestamps();
                $table->unique(['workstation_id', 'type']);
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('agent_report_events')) {
            Schema::create('agent_report_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
                $table->string('type', 64);
                $table->string('previous_status', 32)->nullable();
                $table->string('status', 32);
                $table->string('hash', 64);
                $table->text('detail')->nullable();
                $table->timestamp('created_at')->nullable();
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

        if (!Schema::hasTable('machine_boot_logs')) {
            Schema::create('machine_boot_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workstation_id')->nullable();
                $table->string('machine_name');
                $table->string('action')->nullable();
                $table->string('initiated_by')->nullable();
                $table->boolean('success')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('stopped_at')->nullable();
                $table->string('os')->nullable();
                $table->integer('wol_score')->nullable();
                $table->integer('ipxe_score')->nullable();
                $table->integer('error_flags')->nullable();
                $table->integer('boot_speed')->nullable();
                $table->string('vlan')->nullable();
                $table->string('switch_port')->nullable();
                $table->string('switch_ip')->nullable();
                $table->string('switch_name')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        // Story 4-4 : schedules & runs (le partial schedules-panel est inclus
        // dans la vue parent et exécute une query au rendu — sans ces 2 tables
        // on obtient "no such table" à chaque assertSee() / call()).
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

        // Story 6.1 — l'onglet Imprimantes du partial machines-list invoque
        // $group->printers->count() au rendu, donc il faut a minima les 2
        // tables même vides pour que le rendu de la vue groupe ne casse pas.
        if (!Schema::hasTable('printers')) {
            Schema::create('printers', function (Blueprint $table) {
                $table->string('cups_name', 15)->primary();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->boolean('orphan')->default(false);
                $table->text('description_ser')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('printer_workstation_group')) {
            Schema::create('printer_workstation_group', function (Blueprint $table) {
                $table->string('cups_name', 15);
                $table->unsignedBigInteger('workstation_group_id');
                $table->timestamp('attached_at')->useCurrent();
                $table->unsignedBigInteger('attached_by_user_id')->nullable();
                $table->primary(['cups_name', 'workstation_group_id'], 'pwg_pk');
            });
            $this->createdTables = true;
        }
    }

    private function makeGroupWithMachines(int $count): array
    {
        $group = WorkstationGroup::create([
            'name' => 'lab-batch-' . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);

        $machines = [];
        for ($i = 1; $i <= $count; $i++) {
            $ws = Workstation::create([
                'name' => "pc-batch-{$i}-" . uniqid(),
                'os' => 'Windows 10',
                'ip' => "192.168.100.{$i}",
                'mac' => sprintf('aa:bb:cc:dd:ee:%02x', $i),
                'status' => 1,
            ]);
            $ws->groups()->attach($group->id, ['physical' => true]);
            $machines[] = $ws;
        }

        return [$group, $machines];
    }

    /**
     * Laisse passer l'appel réel au service Laravel pour tester le pipeline
     * async en bout-en-bout (vs. mock du service dans mockGroupService).
     */
    private function bindPowerServiceMock(): MachinePowerService
    {
        $mock = Mockery::mock(MachinePowerService::class);
        $this->app->instance(MachinePowerService::class, $mock);
        return $mock;
    }

    private function makeGroupWithMachine(): array
    {
        $group = WorkstationGroup::create([
            'name' => 'lab-chimie',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $machine = Workstation::create([
            'name' => 'pc-chimie-01',
            'os' => 'Windows 10',
            'ip' => '127.0.0.1',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 1,
        ]);

        $machine->groups()->attach($group->id, ['physical' => true]);

        return [$group, $machine];
    }

    /**
     * Mock du service avec shutdown-force visible dans getAvailableMachineActions().
     * Nécessaire car la vue groupe itère sur ces actions pour construire le dropdown.
     */
    private function mockGroupService(WorkstationGroup $group): WorkstationGroupService
    {
        $mock = Mockery::mock(WorkstationGroupService::class);
        $mock->shouldReceive('getGroup')->andReturn($group);
        $mock->shouldReceive('getAvailableMachineActions')->andReturn([
            ['key' => 'wake', 'label' => 'Allumer', 'icon' => 'fa-solid fa-power-off', 'requires_confirmation' => false],
            ['key' => 'shutdown', 'label' => 'Éteindre', 'icon' => 'fa-solid fa-stop', 'requires_confirmation' => true],
            ['key' => 'shutdown-force', 'label' => "Forcer l'extinction", 'icon' => 'fa-solid fa-triangle-exclamation', 'requires_confirmation' => true],
            ['key' => 'restart', 'label' => 'Redémarrer', 'icon' => 'fa-solid fa-rotate-right', 'requires_confirmation' => true],
            ['key' => 'remote', 'label' => 'Accès distant', 'icon' => 'fa-solid fa-desktop', 'requires_confirmation' => false],
        ]);
        $mock->shouldReceive('getMachineActionLabel')->andReturnUsing(fn (string $a) => match ($a) {
            'wake' => 'allumage',
            'shutdown' => 'extinction',
            'shutdown-force' => 'extinction forcée',
            'restart' => 'redémarrage',
            'remote' => 'accès distant',
            default => $a,
        });

        $this->app->instance(WorkstationGroupService::class, $mock);
        return $mock;
    }

    // ─── Tests ──────────────────────────────────────────────────────────────

    public function test_group_dropdown_exposes_shutdown_force_action(): void
    {
        // Review #4/#10 — la page groupe doit afficher l'entrée "Forcer
        // l'extinction" dans le dropdown d'actions machines.
        [$group, ] = $this->makeGroupWithMachine();
        $this->mockGroupService($group);

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id]);
        // Apostrophe HTML-encodée par Blade (e(...)) → matcher sur l'entité.
        $component->assertSeeHtml('Forcer l&#039;extinction');
    }

    public function test_group_shutdown_force_dispatches_to_group_service_with_force_true(): void
    {
        // Review #10 — cliquer "Forcer l'extinction" sur une machine depuis la
        // vue groupe doit appeler executeGroupMachinesAction(groupId, [machineId], 'shutdown-force').
        [$group, $machine] = $this->makeGroupWithMachine();
        $mock = $this->mockGroupService($group);
        $mock->shouldReceive('executeGroupMachinesAction')
            ->with($group->id, [$machine->id], 'shutdown-force')
            ->once()
            ->andReturn([
                'action' => 'shutdown-force',
                'requested_count' => 1,
                'success_count' => 1,
                'failed_count' => 0,
                // Contrat 4-3 : code=202 (dispatched async) + task_id renseigné.
                'results' => [['machine' => 'pc-chimie-01', 'success' => true, 'code' => 202, 'task_id' => 99]],
            ]);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('executeMachineAction', $machine->id, 'shutdown-force')
            ->assertDispatched('toastMagic', status: 'success');
    }

    public function test_group_shutdown_force_batch_requires_confirmation(): void
    {
        // Review #4 — le dropdown BATCH (actions sur la sélection) doit
        // inclure shutdown-force avec le wire:confirm au pluriel.
        [$group, $machine] = $this->makeGroupWithMachine();
        $this->mockGroupService($group);

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->set('selectedGroupMachineIds', [$machine->id]);

        // Le message de confirmation batch doit être présent dans le rendu
        // (apostrophes HTML-encodées par Blade via e(...)).
        $component->assertSeeHtml('Forcer l&#039;extinction de TOUTES les machines sélectionnées');
        $component->assertSeeHtml('les utilisateurs peuvent perdre leur travail non sauvegardé');
    }

    public function test_group_shutdown_force_batch_dispatches_with_correct_action(): void
    {
        // Complément test #3 — valider le dispatch côté batch (sélection multi).
        [$group, $machine] = $this->makeGroupWithMachine();
        $mock = $this->mockGroupService($group);
        $mock->shouldReceive('executeGroupMachinesAction')
            ->with($group->id, [$machine->id], 'shutdown-force')
            ->once()
            ->andReturn([
                'action' => 'shutdown-force',
                'requested_count' => 1,
                'success_count' => 1,
                'failed_count' => 0,
                // Contrat 4-3 : code=202 (dispatched async) + task_id renseigné
                // → permet à executeSelectedGroupMachinesAction de basculer batchRunning=true.
                'results' => [['machine' => 'pc-chimie-01', 'success' => true, 'code' => 202, 'task_id' => 99]],
            ]);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->set('selectedGroupMachineIds', [$machine->id])
            ->call('executeSelectedGroupMachinesAction', 'shutdown-force')
            ->assertSet('batchRunning', true)
            ->assertDispatched('toastMagic', status: 'success');
    }

    // ─── Tests story 4-3 (pipeline async, polling, résumé, idempotence) ────
    // Ces tests utilisent le vrai WorkstationGroupService (pas de mock) pour
    // valider le pipeline end-to-end : création de MachinePowerActionTask,
    // dispatch de DispatchMachinePowerActionJob, machine à états batch.

    public function test_batch_dispatch_creates_one_task_per_machine_and_emits_success_toast(): void
    {
        Queue::fake();
        $this->bindPowerServiceMock();

        [$group, $machines] = $this->makeGroupWithMachines(3);
        $machineIds = array_map(fn ($m) => $m->id, $machines);

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->set('selectedGroupMachineIds', $machineIds)
            ->call('executeSelectedGroupMachinesAction', 'wake')
            ->assertDispatched('toastMagic', status: 'success');

        $this->assertEquals(3, MachinePowerActionTask::count());
        foreach (MachinePowerActionTask::all() as $task) {
            $this->assertEquals('wake', $task->action);
            $this->assertEquals(MachinePowerActionTask::STATUS_QUEUED, $task->status);
        }

        Queue::assertPushed(DispatchMachinePowerActionJob::class, 3);

        // Review #6 — la propriété pivot du polling doit contenir exactement
        // les IDs des tasks créées pour les machines sélectionnées.
        $taskIds = MachinePowerActionTask::query()
            ->whereIn('workstation_id', $machineIds)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
        $currentBatchTaskIds = collect($component->get('currentBatchTaskIds'))->sort()->values()->all();
        $this->assertEquals($taskIds, $currentBatchTaskIds);
    }

    public function test_batch_sets_batch_running_state_and_disables_batch_dropdown(): void
    {
        Queue::fake();
        $this->bindPowerServiceMock();

        [$group, $machines] = $this->makeGroupWithMachines(2);
        $ids = array_map(fn ($m) => $m->id, $machines);

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->set('selectedGroupMachineIds', $ids)
            ->call('executeSelectedGroupMachinesAction', 'shutdown')
            ->assertSet('batchRunning', true)
            ->assertSet('batchActionKey', 'shutdown')
            ->assertSet('batchSummaryVisible', true);

        // La barre flottante ne s'affiche que quand il y a des machines
        // sélectionnées ; après dispatch on purge la sélection, donc
        // on ne teste pas la barre flottante mais le badge "en cours"
        // dans le slot actions (présence du loading spinner).
        $component->assertSeeHtml('loading loading-spinner');
    }

    public function test_poll_group_readiness_updates_task_statuses_and_stops_when_all_terminal(): void
    {
        Queue::fake();

        [$group, $machines] = $this->makeGroupWithMachines(2);
        $ids = array_map(fn ($m) => $m->id, $machines);

        // Le power service doit retourner "linux" (machine up) → wake completed.
        $power = $this->bindPowerServiceMock();
        $power->shouldReceive('ping')->andReturn('linux');

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->set('selectedGroupMachineIds', $ids)
            ->call('executeSelectedGroupMachinesAction', 'wake')
            ->assertSet('batchRunning', true);

        // Simuler que les jobs ont pris les tasks (status=running).
        MachinePowerActionTask::query()->update(['status' => MachinePowerActionTask::STATUS_RUNNING]);

        $component->call('pollGroupReadiness')
            ->assertSet('batchRunning', false);

        // Toutes les tasks doivent être completed.
        foreach (MachinePowerActionTask::all() as $task) {
            $this->assertEquals(MachinePowerActionTask::STATUS_COMPLETED, $task->status);
            $this->assertNotNull($task->completed_at);
        }
    }

    public function test_poll_group_readiness_times_out_all_active_tasks_after_configured_duration(): void
    {
        Queue::fake();
        Config::set('parc.machine_readiness_timeout_seconds', 60);

        [$group, $machines] = $this->makeGroupWithMachines(2);
        $ids = array_map(fn ($m) => $m->id, $machines);

        $power = $this->bindPowerServiceMock();
        // Le ping NE DOIT PAS être appelé : le timeout doit court-circuiter
        // avant toute tentative de ping (invariant important pour éviter
        // des appels réseau inutiles après le cut-off).
        $power->shouldNotReceive('ping');
        $power->shouldReceive('logReadinessTimeout')->atLeast()->once();

        $start = Carbon::parse('2026-04-21 10:00:00');
        Carbon::setTestNow($start);

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->set('selectedGroupMachineIds', $ids)
            ->call('executeSelectedGroupMachinesAction', 'wake')
            ->assertSet('batchRunning', true);

        // +61s → dépassement du timeout (60s).
        Carbon::setTestNow($start->copy()->addSeconds(61));

        $component->call('pollGroupReadiness')
            ->assertSet('batchRunning', false)
            ->assertSet('batchTimeoutFired', true)
            ->assertDispatched('toastMagic', status: 'warning');

        foreach (MachinePowerActionTask::all() as $task) {
            $this->assertEquals(MachinePowerActionTask::STATUS_FAILED, $task->status);
            $this->assertStringContainsString('timeout', strtolower((string) $task->error_message));
        }
    }

    public function test_batch_summary_card_lists_failed_machines_with_error_messages(): void
    {
        Queue::fake();
        $this->bindPowerServiceMock();

        [$group, $machines] = $this->makeGroupWithMachines(2);
        $ids = array_map(fn ($m) => $m->id, $machines);

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->set('selectedGroupMachineIds', $ids)
            ->call('executeSelectedGroupMachinesAction', 'shutdown');

        // Forcer une des tasks en failed avec un error_message humain.
        $firstTask = MachinePowerActionTask::first();
        $firstTask->update([
            'status' => MachinePowerActionTask::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => "pc-batch-1 est deja eteinte, aucune action effectuee",
        ]);

        // Re-render le composant pour consommer l'update DB.
        $component->call('$refresh');

        $component->assertSeeHtml($firstTask->workstation->name);
        $component->assertSeeHtml('est deja eteinte');
    }

    public function test_batch_summary_clear_button_resets_correlation_without_deleting_db_rows(): void
    {
        Queue::fake();
        $this->bindPowerServiceMock();

        [$group, $machines] = $this->makeGroupWithMachines(1);

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->set('selectedGroupMachineIds', [$machines[0]->id])
            ->call('executeSelectedGroupMachinesAction', 'shutdown')
            ->assertSet('batchSummaryVisible', true);

        $this->assertEquals(1, MachinePowerActionTask::count());

        $component->call('clearBatchSummary')
            ->assertSet('batchSummaryVisible', false)
            ->assertSet('currentBatchTaskIds', [])
            ->assertSet('batchAction', null);

        // Les rows DB sont conservées (audit trail).
        $this->assertEquals(1, MachinePowerActionTask::count());
    }

    public function test_batch_skips_machines_with_active_tasks_and_warns(): void
    {
        // AC7 idempotence : relancer un batch sur des machines dont une task
        // est déjà active doit skip ces machines et toaster warning.
        Queue::fake();
        $this->bindPowerServiceMock();

        [$group, $machines] = $this->makeGroupWithMachines(2);
        $ids = array_map(fn ($m) => $m->id, $machines);

        // Simuler une task déjà active sur la première machine (ex. batch précédent en vol).
        MachinePowerActionTask::create([
            'workstation_id' => $machines[0]->id,
            'action' => 'wake',
            'status' => MachinePowerActionTask::STATUS_RUNNING,
            'initiated_at' => now(),
        ]);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->set('selectedGroupMachineIds', $ids)
            ->call('executeSelectedGroupMachinesAction', 'wake')
            ->assertDispatched('toastMagic', status: 'warning');

        // 1 nouvelle task créée (pour machine 2), 1 pré-existante → total 2.
        $this->assertEquals(2, MachinePowerActionTask::count());
        Queue::assertPushed(DispatchMachinePowerActionJob::class, 1);
    }

    public function test_unit_action_from_group_view_respects_active_task_guard(): void
    {
        // Parité vue machine : une action unitaire sur une machine ayant
        // déjà une task active doit être refusée (toast warning).
        Queue::fake();
        $this->bindPowerServiceMock();

        [$group, $machines] = $this->makeGroupWithMachines(1);
        $machine = $machines[0];

        MachinePowerActionTask::create([
            'workstation_id' => $machine->id,
            'action' => 'wake',
            'status' => MachinePowerActionTask::STATUS_RUNNING,
            'initiated_at' => now(),
        ]);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('executeMachineAction', $machine->id, 'shutdown')
            ->assertDispatched('toastMagic', status: 'warning');

        // Pas de nouvelle task créée.
        $this->assertEquals(1, MachinePowerActionTask::count());
        Queue::assertNotPushed(DispatchMachinePowerActionJob::class);
    }

    public function test_remote_action_not_in_batch_dropdown(): void
    {
        // AC6 : le dropdown BATCH ne doit PAS exposer l'action `remote`
        // (inverse du dropdown unitaire qui la conserve). Assertions
        // structurelles sur les wire:click exacts (review #4 — remplace
        // un substr_count fragile qui cassait dès qu'on passait à 2 machines).
        [$group, $machines] = $this->makeGroupWithMachines(1);
        $machineId = $machines[0]->id;

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->set('selectedGroupMachineIds', [$machineId]);

        // Entrée batch `remote` absente du dropdown batch.
        $component->assertDontSeeHtml("wire:click=\"executeSelectedGroupMachinesAction('remote')\"");

        // Entrée unitaire `remote` présente pour cette machine (dropdown par ligne).
        $component->assertSeeHtml("wire:click=\"executeMachineAction({$machineId}, 'remote')\"");

        // Les 4 actions batch supportées doivent rester présentes.
        foreach (['wake', 'shutdown', 'shutdown-force', 'restart'] as $action) {
            $component->assertSeeHtml("wire:click=\"executeSelectedGroupMachinesAction('{$action}')\"");
        }
    }

    // ─── Story 24.7 — Panneau conformité du groupe (AC3, AC5) ───────────────

    private function enroll(Workstation $ws): Workstation
    {
        $ws->agent_token_hash = str_repeat('a', 64) . $ws->id; // unicité hash
        $ws->agent_token_hash = substr(hash('sha256', (string) $ws->id), 0, 64);
        $ws->agent_last_checkin_at = now();
        $ws->save();

        return $ws->refresh();
    }

    private function seedState(Workstation $ws, string $type, AgentResourceStatus $status, ?string $detail = null): void
    {
        AgentResourceState::create([
            'workstation_id' => $ws->id,
            'type' => $type,
            'status' => $status,
            'hash' => str_repeat('b', 64),
            'detail' => $detail,
            'reported_at' => now(),
        ]);
    }

    public function test_group_conformity_panel_lists_only_exceptions(): void
    {
        // AC3 — panneau par type : « n/N conformes » + SEULES les exceptions.
        [$group, $machines] = $this->makeGroupWithMachines(2);
        $this->mockGroupService($group);

        $ok = $this->enroll($machines[0]);
        $this->seedState($ok, 'wallpaper', AgentResourceStatus::Compliant);

        $bad = $this->enroll($machines[1]);
        $this->seedState($bad, 'wallpaper', AgentResourceStatus::Error, 'kaboom');

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id]);

        $component->assertSee('Conformité agent');
        $component->assertSee('wallpaper');
        // « n/N conformes » : 1 sur 2 conforme.
        $component->assertSee('1/2 conformes');

        // Le panneau ne liste QUE les exceptions : on assert sur la structure
        // de données du composant (le nom des postes apparaît aussi dans la
        // liste des membres du groupe, d'où l'assertion sur la propriété).
        $byType = $component->get('conformityByType');
        $this->assertCount(1, $byType);
        $this->assertSame('wallpaper', $byType[0]['type']);
        $this->assertSame(1, $byType[0]['compliant']);
        $exceptionIds = array_column($byType[0]['exceptions'], 'workstation_id');
        $this->assertContains($bad->id, $exceptionIds);
        $this->assertNotContains($ok->id, $exceptionIds);
    }

    public function test_force_sync_group_requests_eligible_members(): void
    {
        // AC5 — bouton groupe : pose la demande sur les membres enrôlés non
        // quarantaine, ignore les autres (toast récapitulatif).
        [$group, $machines] = $this->makeGroupWithMachines(2);
        $this->mockGroupService($group);

        $enrolled = $this->enroll($machines[0]);
        // $machines[1] reste non enrôlé.

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('forceSyncGroup')
            ->assertDispatched('toastMagic', status: 'success');

        $this->assertNotNull($enrolled->refresh()->agent_sync_requested_at);
        $this->assertNull($machines[1]->refresh()->agent_sync_requested_at);
    }
}
