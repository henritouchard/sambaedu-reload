<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\QuotaRule;
use App\Models\QuotaSetting;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 5.1c — Tests Feature Livewire onglet "Quotas & FS" (/admin/settings).
 *
 * Couvre AC 13 #11-14 :
 *  11. persistance defaults par profil et partition (SystemSetting::get)
 *  12. persistance TTL trash + toggle purge
 *  13. persistance grace period par partition
 *  14. blocage save sans server.admin (forged payload → 403)
 */
class AdminSettingsQuotasFsTabTest extends TestCase
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
            Schema::dropIfExists('quota_rules');
            Schema::dropIfExists('quota_audit_logs');
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
                $table->primary(['permission_id', 'model_id', 'model_type'], 'asq_mhp');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'asq_mhr');
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

    private function componentPath(): string
    {
        return 'pages::admin.settings._partials.quotas-fs-tab';
    }

    // =========================================================================
    // AC 13 #11 — Persistance defaults
    // =========================================================================

    public function test_it_persists_defaults_per_profile_and_partition(): void
    {
        $admin = $this->makeAdmin('admin-defaults');
        $this->actingAs($admin);

        Livewire::test($this->componentPath())
            ->set('defaults.eleve.home.soft_mb', 200)
            ->set('defaults.eleve.home.overage_percent', 25)
            ->set('defaults.eleve.sambaedu.soft_mb', 1000)
            ->set('defaults.eleve.sambaedu.overage_percent', 10)
            ->set('defaults.prof.home.soft_mb', 500)
            ->set('defaults.prof.home.overage_percent', 20)
            ->set('defaults.prof.sambaedu.soft_mb', 2000)
            ->set('defaults.prof.sambaedu.overage_percent', 15)
            ->set('defaults.admin.home.soft_mb', 0)
            ->set('defaults.admin.home.overage_percent', 0)
            ->set('defaults.admin.sambaedu.soft_mb', 0)
            ->set('defaults.admin.sambaedu.overage_percent', 0)
            ->set('defaults.itinerant.home.soft_mb', 100)
            ->set('defaults.itinerant.home.overage_percent', 30)
            ->set('defaults.itinerant.sambaedu.soft_mb', 0)
            ->set('defaults.itinerant.sambaedu.overage_percent', 20)
            ->call('saveDefaults')
            ->assertDispatched('toastMagic');

        $stored = SystemSetting::get('quota.defaults');
        $this->assertIsArray($stored);
        $this->assertSame(200, $stored['eleve']['home']['soft_mb']);
        $this->assertSame(25, $stored['eleve']['home']['overage_percent']);
        $this->assertSame(2000, $stored['prof']['sambaedu']['soft_mb']);
        $this->assertSame(100, $stored['itinerant']['home']['soft_mb']);
    }

    /**
     * AC 6 — soft < 10 Mo sur /home doit être rejeté (sauf 0 = illimité accepté).
     */
    public function test_it_rejects_defaults_soft_below_10mb_on_home(): void
    {
        $admin = $this->makeAdmin('admin-validate-defaults');
        $this->actingAs($admin);

        Livewire::test($this->componentPath())
            ->set('defaults.eleve.home.soft_mb', 5)
            ->set('defaults.eleve.home.overage_percent', 20)
            ->call('saveDefaults')
            ->assertHasErrors('defaults.eleve.home.soft_mb');

        $this->assertNull(SystemSetting::get('quota.defaults'));
    }

    // =========================================================================
    // AC 13 #12 — TTL trash
    // =========================================================================

    public function test_it_persists_trash_ttl_and_purge_toggle(): void
    {
        $admin = $this->makeAdmin('admin-trash');
        $this->actingAs($admin);

        Livewire::test($this->componentPath())
            ->set('trash.ttl_days', 60)
            ->set('trash.purge_auto', true)
            ->call('saveTrash')
            ->assertDispatched('toastMagic');

        $stored = SystemSetting::get('quota.trash');
        $this->assertSame(60, $stored['ttl_days']);
        $this->assertTrue($stored['purge_auto']);
    }

    // =========================================================================
    // AC 13 #13 — Grace period
    // =========================================================================

    public function test_it_persists_grace_period_per_partition(): void
    {
        $admin = $this->makeAdmin('admin-grace');
        $this->actingAs($admin);

        // Stub XfsQuotaService pour éviter le shellout XFS.
        $stub = new class extends \App\Services\Filesystem\XfsQuotaService {
            public function setGracePeriod(string $partition, int $days, string $performedBy): array
            {
                // No-op : on ne touche pas au filesystem dans les tests.
                return ['success' => true, 'error' => null];
            }
        };
        $this->app->instance(\App\Services\Filesystem\XfsQuotaService::class, $stub);

        Livewire::test($this->componentPath())
            ->set('grace.home', 14)
            ->set('grace.sambaedu', 21)
            ->call('saveGrace')
            ->assertDispatched('toastMagic');

        $home = QuotaSetting::forPartition(QuotaRule::PARTITION_HOME);
        $samba = QuotaSetting::forPartition(QuotaRule::PARTITION_SAMBAEDU);

        $this->assertSame(14, (int) $home->grace_period_days);
        $this->assertSame(21, (int) $samba->grace_period_days);
    }

    // =========================================================================
    // AC 13 #14 — Block without server.admin (forged payload)
    // =========================================================================

    public function test_it_blocks_save_without_server_admin(): void
    {
        // Mount sans admin → abort(403) immédiat. Le composant Livewire ne
        // s'instancie pas, ce qui couvre déjà la défense en profondeur.
        $viewer = User::query()->create(['login' => 'viewer-bypass-tab', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test($this->componentPath())->assertStatus(403);
    }

    /**
     * AC 12 — défense en profondeur : si l'admin a fait `mount` puis perd
     * sa permission, les méthodes publiques save* DOIVENT toujours bloquer
     * (Gate::allows en première ligne de chaque méthode publique).
     *
     * On vérifie les 3 méthodes mutantes du composant : `saveDefaults`,
     * `saveGrace`, `saveTrash`. Mount séparé par méthode (un `assertStatus(403)`
     * abort le composant Livewire — pas de chaîne possible).
     */
    public function test_save_methods_recheck_permission_when_user_changes(): void
    {
        foreach (['saveDefaults', 'saveGrace', 'saveTrash'] as $method) {
            $admin = $this->makeAdmin('admin-revoke-' . $method);
            $this->actingAs($admin);

            $component = Livewire::test($this->componentPath());

            // Révoquer la permission server.admin sur le user mounté + réauth
            // (Gate::allows lit auth()->user() à chaque call).
            $admin->revokePermissionTo('server.admin');
            $admin->refresh();
            $this->actingAs($admin);

            $component->call($method)->assertStatus(403);
        }
    }

    // =========================================================================
    // Story 5.1d — Bouton "Purger maintenant" (AC 9)
    // =========================================================================

    public function test_it_purges_now_via_button_when_admin(): void
    {
        $admin = $this->makeAdmin('admin-purge-now');
        $this->actingAs($admin);

        // Story 5.1d code review #5 — pré-check TTL côté UI : sans config
        // valide, purgeNow() retourne un toast d'erreur AVANT d'appeler Artisan.
        SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        // Mock Artisan::call — pas de vrai appel à trash:purge.
        Artisan::shouldReceive('call')
            ->once()
            ->with('trash:purge', Mockery::any())
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->andReturn('Purgé : 5 dossier(s). Conservé : 12 dossier(s). Erreurs : 0.');

        Livewire::test($this->componentPath())
            ->call('purgeNow')
            ->assertDispatched('toastMagic');
    }

    public function test_it_blocks_purge_now_without_server_admin(): void
    {
        // Le mount lui-même bloque (Gate::allows mount), mais la défense en
        // profondeur exige qu'un appel direct à purgeNow sur un Livewire
        // déjà mount par un admin puis re-auth en non-admin échoue aussi.
        $admin = $this->makeAdmin('admin-purge-revoke');
        $this->actingAs($admin);

        $component = Livewire::test($this->componentPath());

        $admin->revokePermissionTo('server.admin');
        $admin->refresh();
        $this->actingAs($admin);

        $component->call('purgeNow')->assertStatus(403);
    }

    public function test_it_emits_error_toast_when_purge_command_fails(): void
    {
        $admin = $this->makeAdmin('admin-purge-fail');
        $this->actingAs($admin);

        // Pré-check TTL côté UI (review #5).
        SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('trash:purge', Mockery::any())
            ->andReturn(1); // FAILURE
        Artisan::shouldReceive('output')
            ->andReturn('Purgé : 0 dossier(s). Erreurs : 3.');

        Livewire::test($this->componentPath())
            ->call('purgeNow')
            ->assertDispatched('toastMagic');
    }
}
