<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Jobs\ApplyQuotaJob;
use App\Models\QuotaAuditLog;
use App\Models\QuotaRule;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests Feature Livewire de la section Quota fiche user (story 5.1b, AC 9 cas 4-7).
 *
 * Couvre :
 *  - rendu du snapshot avec captured_at + breakdown héritage
 *  - refresh manuel (bouton Actualiser → toast success)
 *  - override sans server.admin → abort 403 + bouton absent
 *  - override avec server.admin → ApplyQuotaJob dispatché
 */
class UserShowQuotaSectionTest extends TestCase
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
                $table->primary(['permission_id', 'model_id', 'model_type'], 'mhp_qs');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'mhr_qs');
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
        Permission::firstOrCreate(['name' => 'user.read', 'guard_name' => 'web']);
    }

    private function componentPath(): string
    {
        return 'pages::users.[login]._partials.quota-section';
    }

    private function makeUser(string $login): User
    {
        return User::query()->create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
    }

    private function grantServerAdmin(User $user): User
    {
        $user->givePermissionTo('server.admin');
        return $user;
    }

    public function test_it_renders_quota_section_with_snapshot_data(): void
    {
        $snapshot = [
            'home' => [
                'used_kb' => 50_000,
                'soft_kb' => 100_000,
                'hard_kb' => 120_000,
                'used_mb' => 49,
                'soft_mb' => 98,
                'hard_mb' => 117,
                'percent' => 50,
                'is_over_soft' => false,
                'is_over_hard' => false,
                'grace_days' => null,
            ],
            'sambaedu' => [
                'used_kb' => 1024,
                'soft_kb' => 100_000,
                'hard_kb' => 120_000,
                'used_mb' => 1,
                'soft_mb' => 98,
                'hard_mb' => 117,
                'percent' => 1,
                'is_over_soft' => false,
                'is_over_hard' => false,
                'grace_days' => null,
            ],
            'captured_at' => '2026-04-23T03:00:00+02:00',
        ];

        $target = $this->makeUser('alice-snap');
        $target->update(['quota_snapshot' => $snapshot]);

        // Règle user pour que getEffectiveQuota expose soft_mb > 0
        // (sinon is_unlimited=true court-circuite l'affichage de la %).
        QuotaRule::query()->create([
            'type' => QuotaRule::TYPE_USER,
            'target' => 'alice-snap',
            'partition' => QuotaRule::PARTITION_HOME,
            'quota_soft_mb' => 98,
            'quota_hard_mb' => 117,
            'is_active' => true,
        ]);

        $viewer = $this->makeUser('viewer-snap');
        $this->actingAs($viewer);

        Livewire::test($this->componentPath(), ['login' => 'alice-snap'])
            ->assertSet('login', 'alice-snap')
            ->assertSet('snapshot.home.percent', 50)
            ->assertSee('50%')
            ->assertSee('Snapshot du');
    }

    public function test_it_refreshes_snapshot_on_button_click(): void
    {
        // Pour ce test on mock XfsQuotaService de sorte que getDiskUsage
        // retourne une valeur déterministe sans shellout XFS.
        $mock = \Mockery::mock(\App\Services\Filesystem\XfsQuotaService::class);
        $mock->shouldReceive('getEffectiveQuota')->andReturn([
            'source' => 'none',
            'source_name' => null,
            'quota_soft_mb' => 0,
            'quota_hard_mb' => 0,
            'is_unlimited' => true,
        ]);
        $mock->shouldReceive('getDiskUsage')
            ->with('bob-refresh')
            ->once()
            ->andReturn([
                'home' => [
                    'used_mb' => 42,
                    'quota_soft_mb' => 100,
                    'quota_hard_mb' => 120,
                    'is_over_soft' => false,
                    'is_over_hard' => false,
                    'grace_days' => null,
                    'error' => null,
                ],
                'sambaedu' => [
                    'used_mb' => 0,
                    'quota_soft_mb' => 0,
                    'quota_hard_mb' => 0,
                    'is_over_soft' => false,
                    'is_over_hard' => false,
                    'grace_days' => null,
                    'error' => null,
                ],
            ]);
        $this->app->instance(\App\Services\Filesystem\XfsQuotaService::class, $mock);

        $target = $this->makeUser('bob-refresh');
        $admin = $this->makeUser('admin-refresh');
        // Post code review : refresh réservé server.admin.
        $this->grantServerAdmin($admin);
        $this->actingAs($admin);

        // Reset du rate limiter pour garantir un run propre.
        \Illuminate\Support\Facades\RateLimiter::clear('quota-refresh:' . $admin->id);

        Livewire::test($this->componentPath(), ['login' => 'bob-refresh'])
            ->call('refreshSnapshot')
            ->assertDispatched('toastMagic');

        $target->refresh();
        $this->assertNotNull($target->quota_snapshot);
        $this->assertSame(42, $target->quota_snapshot['home']['used_mb']);
        $this->assertArrayHasKey('captured_at', $target->quota_snapshot);
    }

    public function test_it_blocks_refresh_without_server_admin(): void
    {
        $this->mockQuotaServiceStub();

        $target = $this->makeUser('eve-noperm');
        $viewer = $this->makeUser('viewer-noperm-refresh');
        // Viewer SANS server.admin.
        $this->actingAs($viewer);

        Livewire::test($this->componentPath(), ['login' => 'eve-noperm'])
            ->call('refreshSnapshot')
            ->assertStatus(403);

        // Le bouton Actualiser ne doit pas être rendu non plus.
        Livewire::test($this->componentPath(), ['login' => 'eve-noperm'])
            ->assertDontSee('Actualiser');
    }

    public function test_it_rate_limits_refresh_after_5_attempts(): void
    {
        // Stub service : getDiskUsage doit NE PAS être appelée pour le 6ᵉ essai.
        $mock = \Mockery::mock(\App\Services\Filesystem\XfsQuotaService::class);
        $mock->shouldReceive('getEffectiveQuota')->andReturn([
            'source' => 'none',
            'source_name' => null,
            'quota_soft_mb' => 0,
            'quota_hard_mb' => 0,
            'is_unlimited' => true,
        ]);
        // 5 appels max attendus (le 6ᵉ est rate-limité avant le shellout).
        $mock->shouldReceive('getDiskUsage')
            ->times(5)
            ->andReturn([
                'home' => ['used_mb' => 1, 'quota_soft_mb' => 100, 'quota_hard_mb' => 120, 'is_over_soft' => false, 'is_over_hard' => false, 'grace_days' => null, 'error' => null],
                'sambaedu' => ['used_mb' => 0, 'quota_soft_mb' => 0, 'quota_hard_mb' => 0, 'is_over_soft' => false, 'is_over_hard' => false, 'grace_days' => null, 'error' => null],
            ]);
        $this->app->instance(\App\Services\Filesystem\XfsQuotaService::class, $mock);

        $target = $this->makeUser('frank-rl');
        $admin = $this->makeUser('admin-rl');
        $this->grantServerAdmin($admin);
        $this->actingAs($admin);

        \Illuminate\Support\Facades\RateLimiter::clear('quota-refresh:' . $admin->id);

        $component = Livewire::test($this->componentPath(), ['login' => 'frank-rl']);

        // 5 refresh autorisés.
        for ($i = 0; $i < 5; $i++) {
            $component->call('refreshSnapshot');
        }

        // 6ᵉ refresh : bloqué, toast d'erreur, pas de shellout.
        $component->call('refreshSnapshot')
            ->assertDispatched('toastMagic');

        // Nettoyage pour ne pas polluer les autres tests.
        \Illuminate\Support\Facades\RateLimiter::clear('quota-refresh:' . $admin->id);
    }

    public function test_it_blocks_override_without_server_admin(): void
    {
        $this->mockQuotaServiceStub();

        $target = $this->makeUser('charlie-block');
        $viewer = $this->makeUser('viewer-noperm'); // pas de server.admin
        $this->actingAs($viewer);

        // Le bouton "Modifier le quota" ne doit PAS être rendu.
        Livewire::test($this->componentPath(), ['login' => 'charlie-block'])
            ->assertDontSee('Modifier le quota');

        // Même un appel forgé à applyOverride doit retourner un statut 403
        // (Livewire convertit abort(403) en réponse HTTP).
        Livewire::test($this->componentPath(), ['login' => 'charlie-block'])
            ->call('applyOverride')
            ->assertStatus(403);
    }

    public function test_it_applies_override_with_server_admin(): void
    {
        // Sous-classe anonyme : on hérite du vrai XfsQuotaService mais on
        // stubbe les 2 méthodes de lecture (getDiskUsage / getEffectiveQuota)
        // pour ne pas toucher à XFS. setQuotaRule reste la vraie — elle crée
        // la règle BDD et dispatche ApplyQuotaJob, ce qu'on veut vérifier.
        $service = new class extends \App\Services\Filesystem\XfsQuotaService {
            public function getDiskUsage(string $username): array
            {
                return [
                    'home' => ['used_mb' => 10, 'quota_soft_mb' => 100, 'quota_hard_mb' => 120, 'is_over_soft' => false, 'is_over_hard' => false, 'grace_days' => null, 'error' => null],
                    'sambaedu' => ['used_mb' => 0, 'quota_soft_mb' => 0, 'quota_hard_mb' => 0, 'is_over_soft' => false, 'is_over_hard' => false, 'grace_days' => null, 'error' => null],
                ];
            }

            public function getEffectiveQuota(string $username, string $partition, array $userGroups = [], string $userProfile = 'eleve'): array
            {
                return [
                    'source' => 'none',
                    'source_name' => null,
                    'quota_soft_mb' => 0,
                    'quota_hard_mb' => 0,
                    'is_unlimited' => true,
                ];
            }
        };
        $this->app->instance(\App\Services\Filesystem\XfsQuotaService::class, $service);

        $target = $this->makeUser('diana-ok');
        $admin = $this->makeUser('admin-ok');
        $this->grantServerAdmin($admin);
        $this->actingAs($admin);

        Livewire::test($this->componentPath(), ['login' => 'diana-ok'])
            ->set('overridePartition', QuotaRule::PARTITION_HOME)
            ->set('overrideType', 'custom')
            ->set('overrideSoftMb', 500)
            ->set('overrideOveragePercent', 20)
            ->call('applyOverride')
            ->assertDispatched('toastMagic');

        $this->assertDatabaseHas('quota_rules', [
            'type' => QuotaRule::TYPE_USER,
            'target' => 'diana-ok',
            'partition' => QuotaRule::PARTITION_HOME,
            'quota_soft_mb' => 500,
            'quota_hard_mb' => 600,
        ]);

        // ApplyQuotaJob doit avoir été dispatché (Queue::fake()). Les
        // propriétés du job sont privées — on se contente de vérifier la
        // présence du dispatch ; la règle BDD attestée ci-dessus garantit
        // le bon user/partition/soft/hard.
        Queue::assertPushed(ApplyQuotaJob::class, 1);
    }

    public function test_it_rejects_custom_soft_below_10mb_on_home(): void
    {
        $this->mockQuotaServiceStub();

        $admin = $this->makeUser('admin-lowsoft');
        $this->grantServerAdmin($admin);
        $this->actingAs($admin);

        $target = $this->makeUser('target-lowsoft');

        Livewire::test($this->componentPath(), ['login' => 'target-lowsoft'])
            ->set('overridePartition', QuotaRule::PARTITION_HOME)
            ->set('overrideType', 'custom')
            ->set('overrideSoftMb', 5)
            ->set('overrideOveragePercent', 20)
            ->call('applyOverride')
            ->assertHasErrors('overrideSoftMb');

        $this->assertDatabaseMissing('quota_rules', [
            'target' => 'target-lowsoft',
        ]);
    }

    /**
     * Mock partiel du service pour les tests qui ne doivent pas exercer la
     * logique d'override réelle (blocage 403 par ex).
     */
    private function mockQuotaServiceStub(): void
    {
        $mock = \Mockery::mock(\App\Services\Filesystem\XfsQuotaService::class);
        $mock->shouldReceive('getEffectiveQuota')->andReturn([
            'source' => 'none',
            'source_name' => null,
            'quota_soft_mb' => 0,
            'quota_hard_mb' => 0,
            'is_unlimited' => true,
        ]);
        $mock->shouldReceive('getDiskUsage')->andReturn([
            'home' => ['used_mb' => 0, 'quota_soft_mb' => 0, 'quota_hard_mb' => 0, 'is_over_soft' => false, 'is_over_hard' => false, 'grace_days' => null, 'error' => null],
            'sambaedu' => ['used_mb' => 0, 'quota_soft_mb' => 0, 'quota_hard_mb' => 0, 'is_over_soft' => false, 'is_over_hard' => false, 'grace_days' => null, 'error' => null],
        ]);
        $this->app->instance(\App\Services\Filesystem\XfsQuotaService::class, $mock);
    }
}
