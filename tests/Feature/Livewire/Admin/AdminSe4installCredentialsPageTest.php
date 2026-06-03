<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\ServiceCredential;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Page /admin/settings/credentials — activation/désactivation TOTP de se4install.
 *
 * Stratégie tables : bootstrap manuel (pattern AdminSettingsProfilsItinerantsTabTest,
 * RefreshDatabase incompatible historique). UserService (write AD) est mocké.
 */
class AdminSe4installCredentialsPageTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    private const COMPONENT = 'pages::admin.settings.credentials.index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('service_credentials');
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

        foreach (['permissions', 'roles'] as $t) {
            if (!Schema::hasTable($t)) {
                Schema::create($t, function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                    $table->string('guard_name');
                    $table->timestamps();
                });
                $this->createdTables = true;
            }
        }
        if (!Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_id', 'model_type'], 'se4_mhp');
            });
            $this->createdTables = true;
        }
        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'se4_mhr');
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
        if (!Schema::hasTable('service_credentials')) {
            Schema::create('service_credentials', function (Blueprint $table) {
                $table->id();
                $table->string('name', 64)->unique();
                $table->text('secret')->nullable();
                $table->text('totp_secret')->nullable();
                $table->unsignedBigInteger('totp_applied_counter')->nullable();
                $table->timestamps();
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

    #[Test]
    public function it_renders_for_server_admin(): void
    {
        $this->actingAs($this->makeAdmin('admin-se4-render'));

        Livewire::test(self::COMPONENT)
            ->assertSee('Rotation TOTP')
            ->assertSee('Activer le TOTP')
            ->assertSet('totpActive', false);
    }

    #[Test]
    public function it_blocks_access_without_server_admin(): void
    {
        $viewer = User::query()->create(['login' => 'viewer-se4', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test(self::COMPONENT)->assertStatus(403);
    }

    #[Test]
    public function activate_calls_the_manager_and_marks_active(): void
    {
        $this->mock(UserService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('changePasswordInAd')->once()->andReturn(true);
        });

        $this->actingAs($this->makeAdmin('admin-se4-activate'));

        Livewire::test(self::COMPONENT)
            ->call('activate')
            ->assertSet('totpActive', true);

        $this->assertNotNull(ServiceCredential::firstWhere('name', 'se4install')?->totp_secret);
    }
}
