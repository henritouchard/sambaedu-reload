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
 * Tests Feature Livewire de la page /admin/settings.
 *
 * Refonte : la page n'est plus à onglets — c'est désormais une landing
 * regroupant en sections (Système / GPO / Migration / Réseau) des cards
 * pointant vers les sous-pages de configuration.
 *
 * Garde :
 *   - rendu landing : sections + cards principales visibles
 *   - accès bloqué sans server.admin (mount → 403)
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

    public function test_it_renders_landing_with_all_sections(): void
    {
        $admin = $this->makeAdmin('admin-landing');
        $this->actingAs($admin);

        Livewire::test('pages::admin.settings.index')
            ->assertSee('Système')
            ->assertSee('GPO Active Directory')
            ->assertSee('Migration')
            ->assertSee('Réseau')
            ->assertDontSee('coming soon')
            ->assertDontSee('Bientôt disponible')
            ->assertDontSee('Placeholder');
    }

    public function test_it_renders_landing_with_key_cards(): void
    {
        $admin = $this->makeAdmin('admin-cards');
        $this->actingAs($admin);

        // Cartes du landing après réorganisation (les anciennes cartes Quotas /
        // Profils itinérants / Error Logger / Legacy Monitor ont été fusionnées
        // dans « Gestion des fichiers » et « État du système »).
        Livewire::test('pages::admin.settings.index')
            ->assertSee('État du système')
            ->assertSee('Toutes les GPOs')
            ->assertSee('Vue par OU')
            ->assertSee('Migration SE4 → SE5')
            ->assertSee('Gestion des fichiers')
            ->assertSee('ControlHub');
    }

    public function test_it_blocks_access_without_server_admin(): void
    {
        $viewer = User::query()->create(['login' => 'viewer-noperm-settings', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test('pages::admin.settings.index')
            ->assertStatus(403);
    }
}
