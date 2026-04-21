<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

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

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
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
                'results' => [['machine' => 'pc-chimie-01', 'success' => true, 'code' => 201]],
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
                'results' => [['machine' => 'pc-chimie-01', 'success' => true, 'code' => 201]],
            ]);

        Livewire::test('pages::parc.groups.[id].index', ['id' => $group->id])
            ->set('selectedGroupMachineIds', [$machine->id])
            ->call('executeSelectedGroupMachinesAction', 'shutdown-force')
            ->assertDispatched('toastMagic', status: 'success');
    }
}
