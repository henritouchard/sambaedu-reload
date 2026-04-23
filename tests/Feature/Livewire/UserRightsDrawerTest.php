<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\SyncGpoJob;
use App\Models\Delegation;
use App\Models\DelegationHistory;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\UserService;
use App\Types\User as AdUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests Feature Livewire du drawer `rights-drawer` (Story 7.1 — AC7, AC8, AC9, AC10).
 *
 * Couvre :
 *  - applyDelegations : grant / remove / negate via toggles
 *  - dispatch GPO sync pour computer.elevate
 *  - auto-création User fallback (ensureEloquentUser)
 *  - toggle négative exclusif avec toggle remove (updatedIsNegative / updatedRemoveDelegation)
 *  - audit trail écrit en base
 */
class UserRightsDrawerTest extends TestCase
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
                $table->primary(['permission_id', 'model_id', 'model_type'], 'mhp_drw');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'mhr_drw');
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
                    'delegations_drw_unique'
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

    /**
     * Story 7.1 — Review #5 : tous les appels Livewire du drawer exigent
     * `user.assign.right`. Ce helper l'accorde à l'admin donné.
     */
    private function grantAdminPermission(User $admin): User
    {
        $admin->givePermissionTo('user.assign.right');
        return $admin;
    }

    /**
     * Story 7.1 — Review #A : mock UserService::getByLogin pour simuler
     * l'annuaire AD. Par défaut, tout login retourne un AdUser plausible
     * (existence AD). Passer un login dans $missing pour simuler l'absence.
     *
     * @param array<int, string> $missing  logins qui doivent retourner null (absents de l'AD)
     */
    private function mockAdUserService(array $missing = []): void
    {
        $mock = Mockery::mock(UserService::class);
        $mock->shouldReceive('getByLogin')->andReturnUsing(function (string $login) use ($missing) {
            if (in_array($login, $missing, true)) {
                return null;
            }
            return new AdUser(
                login: $login,
                fullname: $login,
            );
        });
        $this->app->instance(UserService::class, $mock);
    }

    private function drawerComponent(): string
    {
        // Composant partial — slug filesystem Livewire.
        return 'pages::users._partials.rights-drawer';
    }

    private function makeUser(string $login): User
    {
        return User::create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
    }

    private function makeGroup(string $name = 'drawer-grp'): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => "{$name}-" . uniqid(),
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    // ========================================================================
    // applyDelegations (AC7, AC9)
    // ========================================================================

    public function test_apply_delegations_grant_creates_row_and_audit(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('drw-admin'));
        $target = $this->makeUser('drw-target');
        $group = $this->makeGroup();
        $this->actingAs($admin);
        $this->mockAdUserService();

        Livewire::test($this->drawerComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermissions', ['computer.view'])
            ->call('applyDelegations');

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

    public function test_apply_delegations_remove_deletes_row_and_audits(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('drw-admin-rm'));
        $target = $this->makeUser('drw-target-rm');
        $group = $this->makeGroup();
        $this->actingAs($admin);
        $this->mockAdUserService();

        // Pré-création : d'abord grant
        app(\App\Services\PermissionService::class)->grantDelegation($target, 'computer.view', $group, $admin);
        $this->assertDatabaseHas('delegations', ['user_id' => $target->id]);

        // Maintenant remove via drawer
        Livewire::test($this->drawerComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermissions', ['computer.view'])
            ->set('removeDelegation', true)
            ->call('applyDelegations');

        $this->assertDatabaseMissing('delegations', [
            'user_id' => $target->id,
            'is_negative' => false,
        ]);
        $this->assertEquals(
            1,
            DelegationHistory::where('target_user_id', $target->id)
                ->where('action', 'revoke')->count()
        );
    }

    public function test_apply_delegations_negative_creates_row_with_is_negative_flag(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('drw-admin-neg'));
        $target = $this->makeUser('drw-target-neg');
        $group = $this->makeGroup();
        $this->actingAs($admin);
        $this->mockAdUserService();

        Livewire::test($this->drawerComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermissions', ['computer.view'])
            ->set('isNegative', true)
            ->call('applyDelegations');

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

    public function test_apply_delegations_with_computer_elevate_dispatches_gpo_job(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('drw-admin-gpo'));
        $target = $this->makeUser('drw-target-gpo');
        $group = $this->makeGroup();
        $this->actingAs($admin);
        $this->mockAdUserService();

        Livewire::test($this->drawerComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermissions', ['computer.elevate'])
            ->call('applyDelegations');

        Queue::assertPushed(SyncGpoJob::class, 1);
    }

    public function test_apply_delegations_for_nonexistent_user_creates_minimal_eloquent_user(): void
    {
        // Story 7.1 — Review #A : comportement mis à jour. Si le login existe
        // dans l'AD, on crée l'EloquentUser minimal. L'AD est simulé ici.
        $admin = $this->grantAdminPermission($this->makeUser('drw-admin-newu'));
        $group = $this->makeGroup();
        $this->actingAs($admin);
        $this->mockAdUserService();

        $this->assertDatabaseMissing('users', ['login' => 'newly-created']);

        Livewire::test($this->drawerComponent())
            ->call('open', ['newly-created'])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermissions', ['computer.view'])
            ->call('applyDelegations');

        $this->assertDatabaseHas('users', ['login' => 'newly-created']);

        $newUser = User::where('login', 'newly-created')->first();
        $this->assertDatabaseHas('delegations', ['user_id' => $newUser->id]);
    }

    /**
     * Story 7.1 — Review #A : si le login cible n'existe pas dans l'AD,
     * `ensureEloquentUser` refuse la création du user fantôme, affiche un
     * toast warning et incrémente le compteur d'erreurs.
     */
    public function test_ensure_eloquent_user_rejects_unknown_ad_login(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('drw-admin-unk'));
        $group = $this->makeGroup();
        $this->actingAs($admin);
        $this->mockAdUserService(missing: ['ghost-login']);

        Livewire::test($this->drawerComponent())
            ->call('open', ['ghost-login'])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermissions', ['computer.view'])
            ->call('applyDelegations');

        // Aucun user fantôme créé, aucune délégation.
        $this->assertDatabaseMissing('users', ['login' => 'ghost-login']);
        $this->assertEquals(0, Delegation::count());
    }

    /**
     * Story 7.1 — Review #5 : sans `user.assign.right`, applyDelegations
     * doit lever 403 (HttpException via abort_unless). Livewire absorbe
     * HttpException/AuthorizationException par défaut — on utilise
     * `->assertStatus(403)` sur la réponse Livewire (vue rendue en 403).
     */
    public function test_delegated_user_cannot_apply_delegations_without_user_assign_right(): void
    {
        $nonAdmin = $this->makeUser('drw-non-admin');
        $admin = $this->grantAdminPermission($this->makeUser('drw-admin-step1'));
        $target = $this->makeUser('drw-target-step1');
        $group = $this->makeGroup();
        $this->mockAdUserService();

        // Étape 1 : on ouvre le drawer en tant qu'admin légitime (pour mettre
        // l'état Livewire à 'isOpen=true' + selectedUsers). Pas d'exception.
        $this->actingAs($admin);
        $test = Livewire::test($this->drawerComponent())
            ->call('open', [$target->login])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermissions', ['computer.view']);

        // Étape 2 : on change d'user (non admin). Livewire réinstancie la requête
        // avec le user courant — applyDelegations doit lever 403.
        $this->actingAs($nonAdmin);

        $test->call('applyDelegations')->assertStatus(403);

        // Aucune délégation ne doit avoir été créée.
        $this->assertEquals(0, Delegation::count());
    }

    // ========================================================================
    // Toggle exclusif isNegative / removeDelegation
    // ========================================================================

    public function test_toggling_is_negative_disables_remove(): void
    {
        // Pas de call() sensible ici → pas besoin de user.assign.right ni de mock AD.
        $this->actingAs($this->makeUser('drw-toggle'));

        Livewire::test($this->drawerComponent())
            ->set('removeDelegation', true)
            ->set('isNegative', true)
            ->assertSet('removeDelegation', false)
            ->assertSet('isNegative', true);
    }

    public function test_toggling_remove_disables_is_negative(): void
    {
        $this->actingAs($this->makeUser('drw-toggle2'));

        Livewire::test($this->drawerComponent())
            ->set('isNegative', true)
            ->set('removeDelegation', true)
            ->assertSet('removeDelegation', true)
            ->assertSet('isNegative', false);
    }

    // ========================================================================
    // Validations de formulaire
    // ========================================================================

    public function test_apply_delegations_without_users_does_nothing(): void
    {
        $admin = $this->grantAdminPermission($this->makeUser('drw-empty'));
        $group = $this->makeGroup();
        $this->actingAs($admin);
        $this->mockAdUserService();

        Livewire::test($this->drawerComponent())
            ->set('selectedUsers', [])
            ->set('selectedWorkstationGroupId', $group->id)
            ->set('selectedDelegationPermissions', ['computer.view'])
            ->call('applyDelegations');

        $this->assertEquals(0, Delegation::count());
    }

    public static function tearDownAfterClass(): void
    {
        Mockery::close();
        parent::tearDownAfterClass();
    }
}
