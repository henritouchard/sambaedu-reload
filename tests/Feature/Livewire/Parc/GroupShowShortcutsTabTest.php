<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\Shortcut;
use App\Models\WorkstationGroup;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;
use Tests\Traits\MocksAdminUser;

/**
 * Onglet « Raccourcis » de la page groupe de postes.
 *
 * Vérifie l'assignation raccourci ↔ groupe depuis le versant parc
 * (`WorkstationGroup::shortcuts()`, pivot `shortcut_assignables`), miroir de
 * l'assignation gérée côté page raccourci.
 */
class GroupShowShortcutsTabTest extends TestCase
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

        Queue::fake();
        $this->actAsAdmin();
        // La page gate ses mutations groupe via `update-workstationGroup`
        // (Policy update). L'admin mocké a ->can()=true, on renforce via before.
        Gate::before(fn ($user, string $ability) => in_array($ability, ['update-workstationGroup', 'view', 'computer.control'], true) ? true : null);
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('shortcut_assignables');
            Schema::dropIfExists('shortcuts');
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
                $table->string('agent_token_hash', 64)->nullable();
                $table->timestamp('agent_token_rotated_at')->nullable();
                $table->timestamp('agent_last_checkin_at')->nullable();
                $table->timestamp('agent_quarantined_at')->nullable();
                $table->timestamp('agent_sync_requested_at')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

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
                $table->boolean('is_default')->default(false);
                $table->primary(['cups_name', 'workstation_group_id'], 'pwg_pk');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('shortcuts')) {
            Schema::create('shortcuts', function (Blueprint $table) {
                $table->id();
                $table->string('key');
                $table->string('name');
                $table->string('place')->default('desktop');
                $table->boolean('is_global')->default(false);
                $table->boolean('is_active')->default(true);
                $table->string('category')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('shortcut_assignables')) {
            Schema::create('shortcut_assignables', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shortcut_id')->constrained('shortcuts')->cascadeOnDelete();
                $table->morphs('assignable');
                $table->timestamps();
                $table->unique(['shortcut_id', 'assignable_id', 'assignable_type'], 'shortcut_assignable_unique');
            });
            $this->createdTables = true;
        }
    }

    private function makeGroup(): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => 'salle-info-' . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    private function makeShortcut(array $attrs = []): Shortcut
    {
        return Shortcut::create(array_merge([
            'key' => 'sc-' . uniqid(),
            'name' => 'Raccourci ' . uniqid(),
            'place' => 'desktop',
            'is_global' => false,
            'is_active' => true,
        ], $attrs));
    }

    private function mockService(WorkstationGroup $group): void
    {
        $mock = Mockery::mock(WorkstationGroupService::class);
        $mock->shouldReceive('getGroup')->andReturn($group);
        $mock->shouldReceive('getAvailableMachineActions')->andReturn([]);
        $this->app->instance(WorkstationGroupService::class, $mock);
    }

    public function test_shortcuts_tab_is_selectable_and_renders(): void
    {
        $group = $this->makeGroup();
        $this->mockService($group);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('setTab', 'shortcuts')
            ->assertSet('tab', 'shortcuts')
            ->assertSee('Raccourcis attribués');
    }

    public function test_attach_shortcuts_creates_pivot_rows(): void
    {
        $group = $this->makeGroup();
        $sc1 = $this->makeShortcut(['name' => 'Firefox']);
        $sc2 = $this->makeShortcut(['name' => 'LibreOffice']);
        $this->mockService($group);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('setTab', 'shortcuts')
            ->set('selectedShortcutIdsToAdd', [$sc1->id, $sc2->id])
            ->call('attachShortcuts')
            ->assertDispatched('toastMagic', status: 'success');

        $this->assertEqualsCanonicalizing(
            [$sc1->id, $sc2->id],
            $group->shortcuts()->pluck('shortcuts.id')->all(),
        );
    }

    public function test_detach_shortcut_removes_pivot_row(): void
    {
        $group = $this->makeGroup();
        $sc = $this->makeShortcut();
        $group->shortcuts()->attach($sc->id);
        $this->mockService($group);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('setTab', 'shortcuts')
            ->call('detachShortcut', $sc->id)
            ->assertDispatched('toastMagic', status: 'success');

        $this->assertCount(0, $group->shortcuts()->get());
    }

    public function test_available_shortcuts_excludes_attached_and_global(): void
    {
        $group = $this->makeGroup();
        $attached = $this->makeShortcut(['name' => 'Deja assigne']);
        $global = $this->makeShortcut(['name' => 'ControlHub', 'is_global' => true]);
        $free = $this->makeShortcut(['name' => 'Disponible']);
        $group->shortcuts()->attach($attached->id);
        $this->mockService($group);

        $component = Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->call('setTab', 'shortcuts');

        $availableIds = collect($component->get('availableShortcuts'))->pluck('id')->all();

        $this->assertContains($free->id, $availableIds);
        $this->assertNotContains($attached->id, $availableIds);
        $this->assertNotContains($global->id, $availableIds);
    }
}
