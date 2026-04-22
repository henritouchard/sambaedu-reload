<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Policies\WorkstationGroupPolicy;
use App\Services\PermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests Feature du scoping Policy WorkstationGroupPolicy (Story 7.1 — AC3, AC10).
 *
 * On teste directement la Policy via `Gate::forUser($user)->allows('view', $group)`
 * plutôt que via HTTP : les pages Livewire `/parc/*` ont un pipeline de setup
 * lourd (sidebar, auth guard legacy…) qui ne se prête pas à un test headless
 * isolé. La Policy est le socle logique de AC3 — si elle est correcte et si
 * elle est bien appelée dans `mount()` (vérifié par inspection du code), le
 * contrat est respecté.
 */
class WorkstationGroupScopingTest extends TestCase
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
        WorkstationGroupObserver::disableSync();

        // La Policy utilise des gates custom via registerGates() — on doit
        // s'assurer que Laravel connaît la bindable (Gate::allows('view', $group)
        // résout automatiquement vers WorkstationGroupPolicy si le binding est OK).
        Gate::policy(WorkstationGroup::class, WorkstationGroupPolicy::class);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();

        if ($this->createdTables) {
            Schema::dropIfExists('delegation_history');
            Schema::dropIfExists('delegations');
            Schema::dropIfExists('model_has_permissions');
            Schema::dropIfExists('model_has_roles');
            Schema::dropIfExists('role_has_permissions');
            Schema::dropIfExists('permissions');
            Schema::dropIfExists('roles');
            Schema::dropIfExists('workstation_groups');
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    private function createTablesIfNeeded(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255)->unique();
                $table->string('password', 255)->nullable();
                $table->string('fullname', 255)->nullable();
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->timestamp('ad_synced_at')->nullable();
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
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_id', 'model_type'], 'mhp_scope');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'mhr_scope');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('delegations')) {
            Schema::create('delegations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('workstation_group_id');
                $table->unsignedBigInteger('permission_id');
                $table->boolean('is_negative')->default(false);
                $table->unsignedBigInteger('granted_by')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->unique(
                    ['user_id', 'workstation_group_id', 'permission_id', 'is_negative'],
                    'delegations_scope_unique'
                );
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('delegation_history')) {
            Schema::create('delegation_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->unsignedBigInteger('target_user_id')->nullable();
                $table->unsignedBigInteger('workstation_group_id')->nullable();
                $table->string('permission_name', 255);
                $table->string('action', 32);
                $table->boolean('is_negative')->default(false);
                $table->json('context')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
            $this->createdTables = true;
        }

        Permission::firstOrCreate(['name' => 'computer.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'computer.control', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'computer.modify', 'guard_name' => 'web']);
    }

    private function makeUser(string $login): User
    {
        return User::create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
    }

    private function makeGroup(string $name = 'scope-group'): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => "{$name}-" . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    // ========================================================================
    // AC3 — Blocage d'accès direct hors périmètre
    // ========================================================================

    public function test_delegated_user_can_view_authorized_group(): void
    {
        $user = $this->makeUser('prof-authorized');
        $group = $this->makeGroup('authorized');

        app(PermissionService::class)->grantDelegation($user, 'computer.view', $group);

        $this->assertTrue(Gate::forUser($user->fresh())->allows('view', $group));
    }

    public function test_delegated_user_cannot_view_unauthorized_group(): void
    {
        $user = $this->makeUser('prof-unauth');
        $unauthorized = $this->makeGroup('unauthorized');

        $this->assertFalse(Gate::forUser($user)->allows('view', $unauthorized));
    }

    public function test_admin_with_global_permission_can_view_all_groups(): void
    {
        $admin = $this->makeUser('admin-global');
        $admin->givePermissionTo('computer.view');

        $groupA = $this->makeGroup('gA');
        $groupB = $this->makeGroup('gB');

        $this->assertTrue(Gate::forUser($admin->fresh())->allows('view', $groupA));
        $this->assertTrue(Gate::forUser($admin->fresh())->allows('view', $groupB));
    }

    public function test_revoked_user_loses_access_immediately(): void
    {
        $user = $this->makeUser('prof-revoke');
        $group = $this->makeGroup('revoke-target');
        $svc = app(PermissionService::class);

        // Accès initialement autorisé
        $svc->grantDelegation($user, 'computer.view', $group);
        $this->assertTrue(Gate::forUser($user->fresh())->allows('view', $group));

        // Revoke → accès immédiatement perdu (pas de cache session à buster)
        $svc->revokeDelegation($user, 'computer.view', $group);
        $this->assertFalse(Gate::forUser($user->fresh())->allows('view', $group));
    }

    public function test_negative_delegation_overrides_positive(): void
    {
        $user = $this->makeUser('prof-neg-policy');
        $group = $this->makeGroup('neg-target');
        $svc = app(PermissionService::class);

        $svc->grantDelegation($user, 'computer.view', $group);
        $svc->negateDelegation($user, 'computer.view', $group);

        $this->assertFalse(Gate::forUser($user->fresh())->allows('view', $group));
    }

    // ========================================================================
    // manage() gate — Story 7.1 ajout
    // ========================================================================

    public function test_manage_gate_requires_computer_control(): void
    {
        $user = $this->makeUser('prof-manage');
        $group = $this->makeGroup('manage-target');

        // Pas de délégation `computer.control` → manage refusé
        $this->assertFalse(Gate::forUser($user)->allows('manage', $group));

        // Ajout d'une délégation `computer.control` → manage OK
        app(PermissionService::class)->grantDelegation($user, 'computer.control', $group);
        $this->assertTrue(Gate::forUser($user->fresh())->allows('manage', $group));
    }

    public function test_manage_gate_allowed_with_global_computer_control(): void
    {
        $admin = $this->makeUser('admin-manage');
        $admin->givePermissionTo('computer.control');

        $group = $this->makeGroup('global-manage');

        $this->assertTrue(Gate::forUser($admin->fresh())->allows('manage', $group));
    }

    // ========================================================================
    // Review #1 — delete/update gate serveur
    // ========================================================================

    /**
     * Story 7.1 — Review #1 : la gate `delete-workstationGroup` doit retomber
     * sur `canAdminComputers` (droit global `computer.modify`). Un délégué
     * sur un groupe physique ne peut PAS le supprimer, même s'il a la
     * délégation `computer.view`.
     */
    public function test_delegated_user_cannot_delete_group(): void
    {
        $user = $this->makeUser('prof-try-delete');
        $group = $this->makeGroup('del-target');

        // Même avec une délégation view, impossible de delete.
        app(PermissionService::class)->grantDelegation($user, 'computer.view', $group);

        $this->assertFalse(Gate::forUser($user->fresh())->allows('delete-workstationGroup', $group));
        $this->assertFalse(Gate::forUser($user->fresh())->allows('update-workstationGroup', $group));
    }

    public function test_admin_with_computer_modify_can_delete_group(): void
    {
        $admin = $this->makeUser('admin-can-delete');
        $admin->givePermissionTo('computer.modify');

        $group = $this->makeGroup('del-admin-target');

        $this->assertTrue(Gate::forUser($admin->fresh())->allows('delete-workstationGroup', $group));
        $this->assertTrue(Gate::forUser($admin->fresh())->allows('update-workstationGroup', $group));
    }
}
