<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\DelegationHistory;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Services\DelegationHistoryService;
use App\Services\PermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests Feature de l'historique des délégations (Story 7.1 — AC5, AC10).
 *
 * Invariants testés :
 *  - chaque `grant` / `revoke` / `negate` écrit une ligne d'audit complète
 *  - la table est append-only : un `$history->update()` throw
 *  - l'acteur est résolu via `auth()->user()` si non fourni explicitement
 */
class DelegationHistoryTest extends TestCase
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
                $table->primary(['permission_id', 'model_id', 'model_type'], 'mhp_hist');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'mhr_hist');
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
                    'delegations_hist_unique'
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
    }

    private function makeUser(string $login): User
    {
        return User::create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
    }

    private function makeGroup(): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => 'salle-hist-' . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    // ========================================================================
    // Champs écrits
    // ========================================================================

    public function test_grant_creates_history_entry_with_all_fields(): void
    {
        $actor = $this->makeUser('actor-hist');
        $target = $this->makeUser('target-hist');
        $group = $this->makeGroup();

        app(PermissionService::class)->grantDelegation($target, 'computer.view', $group, $actor);

        $entry = DelegationHistory::where('target_user_id', $target->id)
            ->where('action', DelegationHistory::ACTION_GRANT)
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals($actor->id, $entry->actor_user_id);
        $this->assertEquals($target->id, $entry->target_user_id);
        $this->assertEquals($group->id, $entry->workstation_group_id);
        $this->assertEquals('computer.view', $entry->permission_name);
        $this->assertFalse($entry->is_negative);
        $this->assertNotNull($entry->created_at);
    }

    public function test_revoke_creates_history_entry(): void
    {
        $actor = $this->makeUser('actor-rev');
        $target = $this->makeUser('target-rev');
        $group = $this->makeGroup();

        $service = app(PermissionService::class);
        $service->grantDelegation($target, 'computer.view', $group, $actor);
        $service->revokeDelegation($target, 'computer.view', $group, $actor);

        $entry = DelegationHistory::where('target_user_id', $target->id)
            ->where('action', DelegationHistory::ACTION_REVOKE)
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals($actor->id, $entry->actor_user_id);
        $this->assertEquals('computer.view', $entry->permission_name);
    }

    public function test_negate_creates_history_entry_with_is_negative_true(): void
    {
        $actor = $this->makeUser('actor-neg');
        $target = $this->makeUser('target-neg');
        $group = $this->makeGroup();

        app(PermissionService::class)->negateDelegation($target, 'computer.view', $group, $actor);

        $entry = DelegationHistory::where('target_user_id', $target->id)
            ->where('action', DelegationHistory::ACTION_NEGATE)
            ->first();

        $this->assertNotNull($entry);
        $this->assertTrue($entry->is_negative);
    }

    // ========================================================================
    // Append-only (AC5)
    // ========================================================================

    public function test_history_is_append_only_via_save(): void
    {
        $actor = $this->makeUser('actor-ao');
        $target = $this->makeUser('target-ao');
        $group = $this->makeGroup();

        app(PermissionService::class)->grantDelegation($target, 'computer.view', $group, $actor);

        $entry = DelegationHistory::first();
        $this->assertNotNull($entry);

        $this->expectException(LogicException::class);
        $entry->action = 'altered';
        $entry->save();
    }

    public function test_history_is_append_only_via_update(): void
    {
        $actor = $this->makeUser('actor-ao2');
        $target = $this->makeUser('target-ao2');
        $group = $this->makeGroup();

        app(PermissionService::class)->grantDelegation($target, 'computer.view', $group, $actor);

        $entry = DelegationHistory::first();
        $this->assertNotNull($entry);

        $this->expectException(LogicException::class);
        $entry->update(['action' => 'hack']);
    }

    // ========================================================================
    // Résolution acteur depuis auth()
    // ========================================================================

    public function test_actor_is_resolved_from_auth_when_not_explicit(): void
    {
        $actor = $this->makeUser('actor-auth');
        $target = $this->makeUser('target-auth');
        $group = $this->makeGroup();

        // Simule un admin connecté via Laravel auth.
        $this->actingAs($actor);

        // Appelle grantDelegation SANS préciser $grantedBy.
        app(PermissionService::class)->grantDelegation($target, 'computer.view', $group);

        $entry = DelegationHistory::where('target_user_id', $target->id)
            ->where('action', DelegationHistory::ACTION_GRANT)
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals(
            $actor->id,
            $entry->actor_user_id,
            'L\'acteur doit être résolu depuis auth()->user() si non fourni explicitement'
        );
    }

    public function test_direct_history_service_log_accepts_null_actor(): void
    {
        $target = $this->makeUser('target-noactor');
        $group = $this->makeGroup();

        $entry = app(DelegationHistoryService::class)->log(
            action: DelegationHistory::ACTION_GRANT,
            actor: null,
            target: $target,
            group: $group,
            permissionName: 'computer.view',
        );

        $this->assertNotNull($entry);
        $this->assertNull($entry->actor_user_id);
    }
}
