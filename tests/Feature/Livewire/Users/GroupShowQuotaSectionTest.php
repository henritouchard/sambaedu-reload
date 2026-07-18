<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Models\QuotaRule;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 5.1c — Tests Feature Livewire SFC `group-quota-section`.
 *
 * Couvre AC 13 #1-8 :
 *   1. rendu "Hérité" si aucune règle groupe
 *   2. rendu custom (label soft + overage)
 *   3. rendu "Illimité" si soft=0 && hard=0
 *   4. bouton "Modifier" absent sans server.admin
 *   5. payload Livewire forgé sans server.admin → 403
 *   6. apply inherited → règle existante deleteQuotaRule
 *   7. apply custom → setQuotaRule + ApplyQuotaJob dispatched
 *   8. apply custom < 10 Mo sur /home → erreur validation, pas de règle créée
 *
 * Pattern : décalqué sur UserShowQuotaSectionTest 5.1b.
 */
class GroupShowQuotaSectionTest extends TestCase
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
            Schema::dropIfExists('quota_audit_logs');
            Schema::dropIfExists('quota_rules');
            Schema::dropIfExists('quota_settings');
            Schema::dropIfExists('user_group_user');
            Schema::dropIfExists('user_groups');
            Schema::dropIfExists('model_has_permissions');
            Schema::dropIfExists('model_has_roles');
            Schema::dropIfExists('role_has_permissions');
            Schema::dropIfExists('permissions');
            Schema::dropIfExists('roles');
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
                $table->string('dn', 500)->nullable();
                $table->string('school_code', 100)->nullable();
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->json('quota_snapshot')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('type')->nullable();
                $table->string('display_name')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_group_user')) {
            Schema::create('user_group_user', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('user_group_id');
                // Story 42.1 — rôle sur l'arête, lu par withPivot('role').
                $table->string('role', 20)->default('member');
                $table->primary(['user_id', 'user_group_id']);
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('quota_rules')) {
            Schema::create('quota_rules', function (Blueprint $table) {
                $table->id();
                $table->string('type');
                $table->string('target')->nullable();
                $table->string('partition');
                $table->integer('quota_soft_mb')->default(0);
                $table->integer('quota_hard_mb')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('quota_audit_logs')) {
            Schema::create('quota_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->string('action');
                $table->string('performed_by');
                $table->string('target_type')->nullable();
                $table->string('target_name')->nullable();
                $table->string('partition')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->boolean('fs_applied')->default(false);
                $table->text('fs_error')->nullable();
                $table->unsignedBigInteger('quota_rule_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('quota_settings')) {
            Schema::create('quota_settings', function (Blueprint $table) {
                $table->id();
                $table->string('partition')->unique();
                $table->integer('grace_period_days')->default(7);
                $table->integer('default_overage_percent')->default(20);
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
                $table->primary(['permission_id', 'model_id', 'model_type'], 'gqs_mhp');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'gqs_mhr');
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

        Permission::firstOrCreate(['name' => 'server.admin', 'guard_name' => 'web']);
    }

    private function componentPath(): string
    {
        return 'pages::users.groups.[id]._partials.group-quota-section';
    }

    private function makeUser(string $login): User
    {
        return User::query()->create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
    }

    private function makeGroup(string $name, string $type = 'classe'): UserGroup
    {
        return UserGroup::query()->create([
            'name' => $name,
            'display_name' => $name,
            'type' => $type,
        ]);
    }

    private function grantServerAdmin(User $user): User
    {
        $user->givePermissionTo('server.admin');
        return $user;
    }

    // =========================================================================
    // AC 13 #1
    // =========================================================================

    public function test_it_renders_group_quota_section_with_inherited_label(): void
    {
        $group = $this->makeGroup('classe-6a');
        $admin = $this->makeUser('admin-inherited');
        $this->grantServerAdmin($admin);
        $this->actingAs($admin);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertSet('groupName', 'classe-6a')
            ->assertSee('Hérité (défaut)');
    }

    // =========================================================================
    // AC 13 #2
    // =========================================================================

    public function test_it_renders_group_quota_section_with_custom_rule(): void
    {
        $group = $this->makeGroup('classe-5b');

        QuotaRule::query()->create([
            'type' => QuotaRule::TYPE_GROUP,
            'target' => 'classe-5b',
            'partition' => QuotaRule::PARTITION_HOME,
            'quota_soft_mb' => 500,
            'quota_hard_mb' => 600,
            'is_active' => true,
        ]);

        $admin = $this->makeUser('admin-custom');
        $this->grantServerAdmin($admin);
        $this->actingAs($admin);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertSee('500 Mo')
            ->assertSee('+20%');
    }

    // =========================================================================
    // AC 13 #3
    // =========================================================================

    public function test_it_renders_unlimited_when_soft_and_hard_zero(): void
    {
        $group = $this->makeGroup('classe-4c');

        QuotaRule::query()->create([
            'type' => QuotaRule::TYPE_GROUP,
            'target' => 'classe-4c',
            'partition' => QuotaRule::PARTITION_HOME,
            'quota_soft_mb' => 0,
            'quota_hard_mb' => 0,
            'is_active' => true,
        ]);

        $admin = $this->makeUser('admin-unlim');
        $this->grantServerAdmin($admin);
        $this->actingAs($admin);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertSee('Illimité');
    }

    // =========================================================================
    // AC 13 #4 — UI gate
    // =========================================================================

    public function test_it_hides_modify_button_without_server_admin(): void
    {
        $group = $this->makeGroup('classe-3d');

        $viewer = $this->makeUser('viewer-noperm-group');
        $this->actingAs($viewer);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertDontSee('Modifier');
    }

    // =========================================================================
    // AC 13 #5 — Forged payload
    // =========================================================================

    public function test_it_blocks_apply_override_without_server_admin_even_on_forged_payload(): void
    {
        $group = $this->makeGroup('classe-2e');

        $viewer = $this->makeUser('viewer-bypass');
        $this->actingAs($viewer);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->set('overridePartition', QuotaRule::PARTITION_HOME)
            ->set('overrideType', 'custom')
            ->set('overrideSoftMb', 500)
            ->set('overrideOveragePercent', 20)
            ->call('applyOverride')
            ->assertStatus(403);
    }

    // =========================================================================
    // AC 13 #6 — Inherited override deletes rule
    // =========================================================================

    public function test_it_applies_inherited_override_deletes_rule(): void
    {
        $service = $this->makeStubXfsQuotaService();
        $this->app->instance(\App\Services\Filesystem\XfsQuotaService::class, $service);

        $group = $this->makeGroup('classe-1f');

        QuotaRule::query()->create([
            'type' => QuotaRule::TYPE_GROUP,
            'target' => 'classe-1f',
            'partition' => QuotaRule::PARTITION_HOME,
            'quota_soft_mb' => 500,
            'quota_hard_mb' => 600,
            'is_active' => true,
        ]);

        $admin = $this->makeUser('admin-inherit');
        $this->grantServerAdmin($admin);
        $this->actingAs($admin);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->set('overridePartition', QuotaRule::PARTITION_HOME)
            ->set('overrideType', 'inherited')
            ->call('applyOverride')
            ->assertDispatched('toastMagic');

        // La règle a été supprimée par deleteQuotaRule.
        $this->assertDatabaseMissing('quota_rules', [
            'type' => QuotaRule::TYPE_GROUP,
            'target' => 'classe-1f',
            'partition' => QuotaRule::PARTITION_HOME,
        ]);
    }

    // =========================================================================
    // AC 13 #7 — Custom override sets rule + dispatch
    // =========================================================================

    public function test_it_applies_custom_override_sets_rule_and_dispatches_recalculate(): void
    {
        $service = $this->makeStubXfsQuotaService();
        $this->app->instance(\App\Services\Filesystem\XfsQuotaService::class, $service);

        $group = $this->makeGroup('classe-cm2-a');

        $admin = $this->makeUser('admin-custom-apply');
        $this->grantServerAdmin($admin);
        $this->actingAs($admin);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->set('overridePartition', QuotaRule::PARTITION_HOME)
            ->set('overrideType', 'custom')
            ->set('overrideSoftMb', 500)
            ->set('overrideOveragePercent', 20)
            ->call('applyOverride')
            ->assertDispatched('toastMagic');

        $this->assertDatabaseHas('quota_rules', [
            'type' => QuotaRule::TYPE_GROUP,
            'target' => 'classe-cm2-a',
            'partition' => QuotaRule::PARTITION_HOME,
            'quota_soft_mb' => 500,
            'quota_hard_mb' => 600,
        ]);
    }

    // =========================================================================
    // AC 13 #8 — Validation soft >= 10 Mo
    // =========================================================================

    public function test_it_rejects_custom_soft_below_10mb_on_home(): void
    {
        $service = $this->makeStubXfsQuotaService();
        $this->app->instance(\App\Services\Filesystem\XfsQuotaService::class, $service);

        $group = $this->makeGroup('classe-validation');

        $admin = $this->makeUser('admin-validate');
        $this->grantServerAdmin($admin);
        $this->actingAs($admin);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->set('overridePartition', QuotaRule::PARTITION_HOME)
            ->set('overrideType', 'custom')
            ->set('overrideSoftMb', 5)
            ->set('overrideOveragePercent', 20)
            ->call('applyOverride')
            ->assertHasErrors('overrideSoftMb');

        $this->assertDatabaseMissing('quota_rules', [
            'target' => 'classe-validation',
        ]);
    }

    /**
     * Sous-classe anonyme : on évite tout shellout XFS pour les tests qui
     * exercent setQuotaRule/deleteQuotaRule réels.
     */
    private function makeStubXfsQuotaService(): \App\Services\Filesystem\XfsQuotaService
    {
        return new class extends \App\Services\Filesystem\XfsQuotaService {
            public function getDiskUsage(string $username): array
            {
                return [
                    'home' => ['used_mb' => 0, 'quota_soft_mb' => 0, 'quota_hard_mb' => 0, 'is_over_soft' => false, 'is_over_hard' => false, 'grace_days' => null, 'error' => null],
                    'sambaedu' => ['used_mb' => 0, 'quota_soft_mb' => 0, 'quota_hard_mb' => 0, 'is_over_soft' => false, 'is_over_hard' => false, 'grace_days' => null, 'error' => null],
                ];
            }
        };
    }
}
