<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 5.1c — Tests Feature Livewire de la page /admin/settings (scaffold).
 *
 * Couvre AC 13 #9-10 :
 *   9. rendu mono-onglet "Quotas & FS" (assertSee + assertDontSee placeholders)
 *  10. accès bloqué sans server.admin (mount + payload Livewire forgé → 403)
 */
class AdminSettingsPageTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('system_settings');
            Schema::dropIfExists('quota_settings');
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
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
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

        if (!Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 191)->unique();
                $table->json('value')->nullable();
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
                $table->primary(['permission_id', 'model_id', 'model_type'], 'asp_mhp');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'asp_mhr');
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

    private function makeAdmin(string $login): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    // =========================================================================
    // AC 13 #9
    // =========================================================================

    public function test_it_renders_single_tab_quotas_fs(): void
    {
        $admin = $this->makeAdmin('admin-singletab');
        $this->actingAs($admin);

        Livewire::test('pages::admin.settings.index')
            ->assertSet('tab', 'quotas-fs')
            // 'Quotas' apparaît dans l'onglet — le `&` est HTML-escaped donc
            // on évite la chaîne complète qui dépend de l'escape.
            ->assertSee('Quotas')
            ->assertDontSee('coming soon')
            ->assertDontSee('Bientôt disponible')
            ->assertDontSee('Placeholder');
    }

    // =========================================================================
    // AC 13 #10
    // =========================================================================

    public function test_it_blocks_access_without_server_admin(): void
    {
        $viewer = User::query()->create(['login' => 'viewer-noperm-settings', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test('pages::admin.settings.index')
            ->assertStatus(403);
    }

    /**
     * AC 12 — bypass tentatives sur méthode publique `setTab` également bloquées.
     */
    public function test_it_blocks_set_tab_without_server_admin(): void
    {
        $viewer = User::query()->create(['login' => 'viewer-bypass-settab', 'role' => 'eleve', 'is_active' => true]);

        // On crée un admin pour pouvoir mount le composant (mount() guard)
        // puis on bascule vers viewer non-admin pour tester le call('setTab').
        $admin = $this->makeAdmin('admin-mount-only');
        $this->actingAs($admin);

        $component = Livewire::test('pages::admin.settings.index');

        // Bascule d'identité sans re-mount.
        $this->actingAs($viewer);
        $component->call('setTab', 'quotas-fs')->assertStatus(403);
    }
}
