<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Jobs\SyncGpoJob;
use App\Models\Delegation;
use App\Models\DelegationHistory;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Services\PermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests unitaires du PermissionService (Story 7.1).
 *
 * Couvre grant / revoke / negate / canOnWorkstationGroup / idempotence /
 * expiration / GPO dispatch / résolution user global via Spatie.
 *
 * Schéma créé ad hoc en SQLite via `createTablesIfNeeded()` (pattern story 4.4).
 */
class PermissionServiceTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;
    private PermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createTablesIfNeeded();
        Queue::fake();

        $this->service = app(PermissionService::class);
    }

    protected function tearDown(): void
    {
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

        // Tables Spatie minimales
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
                    'delegations_unique'
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

        // Seed de base
        $this->seedPermissions();
    }

    private function seedPermissions(): void
    {
        $perms = [
            'computer.view',
            'computer.control',
            'computer.elevate',
            'computer.install',
            'wpkg.assign',
        ];
        foreach ($perms as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function makeUser(string $login): User
    {
        return User::create([
            'login' => $login,
            'role' => 'prof',
            'is_active' => true,
        ]);
    }

    private function makeGroup(string $name = 'salle-A'): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => $name . '-' . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    // ========================================================================
    // GRANT
    // ========================================================================

    public function test_grant_delegation_persists_and_is_idempotent(): void
    {
        $user = $this->makeUser('prof1');
        $group = $this->makeGroup();

        $d1 = $this->service->grantDelegation($user, 'computer.view', $group);
        $d2 = $this->service->grantDelegation($user, 'computer.view', $group);

        $this->assertEquals($d1->id, $d2->id);
        $this->assertEquals(1, Delegation::where('user_id', $user->id)
            ->where('workstation_group_id', $group->id)
            ->count());
    }

    public function test_grant_delegation_creates_history_entry_only_on_first_call(): void
    {
        $user = $this->makeUser('prof2');
        $group = $this->makeGroup();

        $this->service->grantDelegation($user, 'computer.view', $group);
        $this->service->grantDelegation($user, 'computer.view', $group);

        // Idempotence : un seul grant dans l'historique.
        $this->assertEquals(1, DelegationHistory::where('target_user_id', $user->id)
            ->where('action', DelegationHistory::ACTION_GRANT)
            ->count());
    }

    public function test_grant_delegation_dispatches_gpo_sync_for_computer_elevate_only(): void
    {
        $user = $this->makeUser('prof3');
        $group = $this->makeGroup();

        $this->service->grantDelegation($user, 'computer.view', $group);
        Queue::assertNotPushed(SyncGpoJob::class);

        $this->service->grantDelegation($user, 'computer.elevate', $group);
        Queue::assertPushed(SyncGpoJob::class, 1);
    }

    // ========================================================================
    // REVOKE
    // ========================================================================

    public function test_revoke_delegation_deletes_row_and_creates_history(): void
    {
        $user = $this->makeUser('prof-rev');
        $group = $this->makeGroup();

        $this->service->grantDelegation($user, 'computer.view', $group);
        $ok = $this->service->revokeDelegation($user, 'computer.view', $group);

        $this->assertTrue($ok);
        $this->assertEquals(0, Delegation::where('user_id', $user->id)
            ->where('is_negative', false)
            ->count());

        $this->assertEquals(1, DelegationHistory::where('target_user_id', $user->id)
            ->where('action', DelegationHistory::ACTION_REVOKE)
            ->count());
    }

    public function test_revoke_without_existing_delegation_does_not_log(): void
    {
        $user = $this->makeUser('prof-rev2');
        $group = $this->makeGroup();

        $ok = $this->service->revokeDelegation($user, 'computer.view', $group);

        $this->assertFalse($ok);
        $this->assertEquals(0, DelegationHistory::where('target_user_id', $user->id)->count());
    }

    public function test_revoke_delegation_accepts_explicit_actor(): void
    {
        $target = $this->makeUser('target-user');
        $actor = $this->makeUser('admin-user');
        $group = $this->makeGroup();

        $this->service->grantDelegation($target, 'computer.view', $group);
        $this->service->revokeDelegation($target, 'computer.view', $group, $actor);

        $entry = DelegationHistory::where('target_user_id', $target->id)
            ->where('action', DelegationHistory::ACTION_REVOKE)
            ->first();
        $this->assertNotNull($entry);
        $this->assertEquals($actor->id, $entry->actor_user_id);
    }

    // ========================================================================
    // NEGATE
    // ========================================================================

    public function test_negate_delegation_creates_negative_flag(): void
    {
        $user = $this->makeUser('prof-neg');
        $group = $this->makeGroup();

        $deleg = $this->service->negateDelegation($user, 'computer.view', $group);

        $this->assertTrue($deleg->is_negative);
        $this->assertEquals(1, Delegation::where('user_id', $user->id)
            ->where('is_negative', true)
            ->count());

        $this->assertEquals(1, DelegationHistory::where('target_user_id', $user->id)
            ->where('action', DelegationHistory::ACTION_NEGATE)
            ->where('is_negative', true)
            ->count());
    }

    public function test_negate_delegation_accepts_explicit_actor(): void
    {
        $target = $this->makeUser('target-neg');
        $actor = $this->makeUser('admin-neg');
        $group = $this->makeGroup();

        $this->service->negateDelegation($target, 'computer.view', $group, $actor);

        $entry = DelegationHistory::where('target_user_id', $target->id)
            ->where('action', DelegationHistory::ACTION_NEGATE)
            ->first();
        $this->assertNotNull($entry);
        $this->assertEquals($actor->id, $entry->actor_user_id);
    }

    // ========================================================================
    // canOnWorkstationGroup
    // ========================================================================

    public function test_can_on_workstation_group_with_global_permission(): void
    {
        $user = $this->makeUser('admin-global');
        $group = $this->makeGroup();

        $user->givePermissionTo('computer.view');

        $this->assertTrue(
            $this->service->canOnWorkstationGroup($user->fresh(), 'computer.view', $group)
        );
    }

    public function test_can_on_workstation_group_with_positive_delegation(): void
    {
        $user = $this->makeUser('prof-del');
        $group = $this->makeGroup();

        $this->service->grantDelegation($user, 'computer.view', $group);

        $this->assertTrue(
            $this->service->canOnWorkstationGroup($user, 'computer.view', $group)
        );
    }

    public function test_can_on_workstation_group_without_any_grant_is_false(): void
    {
        $user = $this->makeUser('prof-no');
        $group = $this->makeGroup();

        $this->assertFalse(
            $this->service->canOnWorkstationGroup($user, 'computer.view', $group)
        );
    }

    public function test_can_on_workstation_group_blocked_by_negative_delegation(): void
    {
        $user = $this->makeUser('prof-mixed');
        $group = $this->makeGroup();

        $this->service->grantDelegation($user, 'computer.view', $group);
        $this->service->negateDelegation($user, 'computer.view', $group);

        $this->assertFalse(
            $this->service->canOnWorkstationGroup($user, 'computer.view', $group)
        );
    }

    public function test_can_on_workstation_group_with_expired_delegation(): void
    {
        $user = $this->makeUser('prof-exp');
        $group = $this->makeGroup();

        $this->service->grantDelegation(
            $user,
            'computer.view',
            $group,
            null,
            now()->subHour(),
        );

        $this->assertFalse(
            $this->service->canOnWorkstationGroup($user, 'computer.view', $group)
        );
    }

    /**
     * Story 7.1 — Review #3 : une délégation NÉGATIVE expirée ne doit plus
     * bloquer l'accès. On simule via insertion directe avec `expires_at` passé
     * (car `negateDelegation` ne pose pas d'expires_at aujourd'hui, mais la
     * logique `canOnWorkstationGroup` doit rester cohérente).
     */
    public function test_expired_negative_delegation_no_longer_blocks_access(): void
    {
        $user = $this->makeUser('prof-neg-exp');
        $group = $this->makeGroup();

        // Positive active
        $this->service->grantDelegation($user, 'computer.view', $group);

        // Négative ancienne → on pose artificiellement expires_at dans le passé
        // via update direct (negateDelegation ne pose pas d'expiration).
        $negative = $this->service->negateDelegation($user, 'computer.view', $group);
        $negative->expires_at = now()->subHour();
        $negative->save();

        $this->assertTrue(
            $this->service->canOnWorkstationGroup($user->fresh(), 'computer.view', $group),
            'Une négative expirée ne doit pas bloquer l\'accès.'
        );
    }

    // ========================================================================
    // getAuthorizedWorkstationGroups
    // ========================================================================

    public function test_get_authorized_workstation_groups_filters_correctly(): void
    {
        $user = $this->makeUser('prof-auth');
        $groupA = $this->makeGroup('A');
        $groupB = $this->makeGroup('B');
        $groupC = $this->makeGroup('C');

        // Positive sur A + B, négative sur B (B doit être filtré), rien sur C.
        $this->service->grantDelegation($user, 'computer.view', $groupA);
        $this->service->grantDelegation($user, 'computer.view', $groupB);
        $this->service->negateDelegation($user, 'computer.view', $groupB);

        $allowed = $this->service->getAuthorizedWorkstationGroups($user, 'computer.view');
        $allowedIds = $allowed->pluck('id')->all();

        $this->assertContains($groupA->id, $allowedIds);
        $this->assertNotContains($groupB->id, $allowedIds, 'Négative doit filtrer le group');
        $this->assertNotContains($groupC->id, $allowedIds);
    }

    public function test_get_authorized_workstation_groups_with_global_returns_all(): void
    {
        $user = $this->makeUser('admin-auth');
        $groupA = $this->makeGroup('A');
        $groupB = $this->makeGroup('B');

        $user->givePermissionTo('computer.view');

        $allowed = $this->service->getAuthorizedWorkstationGroups($user->fresh(), 'computer.view');
        $allowedIds = $allowed->pluck('id')->all();

        $this->assertContains($groupA->id, $allowedIds);
        $this->assertContains($groupB->id, $allowedIds);
    }
}
