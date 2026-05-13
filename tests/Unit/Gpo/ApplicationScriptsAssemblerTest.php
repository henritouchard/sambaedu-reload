<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Services\ApplicationScriptsAssembler;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\PermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 16.7 post-review #4 (2026-05-13) — câblage `localAdminScripts` aux
 * services Spatie natifs Epic 7.
 *
 * Couvre la branche `os=windows && userprofile !== ''` (logon/logoff) ainsi
 * que la branche Linux iso-legacy `/etc/sudoers.d/<user>`. Mock du
 * `PermissionService` pour isoler la logique de génération du script.
 */
class ApplicationScriptsAssemblerTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        // Domaine Samba prévisible pour les assertions iso-bytes.
        config(['sambaedu.samba_domain' => 'SE4FS']);
        // Désactive la synchro AD déclenchée par `WorkstationGroupObserver::created`
        // (test isolé du backend LDAP — pattern réutilisable).
        WorkstationGroupObserver::disableSync();
        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        WorkstationGroupObserver::enableSync();
        if ($this->createdTables) {
            Schema::dropIfExists('workstation_group_workstation');
            Schema::dropIfExists('workstations');
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
        if (! Schema::hasTable('users')) {
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

        if (! Schema::hasTable('workstation_groups')) {
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

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_id', 'model_type'], 'mhp_primary');
            });
            $this->createdTables = true;
        }
        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'mhr_primary');
            });
            $this->createdTables = true;
        }
        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('delegations')) {
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

        Permission::firstOrCreate(['name' => 'computer.elevate', 'guard_name' => 'web']);
    }

    private function makeUser(string $login): User
    {
        return User::create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
    }

    private function makeGroup(string $name): WorkstationGroup
    {
        return WorkstationGroup::create([
            'name' => $name,
            'is_physical' => true,
            'is_active' => true,
        ]);
    }

    private function infoLogonWindows(string $user = 'alice', string $machine = 'pc01'): array
    {
        return [
            'os' => 'windows',
            'action' => 'logon',
            'user' => ['cn' => $user],
            'machine' => ['cn' => $machine],
            'userprofile' => 'C:\\Users\\' . $user,
            'parcs' => [],
        ];
    }

    /**
     * Invocation directe de la méthode privée `localAdminScripts` via réflexion
     * (parité tests Generator).
     */
    private function invokeLocalAdmin(ApplicationScriptsAssembler $a, array $info): array
    {
        $ref = new \ReflectionMethod($a, 'localAdminScripts');
        return $ref->invoke($a, $info);
    }

    #[Test]
    public function it_generates_add_at_logon_when_user_has_global_computer_elevate(): void
    {
        $user = $this->makeUser('alice');
        $user->givePermissionTo('computer.elevate');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('alice', 'pc01');

        $result = $this->invokeLocalAdmin($assembler, $info);

        self::assertSame('cmd', $result['interpreter']);
        $script = implode('', $result['script']);
        self::assertStringContainsString('net localgroup administrateurs "SE4FS\\alice" /add', $script);
        self::assertStringContainsString('set admin=1', $script);
    }

    #[Test]
    public function it_generates_add_at_logon_when_user_has_scoped_delegation(): void
    {
        $user = $this->makeUser('bob');
        $group = $this->makeGroup('salle-A');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')
            ->withArgs(fn ($u, $p, $g) => $u->id === $user->id && $p === 'computer.elevate' && $g->id === $group->id)
            ->andReturn(true);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('bob', 'pc02');
        // Le générateur peuple `parcs` depuis le memberof LDAP — on simule.
        $info['parcs'] = [$group->name];

        $result = $this->invokeLocalAdmin($assembler, $info);
        $script = implode('', $result['script']);

        self::assertStringContainsString('net localgroup administrateurs "SE4FS\\bob" /add', $script);
        self::assertStringContainsString('set admin=1', $script);
    }

    #[Test]
    public function it_produces_empty_script_at_logon_when_user_has_no_rights(): void
    {
        $this->makeUser('eve');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('eve', 'pc03');

        $result = $this->invokeLocalAdmin($assembler, $info);
        $script = implode('', $result['script']);

        self::assertSame('cmd', $result['interpreter']);
        self::assertSame('', $script, 'Aucun script ne doit être émis pour un user sans droit.');
    }

    #[Test]
    public function it_always_emits_delete_at_logoff_regardless_of_rights(): void
    {
        $this->makeUser('alice');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('alice', 'pc01');
        $info['action'] = 'logoff';

        $result = $this->invokeLocalAdmin($assembler, $info);
        $script = implode('', $result['script']);

        self::assertSame('cmd', $result['interpreter']);
        self::assertStringContainsString('net localgroup administrateurs "SE4FS\\alice" /delete', $script);
    }

    #[Test]
    public function it_generates_sudoers_d_at_logon_on_linux_when_elevated(): void
    {
        $user = $this->makeUser('charlie.dupont');
        $user->givePermissionTo('computer.elevate');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('charlie.dupont', 'pc-lin');
        $info['os'] = 'linux';

        $result = $this->invokeLocalAdmin($assembler, $info);
        $script = implode('', $result['script']);

        self::assertSame('bash', $result['interpreter']);
        // Le `.` est remplacé par `_` dans le nom de fichier sudoers (parité legacy).
        self::assertStringContainsString('/etc/sudoers.d/charlie_dupont', $script);
        self::assertStringContainsString('charlie.dupont ALL=(ALL:ALL) ALL', $script);
        self::assertStringContainsString('chmod 0440', $script);
    }

    #[Test]
    public function it_removes_sudoers_d_at_logoff_on_linux_inconditionally(): void
    {
        $this->makeUser('charlie.dupont');

        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('charlie.dupont', 'pc-lin');
        $info['os'] = 'linux';
        $info['action'] = 'logoff';

        $result = $this->invokeLocalAdmin($assembler, $info);
        $script = implode('', $result['script']);

        self::assertStringContainsString('rm -f /etc/sudoers.d/charlie_dupont', $script);
    }

    #[Test]
    public function resolve_local_admin_right_returns_false_when_user_unknown(): void
    {
        $perm = Mockery::mock(PermissionService::class);
        $perm->shouldReceive('canOnWorkstationGroup')->andReturn(false)->byDefault();

        $assembler = new ApplicationScriptsAssembler($perm);
        $info = $this->infoLogonWindows('ghost', 'pc99');

        self::assertFalse($assembler->resolveLocalAdminRight($info));
    }

    #[Test]
    public function resolve_local_admin_right_returns_false_when_cn_missing(): void
    {
        $perm = Mockery::mock(PermissionService::class);
        $assembler = new ApplicationScriptsAssembler($perm);

        self::assertFalse($assembler->resolveLocalAdminRight([
            'user' => ['cn' => ''],
            'machine' => ['cn' => 'pc01'],
        ]));
        self::assertFalse($assembler->resolveLocalAdminRight([
            'user' => ['cn' => 'alice'],
            'machine' => ['cn' => ''],
        ]));
    }
}
