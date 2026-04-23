<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Policies\MachinePolicy;
use App\Services\PermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 7.2 (AC5) — MachinePolicy.
 *
 * Le scoping via `WorkstationGroup` (parent N:N) est central. On teste :
 *  - droit global → accès à toute machine
 *  - pas de droit global + délégation scopée → accès uniquement aux machines
 *    dont un des groupes parents physiques est délégué
 *  - machine sans groupe physique parent → fallback droit global
 */
class MachinePolicyTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    private MachinePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->createPermissionSchema();
        $this->createWorkstationSchema();
        Queue::fake();
        (new PermissionSeeder())->run();
        $this->policy = new MachinePolicy();
        WorkstationGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        Schema::dropIfExists('workstation_group_workstation');
        Schema::dropIfExists('workstations');
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function createWorkstationSchema(): void
    {
        if (!Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('mac_address')->nullable();
                $table->string('ip_address')->nullable();
                $table->string('os_type', 50)->default('unknown');
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('workstation_group_workstation')) {
            Schema::create('workstation_group_workstation', function (Blueprint $table) {
                $table->unsignedBigInteger('workstation_id');
                $table->unsignedBigInteger('workstation_group_id');
                $table->timestamps();
                $table->primary(['workstation_id', 'workstation_group_id'], 'wgw_primary');
            });
        }
    }

    private function makeUser(string $login, array $perms = []): User
    {
        $user = User::create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
        foreach ($perms as $p) {
            $user->givePermissionTo($p);
        }
        return $user;
    }

    public function test_viewany_requires_computer_view(): void
    {
        $with = $this->makeUser('viewer', ['computer.view']);
        $without = $this->makeUser('none');

        $this->assertTrue($this->policy->viewAny($with));
        $this->assertFalse($this->policy->viewAny($without));
    }

    public function test_view_with_global_right_grants_any_machine(): void
    {
        $user = $this->makeUser('global', ['computer.view']);

        $group = WorkstationGroup::create(['name' => 'salleA', 'is_physical' => true]);
        $machine = Workstation::create(['name' => 'pc1']);
        $machine->groups()->attach($group->id);

        $this->assertTrue($this->policy->view($user, $machine));
    }

    public function test_view_with_scoped_delegation_grants_only_delegated_group(): void
    {
        $user = $this->makeUser('scoped');

        $salleA = WorkstationGroup::create(['name' => 'salleA', 'is_physical' => true]);
        $salleB = WorkstationGroup::create(['name' => 'salleB', 'is_physical' => true]);

        $machineA = Workstation::create(['name' => 'pcA']);
        $machineA->groups()->attach($salleA->id);
        $machineB = Workstation::create(['name' => 'pcB']);
        $machineB->groups()->attach($salleB->id);

        // Délégation scopée : computer.view uniquement sur salleA.
        app(PermissionService::class)->grantDelegation($user, 'computer.view', $salleA);

        $this->assertTrue($this->policy->view($user, $machineA), 'Accès salleA autorisé');
        $this->assertFalse($this->policy->view($user, $machineB), 'Accès salleB refusé');
    }

    public function test_control_requires_scoped_computer_control(): void
    {
        $user = $this->makeUser('ctrl');

        $salleA = WorkstationGroup::create(['name' => 'salleA', 'is_physical' => true]);
        $machine = Workstation::create(['name' => 'pc']);
        $machine->groups()->attach($salleA->id);

        // Seul computer.view délégué, pas computer.control.
        app(PermissionService::class)->grantDelegation($user, 'computer.view', $salleA);

        $this->assertFalse($this->policy->control($user, $machine));

        // On accorde computer.control.
        app(PermissionService::class)->grantDelegation($user, 'computer.control', $salleA);
        $this->assertTrue($this->policy->control($user, $machine));
    }

    public function test_elevate_requires_computer_elevate(): void
    {
        $user = $this->makeUser('elev', ['computer.elevate']);
        $machine = Workstation::create(['name' => 'pc']);

        $this->assertTrue($this->policy->elevate($user, $machine));
    }

    public function test_machine_without_physical_parent_fallbacks_to_global_right(): void
    {
        $user = $this->makeUser('no-parent');
        $machine = Workstation::create(['name' => 'pc-orphelin']);

        // Sans droit global, refus.
        $this->assertFalse($this->policy->view($user, $machine));

        // Avec droit global, OK.
        $user->givePermissionTo('computer.view');
        $user->refresh();
        $this->assertTrue($this->policy->view($user, $machine));
    }
}
