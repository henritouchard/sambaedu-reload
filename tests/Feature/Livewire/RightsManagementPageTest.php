<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Models\Delegation;
use App\Models\DelegationHistory;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\PermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests Feature Livewire de la page `/rights-management` (Story 7.1 — AC6, AC8, AC10).
 *
 * On teste via `Livewire::test(livewire-component, ...)` en chargeant la SFC
 * Blade directement. Pattern hérité des tests Parc (createTablesIfNeeded).
 *
 * La SFC `/resources/views/pages/rights-management/index.blade.php` est enregistrée
 * automatiquement par le filesystem-based router Livewire — Livewire::test()
 * accepte la classe anonyme via son path résolu.
 */
class RightsManagementPageTest extends TestCase
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
                $table->string('firstname', 255)->nullable();
                $table->string('lastname', 255)->nullable();
                $table->string('email', 255)->nullable();
                $table->string('phone', 50)->nullable();
                $table->text('description')->nullable();
                $table->string('dn', 500)->nullable();
                $table->string('ad_guid', 100)->nullable();
                $table->string('school_code', 100)->nullable();
                $table->string('school_name', 255)->nullable();
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->json('ad_right_profiles')->nullable();
                $table->unsignedInteger('ad_rights_bitmask')->default(0);
                $table->timestamp('ad_synced_at')->nullable();
                $table->timestamp('pwd_reset_at')->nullable();
                $table->string('remember_token', 100)->nullable();
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
                $table->primary(['permission_id', 'model_id', 'model_type'], 'mhp_rm');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'mhr_rm');
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
                    'delegations_rm_unique'
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
        // Story 7.1 — Review #5 : la permission `user.assign.right` est exigée
        // pour tout appel Livewire du `rights-management` (route + guards méthodes).
        Permission::firstOrCreate(['name' => 'user.assign.right', 'guard_name' => 'web']);
    }

    private function makeUser(string $login): User
    {
        return User::create([
            'login' => $login,
            'role' => 'prof',
            'is_active' => true,
        ]);
    }

    /**
     * Story 7.1 — Review #5 : donne la permission `user.assign.right` à un user
     * pour qu'il puisse déclencher les guards Livewire (revokeDelegation, etc.).
     */
    private function grantAdminPermission(User $user): User
    {
        $user->givePermissionTo('user.assign.right');
        return $user;
    }

    private function makeGroup(string $name = 'rm-group'): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => "{$name}-" . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Résout le nom canonique du composant Livewire filesystem-based pour
     * /rights-management — utilisé par Livewire::test(name).
     */
    private function pageComponent(): string
    {
        // Namespace filesystem Livewire du projet : "pages::<slug>..."
        return 'pages::rights-management.index';
    }

    // ========================================================================
    // Onglets
    // ========================================================================

    public function test_can_switch_between_4_tabs(): void
    {
        $admin = $this->makeUser('admin-tabs');
        $this->actingAs($admin);

        Livewire::test($this->pageComponent())
            ->assertSet('activeTab', 'overview')
            ->call('setActiveTab', 'user-lookup')
            ->assertSet('activeTab', 'user-lookup')
            ->call('setActiveTab', 'delegations')
            ->assertSet('activeTab', 'delegations')
            ->call('setActiveTab', 'history')
            ->assertSet('activeTab', 'history');
    }

    public function test_history_tab_displays_recent_audits(): void
    {
        $actor = $this->makeUser('audit-actor');
        $target = $this->makeUser('audit-target');
        $group = $this->makeGroup('audit-grp');

        app(PermissionService::class)->grantDelegation($target, 'computer.view', $group, $actor);

        $this->actingAs($actor);

        $component = Livewire::test($this->pageComponent())
            ->call('setActiveTab', 'history');

        $entries = $component->get('historyEntries');
        $this->assertEquals(1, $entries->total());
        $this->assertEquals('grant', $entries->first()->action);
    }

    public function test_history_tab_filters_by_action(): void
    {
        $actor = $this->makeUser('f-actor');
        $target = $this->makeUser('f-target');
        $group = $this->makeGroup('filter-grp');
        $svc = app(PermissionService::class);

        $svc->grantDelegation($target, 'computer.view', $group, $actor);
        $svc->revokeDelegation($target, 'computer.view', $group, $actor);
        $svc->negateDelegation($target, 'computer.view', $group, $actor);

        $this->actingAs($actor);

        $component = Livewire::test($this->pageComponent())
            ->set('historyActionFilter', 'revoke');

        $entries = $component->get('historyEntries');
        $this->assertEquals(1, $entries->total());
        $this->assertEquals('revoke', $entries->first()->action);
    }

    public function test_history_tab_filters_by_target(): void
    {
        $actor = $this->makeUser('t-actor');
        $t1 = $this->makeUser('alpha-target');
        $t2 = $this->makeUser('beta-target');
        $group = $this->makeGroup('tgrp');
        $svc = app(PermissionService::class);

        $svc->grantDelegation($t1, 'computer.view', $group, $actor);
        $svc->grantDelegation($t2, 'computer.view', $group, $actor);

        $this->actingAs($actor);

        $component = Livewire::test($this->pageComponent())
            ->set('historyTargetFilter', 'alpha');

        $entries = $component->get('historyEntries');
        $this->assertEquals(1, $entries->total());
        $this->assertEquals($t1->id, $entries->first()->target_user_id);
    }

    public function test_reset_history_filters_clears_all(): void
    {
        $actor = $this->makeUser('r-actor');
        $this->actingAs($actor);

        Livewire::test($this->pageComponent())
            ->set('historyActionFilter', 'grant')
            ->set('historyTargetFilter', 'foo')
            ->set('historyFromFilter', '2026-01-01')
            ->set('historyToFilter', '2026-12-31')
            ->call('resetHistoryFilters')
            ->assertSet('historyActionFilter', '')
            ->assertSet('historyTargetFilter', '')
            ->assertSet('historyFromFilter', '')
            ->assertSet('historyToFilter', '');
    }

    // ========================================================================
    // Révocation (AC8)
    // ========================================================================

    public function test_revoke_delegation_button_removes_delegation_and_creates_audit(): void
    {
        $actor = $this->grantAdminPermission($this->makeUser('rev-actor'));
        $target = $this->makeUser('rev-target');
        $group = $this->makeGroup('rev-grp');

        $delegation = app(PermissionService::class)->grantDelegation(
            $target,
            'computer.view',
            $group,
            $actor
        );
        $this->assertDatabaseHas('delegations', ['id' => $delegation->id]);

        $this->actingAs($actor);

        Livewire::test($this->pageComponent())
            ->call('revokeDelegation', $delegation->id);

        $this->assertDatabaseMissing('delegations', ['id' => $delegation->id]);

        $this->assertEquals(
            1,
            DelegationHistory::where('target_user_id', $target->id)
                ->where('action', DelegationHistory::ACTION_REVOKE)
                ->count()
        );
    }

    /**
     * Story 7.1 — Review #5 / #C : sans `user.assign.right`, revokeDelegation
     * doit lever 403 (HttpException via abort_unless).
     *
     * Note : Livewire absorbe HttpException dans les tests — on utilise
     * `assertStatus(403)` sur la réponse rendue + assertion DB pour vérifier
     * la non-régression.
     */
    public function test_non_admin_cannot_revoke_delegation_via_livewire(): void
    {
        $actor = $this->makeUser('rev-actor-ok');
        $actor->givePermissionTo('user.assign.right');
        $target = $this->makeUser('rev-target-ok');
        $group = $this->makeGroup('rev-grp-ok');

        $delegation = app(PermissionService::class)->grantDelegation(
            $target,
            'computer.view',
            $group,
            $actor
        );

        $nonAdmin = $this->makeUser('rev-nonadmin');
        $this->actingAs($nonAdmin);

        Livewire::test($this->pageComponent())
            ->call('revokeDelegation', $delegation->id)
            ->assertStatus(403);

        // La délégation doit toujours exister.
        $this->assertDatabaseHas('delegations', ['id' => $delegation->id]);
    }

    /**
     * Story 7.1 — Review #8 : searchUser doit fonctionner sur SQLite (LIKE)
     * ET Postgres (ILIKE). Le pattern conditionnel sur `DB::getDriverName()`
     * doit pouvoir remonter le user par login ou fullname.
     */
    public function test_search_user_works_on_sqlite_and_pgsql(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('search-admin'));
        $found = $this->makeUser('search-found');
        $this->actingAs($admin);

        $component = Livewire::test($this->pageComponent())
            ->set('userSearch', 'search-found')
            ->call('searchUser');

        $users = $component->get('foundUsers');
        $this->assertIsArray($users);
        $this->assertNotEmpty($users);
        $this->assertEquals('search-found', $users[0]['login']);
    }

    public function test_user_lookup_loads_delegation_history_for_selected_user(): void
    {
        $actor = $this->makeUser('lookup-actor');
        $target = $this->makeUser('lookup-target');
        $group = $this->makeGroup('lookup-grp');

        app(PermissionService::class)->grantDelegation($target, 'computer.view', $group, $actor);

        $this->actingAs($actor);

        $component = Livewire::test($this->pageComponent())
            ->call('selectUser', 'lookup-target');

        $details = $component->get('selectedUserDetails');
        $this->assertNotNull($details);
        $this->assertEquals('lookup-target', $details['login']);
        $this->assertIsArray($details['history']);
        $this->assertCount(1, $details['history']);
        $this->assertEquals('grant', $details['history'][0]['action']);
    }
}
