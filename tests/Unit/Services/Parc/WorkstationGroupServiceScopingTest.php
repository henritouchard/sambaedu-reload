<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Parc;

use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Parc\WorkstationGroupService;
use App\Services\PermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests du paramètre `$scopeFor` sur WorkstationGroupService::listGroups / listMachines
 * (Story 7.1 — AC2, AC10).
 *
 * Invariants testés :
 *  - appel sans scopeFor → comportement historique (tout est visible)
 *  - scopeFor = admin avec droit global Spatie → tout est visible
 *  - scopeFor = délégué avec `computer.view` positive sur un group → uniquement ce group
 *  - scopeFor = délégué avec positive ET négative sur le même group → group filtré
 *  - listMachines cohérent avec le périmètre des groupes (machines des autres groups masquées)
 */
class WorkstationGroupServiceScopingTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;
    private WorkstationGroupService $service;
    private PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createTablesIfNeeded();

        // Neutraliser les jobs AD async (WorkstationGroupObserver dispatche
        // un WorkstationGroupAdSyncJob à la création — pas de LDAP dispo en test).
        Queue::fake();
        WorkstationGroupObserver::disableSync();

        $this->service = app(WorkstationGroupService::class);
        $this->permissionService = app(PermissionService::class);
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
            Schema::dropIfExists('workstations_migration_status');
            Schema::dropIfExists('workstation_application_status');
            Schema::dropIfExists('workstation_group_workstation');
            Schema::dropIfExists('workstation_groups');
            Schema::dropIfExists('workstations');
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

        // Table utilisée par withCount() dans le repository — stub minimal.
        if (!Schema::hasTable('workstation_application_status')) {
            Schema::create('workstation_application_status', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workstation_id');
                $table->unsignedBigInteger('application_id')->nullable();
                $table->string('status', 32)->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        // Story 16.13bis — table consultée par l'eager-load `migrationStatus`
        // ajouté à paginateMachines (Workstation::with('migrationStatus')).
        if (!Schema::hasTable('workstations_migration_status')) {
            Schema::create('workstations_migration_status', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('workstation_uuid', 36)->unique();
                $table->timestamp('migrated_at');
                $table->string('access_token_emitted_jti', 36)->nullable();
                $table->string('bootstrap_token_hash_prefix', 16)->nullable();
                $table->string('os', 16);
                $table->string('se4fs_name', 255)->nullable();
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
                $table->primary(['permission_id', 'model_id', 'model_type'], 'mhp_primary');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'mhr_primary');
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
                    'delegations_unique_scoping'
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

        // Seed permission requise
        Permission::firstOrCreate(['name' => 'computer.view', 'guard_name' => 'web']);
    }

    /**
     * Crée 3 groupes physiques + 2 machines par groupe.
     *
     * @return array{0: WorkstationGroup, 1: WorkstationGroup, 2: WorkstationGroup}
     */
    private function makeThreeGroupsWithMachines(): array
    {
        $groups = [];
        foreach (['A', 'B', 'C'] as $label) {
            $group = WorkstationGroup::create([
                'name' => "salle-{$label}-" . uniqid(),
                'is_physical' => true,
                'is_active' => true,
            ]);
            for ($i = 1; $i <= 2; $i++) {
                $ws = Workstation::create([
                    'name' => "pc-{$label}{$i}-" . uniqid(),
                    'os' => 'Windows 10',
                ]);
                $ws->groups()->attach($group->id, ['physical' => true]);
            }
            $groups[] = $group;
        }

        return $groups;
    }

    private function makeUser(string $login): User
    {
        return User::create([
            'login' => $login,
            'role' => 'prof',
            'is_active' => true,
        ]);
    }

    // ========================================================================
    // listGroups
    // ========================================================================

    public function test_list_groups_without_scope_user_returns_all(): void
    {
        [$a, $b, $c] = $this->makeThreeGroupsWithMachines();

        $result = $this->service->listGroups();

        // Comportement existant non cassé
        $ids = $result->pluck('id')->all();
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertContains($c->id, $ids);
    }

    public function test_list_groups_scoped_by_user_returns_only_authorized(): void
    {
        [$a, $b, $c] = $this->makeThreeGroupsWithMachines();
        $user = $this->makeUser('scoped-user');

        // Une seule délégation sur A
        $this->permissionService->grantDelegation($user, 'computer.view', $a);

        $result = $this->service->listGroups(scopeFor: $user);
        $ids = $result->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids);
        $this->assertNotContains($c->id, $ids);
    }

    public function test_list_groups_scoped_by_admin_returns_all(): void
    {
        [$a, $b, $c] = $this->makeThreeGroupsWithMachines();
        $admin = $this->makeUser('scoped-admin');

        // Droit global
        $admin->givePermissionTo('computer.view');

        $result = $this->service->listGroups(scopeFor: $admin->fresh());
        $ids = $result->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertContains($c->id, $ids);
    }

    /**
     * Story 7.1 — hiérarchie exclusion > global côté listing paginé : un admin
     * avec droit global mais une exclusion sur B ne doit plus voir B.
     */
    public function test_list_groups_scoped_by_admin_excludes_negatives(): void
    {
        [$a, $b, $c] = $this->makeThreeGroupsWithMachines();
        $admin = $this->makeUser('scoped-admin-excl');

        $admin->givePermissionTo('computer.view');
        $this->permissionService->negateDelegation($admin->fresh(), 'computer.view', $b);

        $result = $this->service->listGroups(scopeFor: $admin->fresh());
        $ids = $result->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids, 'Exclusion scopée doit retirer B même avec droit global.');
        $this->assertContains($c->id, $ids);
    }

    public function test_list_groups_scoped_by_user_with_no_delegation_returns_empty(): void
    {
        $this->makeThreeGroupsWithMachines();
        $user = $this->makeUser('scoped-empty');

        $result = $this->service->listGroups(scopeFor: $user);

        $this->assertEquals(0, $result->total());
    }

    public function test_list_groups_with_negative_delegation_is_excluded(): void
    {
        [$a, $b] = $this->makeThreeGroupsWithMachines();
        $user = $this->makeUser('scoped-neg');

        $this->permissionService->grantDelegation($user, 'computer.view', $a);
        $this->permissionService->grantDelegation($user, 'computer.view', $b);
        $this->permissionService->negateDelegation($user, 'computer.view', $b);

        $result = $this->service->listGroups(scopeFor: $user);
        $ids = $result->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids);
    }

    // ========================================================================
    // listMachines
    // ========================================================================

    public function test_list_machines_without_scope_returns_all(): void
    {
        $this->makeThreeGroupsWithMachines();

        $result = $this->service->listMachines();

        $this->assertGreaterThanOrEqual(6, $result->total());
    }

    public function test_list_machines_scoped_by_user_returns_only_machines_in_authorized_groups(): void
    {
        [$a, $b, $c] = $this->makeThreeGroupsWithMachines();
        $user = $this->makeUser('scoped-machines');

        $this->permissionService->grantDelegation($user, 'computer.view', $a);

        $result = $this->service->listMachines(scopeFor: $user);

        // Seulement 2 machines de A doivent remonter (A en a 2).
        $this->assertEquals(2, $result->total());

        foreach ($result->items() as $machine) {
            $groupIds = $machine->groups->pluck('id')->all();
            $this->assertContains($a->id, $groupIds);
        }
    }

    public function test_list_machines_scoped_by_admin_returns_all(): void
    {
        $this->makeThreeGroupsWithMachines();
        $admin = $this->makeUser('scoped-admin-m');
        $admin->givePermissionTo('computer.view');

        $result = $this->service->listMachines(scopeFor: $admin->fresh());

        $this->assertGreaterThanOrEqual(6, $result->total());
    }

    public function test_list_machines_with_unauthorized_group_filter_returns_empty(): void
    {
        [$a, $b] = $this->makeThreeGroupsWithMachines();
        $user = $this->makeUser('scoped-filter');

        $this->permissionService->grantDelegation($user, 'computer.view', $a);

        // L'user n'a pas accès à B → même avec un filtre explicite groupId=B, pagination vide.
        $result = $this->service->listMachines(groupId: $b->id, scopeFor: $user);

        $this->assertEquals(0, $result->total());
    }

    // ========================================================================
    // getRootGroupsForSelect — Review #7
    // ========================================================================

    /**
     * Story 7.1 — Review #7 : sans scope, toutes les racines sont exposées
     * (backward-compat).
     */
    public function test_root_groups_for_select_without_scope_returns_all(): void
    {
        [$a, $b, $c] = $this->makeThreeGroupsWithMachines();

        $result = $this->service->getRootGroupsForSelect();
        $ids = $result->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertContains($c->id, $ids);
    }

    /**
     * Story 7.1 — Review #7 : si un user est passé et qu'il a une délégation
     * scopée, seule la salle autorisée doit apparaître dans le dropdown.
     */
    public function test_root_groups_for_select_scoped_by_user(): void
    {
        [$a, $b, $c] = $this->makeThreeGroupsWithMachines();
        $user = $this->makeUser('root-scoped');

        $this->permissionService->grantDelegation($user, 'computer.view', $a);

        $result = $this->service->getRootGroupsForSelect($user);
        $ids = $result->pluck('id')->all();

        $this->assertContains($a->id, $ids, 'Le groupe délégué doit apparaître');
        $this->assertNotContains($b->id, $ids, 'Un groupe non délégué ne doit pas apparaître');
        $this->assertNotContains($c->id, $ids, 'Un groupe non délégué ne doit pas apparaître');
    }

    /**
     * Story 7.1 — Review #7 : user admin → pas de filtre appliqué, tout remonte.
     */
    public function test_root_groups_for_select_admin_user_returns_all(): void
    {
        [$a, $b, $c] = $this->makeThreeGroupsWithMachines();
        $admin = $this->makeUser('root-admin');
        $admin->givePermissionTo('computer.view');

        $result = $this->service->getRootGroupsForSelect($admin->fresh());
        $ids = $result->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertContains($c->id, $ids);
    }

    /**
     * Story 7.1 — Review #7 : user sans délégation → dropdown vide
     * (pas de fuite de noms).
     */
    public function test_root_groups_for_select_user_without_delegation_returns_empty(): void
    {
        $this->makeThreeGroupsWithMachines();
        $user = $this->makeUser('root-noaccess');

        $result = $this->service->getRootGroupsForSelect($user);

        $this->assertTrue($result->isEmpty(), 'Aucun groupe ne doit remonter pour un user sans délégation.');
    }
}
