<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\SyncGpoJob;
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
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests Feature Livewire de la modale `delegation-modal` (Story 7.1.bis).
 *
 * UX état→action mono-permission extraite de l'ancien tab Délégations du drawer.
 * Couvre :
 *  - applyDelegationActions : Auto selon l'état courant (grant / revoke / negate / lift_negative)
 *  - forcedAction : override de la suggestion
 *  - multi-user hétérogène
 *  - dispatch GPO pour computer.elevate
 *  - ensureEloquentUser : création / refus si AD absent
 *  - guard `user.assign.right`
 */
class DelegationModalTest extends TestCase
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
                $table->primary(['permission_id', 'model_id', 'model_type'], 'mhp_dmod');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'mhr_dmod');
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
                    'delegations_dmod_unique'
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

        foreach (['computer.view', 'computer.control', 'computer.elevate', 'computer.install', 'user.assign.right'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    private function grantAdminPermission(User $admin): User
    {
        $admin->givePermissionTo('user.assign.right');
        return $admin;
    }


    private function modalComponent(): string
    {
        return 'pages::users._partials.delegation-modal';
    }

    private function makeUser(string $login): User
    {
        return User::create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
    }

    private function makeGroup(string $name = 'dmod-grp'): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => "{$name}-" . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    // ========================================================================
    // applyDelegationActions — Auto selon l'état courant
    // ========================================================================

    public function test_auto_grants_when_user_has_no_access(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-admin'));
        $target = $this->makeUser('dmod-target');
        $group = $this->makeGroup();
        $this->actingAs($admin);

        Livewire::test($this->modalComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.view')
            ->call('applyDelegationActions');

        $this->assertDatabaseHas('delegations', [
            'user_id' => $target->id,
            'workstation_group_id' => $group->id,
            'is_negative' => false,
        ]);
        $this->assertEquals(
            1,
            DelegationHistory::where('target_user_id', $target->id)
                ->where('action', 'grant')->count()
        );
    }

    public function test_auto_revokes_when_user_has_positive_delegation(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-admin-rm'));
        $target = $this->makeUser('dmod-target-rm');
        $group = $this->makeGroup();
        $this->actingAs($admin);

        app(PermissionService::class)->grantDelegation($target, 'computer.view', $group, $admin);

        Livewire::test($this->modalComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.view')
            ->call('applyDelegationActions');

        $this->assertDatabaseMissing('delegations', [
            'user_id' => $target->id,
            'is_negative' => false,
        ]);
        $this->assertEquals(
            1,
            DelegationHistory::where('target_user_id', $target->id)
                ->where('action', 'revoke')
                ->where('is_negative', false)
                ->count()
        );
    }

    public function test_auto_negates_when_user_has_global_permission(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-admin-neg'));
        $target = $this->makeUser('dmod-target-neg');
        $target->givePermissionTo('computer.view');
        $group = $this->makeGroup();
        $this->actingAs($admin);

        Livewire::test($this->modalComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.view')
            ->call('applyDelegationActions');

        $this->assertDatabaseHas('delegations', [
            'user_id' => $target->id,
            'workstation_group_id' => $group->id,
            'is_negative' => true,
        ]);
        $this->assertEquals(
            1,
            DelegationHistory::where('target_user_id', $target->id)
                ->where('action', 'negate')
                ->where('is_negative', true)
                ->count()
        );
    }

    public function test_auto_lifts_exclusion_when_user_has_negative(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-admin-lift'));
        $target = $this->makeUser('dmod-target-lift');
        $group = $this->makeGroup();
        app(PermissionService::class)->negateDelegation($target, 'computer.view', $group, $admin);
        $this->actingAs($admin);

        Livewire::test($this->modalComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.view')
            ->call('applyDelegationActions');

        $this->assertDatabaseMissing('delegations', [
            'user_id' => $target->id,
            'is_negative' => true,
        ]);
        $this->assertEquals(
            1,
            DelegationHistory::where('target_user_id', $target->id)
                ->where('action', 'revoke')
                ->where('is_negative', true)
                ->count()
        );
    }

    public function test_auto_multi_user_applies_correct_action_per_state(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-admin-multi'));
        $group = $this->makeGroup();
        $this->actingAs($admin);

        $svc = app(PermissionService::class);
        $userNone = $this->makeUser('u-none');
        $userPositive = $this->makeUser('u-positive');
        $svc->grantDelegation($userPositive, 'computer.view', $group);
        $userNegative = $this->makeUser('u-negative');
        $svc->negateDelegation($userNegative, 'computer.view', $group);
        $userGlobal = $this->makeUser('u-global');
        $userGlobal->givePermissionTo('computer.view');

        Livewire::test($this->modalComponent())
            ->call('open', ['u-none', 'u-positive', 'u-negative', 'u-global'])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.view')
            ->call('applyDelegationActions');

        $this->assertDatabaseHas('delegations', ['user_id' => $userNone->id, 'is_negative' => false]);
        $this->assertDatabaseMissing('delegations', ['user_id' => $userPositive->id, 'is_negative' => false]);
        $this->assertDatabaseMissing('delegations', ['user_id' => $userNegative->id, 'is_negative' => true]);
        $this->assertDatabaseHas('delegations', ['user_id' => $userGlobal->id, 'is_negative' => true]);
    }

    public function test_unchecking_has_expiration_clears_the_date(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-admin-togexp'));
        $this->actingAs($admin);

        Livewire::test($this->modalComponent())
            ->set('hasExpiration', true)
            ->set('delegationExpiresAt', '2027-01-01T10:00')
            ->set('hasExpiration', false)
            ->assertSet('delegationExpiresAt', null);
    }

    public function test_negate_with_expiration_persists_expires_at(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-admin-negexp'));
        $target = $this->makeUser('dmod-target-negexp');
        $target->givePermissionTo('computer.view');
        $group = $this->makeGroup();
        $this->actingAs($admin);

        $expiresAtIso = (new \DateTimeImmutable('+3 hours'))->format('Y-m-d\TH:i');

        Livewire::test($this->modalComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.view')
            ->set('delegationExpiresAt', $expiresAtIso)
            ->call('applyDelegationActions');

        $delegation = Delegation::where('user_id', $target->id)
            ->where('is_negative', true)
            ->first();
        $this->assertNotNull($delegation, 'Exclusion doit exister.');
        $this->assertNotNull($delegation->expires_at, 'expires_at doit être persisté sur la négative.');
    }

    public function test_forced_action_overrides_auto_suggestion(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-admin-force'));
        $target = $this->makeUser('dmod-target-force');
        $group = $this->makeGroup();
        app(PermissionService::class)->grantDelegation($target, 'computer.view', $group, $admin);
        $this->actingAs($admin);

        Livewire::test($this->modalComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.view')
            ->set('forcedAction', 'negate')
            ->call('applyDelegationActions');

        $this->assertDatabaseHas('delegations', ['user_id' => $target->id, 'is_negative' => false]);
        $this->assertDatabaseHas('delegations', ['user_id' => $target->id, 'is_negative' => true]);
    }

    public function test_auto_with_computer_elevate_dispatches_gpo_job(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-admin-gpo'));
        $target = $this->makeUser('dmod-target-gpo');
        $group = $this->makeGroup();
        $this->actingAs($admin);

        Livewire::test($this->modalComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.elevate')
            ->call('applyDelegationActions');

        Queue::assertPushed(SyncGpoJob::class, 1);
    }

    /**
     * Story 49.2 (AC7) — INVERSION ASSUMÉE d'un comportement.
     *
     * Ce test s'appelait `test_auto_for_nonexistent_user_creates_minimal_eloquent_user`
     * et vérifiait qu'un login absent de SQL mais présent dans l'AD provoquait
     * la création d'une ligne `users` minimale (`role='autre'`, `is_active=true`
     * en dur). Ce fallback est supprimé : Postgres est la vérité pour
     * l'existence d'un compte côté SE5, et une ligne fabriquée dont les valeurs
     * ne sont le miroir de rien est précisément ce que l'Epic 49 élimine.
     *
     * Nouveau contrat : aucune ligne créée, aucune délégation, l'utilisateur est
     * simplement compté en erreur (l'admin est invité à attendre la sync).
     */
    public function test_auto_for_user_absent_from_sql_creates_nothing(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-admin-newu'));
        $group = $this->makeGroup();
        $this->actingAs($admin);

        $this->assertDatabaseMissing('users', ['login' => 'newly-created-dmod']);

        Livewire::test($this->modalComponent())
            ->call('open', ['newly-created-dmod'])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.view')
            ->call('applyDelegationActions');

        $this->assertDatabaseMissing('users', ['login' => 'newly-created-dmod']);
        $this->assertEquals(0, Delegation::count());
    }

    /** Story 49.2 — « inconnu » se lit désormais en Postgres, plus dans l'annuaire. */
    public function test_auto_rejects_login_unknown_to_postgres(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-admin-unk'));
        $group = $this->makeGroup();
        $this->actingAs($admin);

        Livewire::test($this->modalComponent())
            ->call('open', ['ghost-login-dmod'])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.view')
            ->call('applyDelegationActions');

        $this->assertDatabaseMissing('users', ['login' => 'ghost-login-dmod']);
        $this->assertEquals(0, Delegation::count());
    }

    public function test_non_admin_cannot_apply_delegation_actions(): void
    {
        $nonAdmin = $this->makeUser('dmod-non-admin');
        $admin = $this->grantAdminPermission($this->makeUser('dmod-admin-step1'));
        $target = $this->makeUser('dmod-target-step1');
        $group = $this->makeGroup();

        $this->actingAs($admin);
        $test = Livewire::test($this->modalComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.view');

        $this->actingAs($nonAdmin);
        $test->call('applyDelegationActions')->assertStatus(403);

        $this->assertEquals(0, Delegation::count());
    }

    // ========================================================================
    // userSummaries
    // ========================================================================

    public function test_user_summaries_reflects_current_state_per_user(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-sum-admin'));
        $group = $this->makeGroup();
        $svc = app(PermissionService::class);
        $userPositive = $this->makeUser('sum-dmod-pos');
        $svc->grantDelegation($userPositive, 'computer.view', $group);
        $userNone = $this->makeUser('sum-dmod-none');
        $this->actingAs($admin);

        $test = Livewire::test($this->modalComponent())
            ->call('open', ['sum-dmod-pos', 'sum-dmod-none'])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.view');

        $summaries = $test->instance()->userSummaries;

        $this->assertEquals('delegation_positive', $summaries['sum-dmod-pos']['source']);
        $this->assertEquals('revoke', $summaries['sum-dmod-pos']['action_suggested']);
        $this->assertEquals('none', $summaries['sum-dmod-none']['source']);
        $this->assertEquals('grant', $summaries['sum-dmod-none']['action_suggested']);
    }

    // ========================================================================
    // Validations
    // ========================================================================

    public function test_apply_without_users_does_nothing(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-empty'));
        $group = $this->makeGroup();
        $this->actingAs($admin);

        Livewire::test($this->modalComponent())
            ->set('selectedUsers', [])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermission', 'computer.view')
            ->call('applyDelegationActions');

        $this->assertEquals(0, Delegation::count());
    }

    public function test_apply_without_permission_does_nothing(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('dmod-noperm'));
        $target = $this->makeUser('dmod-target-noperm');
        $group = $this->makeGroup();
        $this->actingAs($admin);

        Livewire::test($this->modalComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->call('applyDelegationActions');

        $this->assertEquals(0, Delegation::count());
    }

    public static function tearDownAfterClass(): void
    {
        Mockery::close();
        parent::tearDownAfterClass();
    }
}
