<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\User;
use App\Services\RoamingProfileService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 1bis.18f — Tests Feature Livewire onglet "Profils itinérants"
 * (/admin/settings).
 *
 * Couvre AC #2, #3, #4, #5, #10 :
 *   - rendu du partial avec server.admin
 *   - blocage 403 sans server.admin (mount + payloads forgés)
 *   - addExclusion via modale + persistance via service stub
 *   - rejet path-traversal côté UI
 *   - applyToGpo appelle setExclusions(.., true)
 *   - removeExclusion
 *
 * Stratégie de stub : on bind dans le container une sous-classe anonyme de
 * `RoamingProfileService` qui capture les calls (pattern 5.1c stub
 * `XfsQuotaService`) — pas de mock du legacy via Mockery.
 */
class AdminSettingsProfilsItinerantsTabTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
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
                $table->primary(['permission_id', 'model_id', 'model_type'], 'aspi_mhp');
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'aspi_mhr');
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

    /**
     * Bind un stub de RoamingProfileService dans le container et le retourne
     * pour assertions (capture des calls + lecture de l'état mémoire).
     */
    private function bindStubService(array $initialExclusions = [], array $stats = []): object
    {
        $stub = new class($initialExclusions, $stats) extends RoamingProfileService {
            public array $exclusions;
            public array $stats;
            /** @var list<array{op:string, args:array}> */
            public array $calls = [];

            public function __construct(array $exclusions, array $stats)
            {
                $this->exclusions = $exclusions;
                $this->stats = $stats;
            }

            public function getExclusions(): array
            {
                $this->calls[] = ['op' => 'getExclusions', 'args' => []];
                return $this->exclusions;
            }

            public function setExclusions(array $values, bool $applyVersionBump = false): void
            {
                $this->calls[] = ['op' => 'setExclusions', 'args' => [$values, $applyVersionBump]];
                $this->exclusions = $values;
            }

            public function getProfileStatsGlobal(): array
            {
                $this->calls[] = ['op' => 'getProfileStatsGlobal', 'args' => []];
                return $this->stats;
            }

            public function getProfileStatsForPath(string $path): array
            {
                $this->calls[] = ['op' => 'getProfileStatsForPath', 'args' => [$path]];
                return [];
            }

            public function generatePurgeScript(): string
            {
                $this->calls[] = ['op' => 'generatePurgeScript', 'args' => []];
                return '';
            }
        };
        $this->app->instance(RoamingProfileService::class, $stub);
        return $stub;
    }

    private function componentPath(): string
    {
        return 'pages::admin.settings._partials.profils-itinerants-tab';
    }

    // =========================================================================
    // Rendu page
    // =========================================================================

    #[Test]
    public function it_renders_profils_tab_with_server_admin(): void
    {
        $this->bindStubService(['AppData/Local/Mozilla']);
        $admin = $this->makeAdmin('admin-render-profils');
        $this->actingAs($admin);

        Livewire::test($this->componentPath())
            ->assertSee('Exclusions du profil itinérant')
            ->assertSee('AppData/Local/Mozilla')
            ->assertSee('Statistiques des profils itinérants');
    }

    #[Test]
    public function it_blocks_access_without_server_admin(): void
    {
        $this->bindStubService();
        $viewer = User::query()->create([
            'login' => 'viewer-no-perm-profils',
            'role' => 'eleve',
            'is_active' => true,
        ]);
        $this->actingAs($viewer);

        Livewire::test($this->componentPath())->assertStatus(403);
    }

    #[Test]
    public function it_blocks_add_exclusion_without_server_admin_even_on_forged_payload(): void
    {
        $this->bindStubService();

        // Mount avec admin pour pouvoir construire le composant…
        $admin = $this->makeAdmin('admin-bypass-profils-mount');
        $this->actingAs($admin);
        $component = Livewire::test($this->componentPath());

        // …puis bascule vers viewer non-admin.
        $viewer = User::query()->create([
            'login' => 'viewer-bypass-profils-call',
            'role' => 'eleve',
            'is_active' => true,
        ]);
        $this->actingAs($viewer);

        $component->set('newExclusion', 'foo')->call('addExclusion')->assertStatus(403);
    }

    // =========================================================================
    // Méthodes mutantes
    // =========================================================================

    #[Test]
    public function it_adds_exclusion_via_modal_and_persists_via_service(): void
    {
        $stub = $this->bindStubService();
        $admin = $this->makeAdmin('admin-add-excl');
        $this->actingAs($admin);

        Livewire::test($this->componentPath())
            ->set('newExclusion', 'AppData/Local/Mozilla')
            ->call('addExclusion')
            ->assertDispatched('toastMagic')
            ->assertSet('showAddModal', false)
            ->assertSet('newExclusion', '');

        // Le service a bien été appelé avec applyVersionBump=false.
        $setCalls = array_filter($stub->calls, fn ($c) => $c['op'] === 'setExclusions');
        $this->assertNotEmpty($setCalls);
        $first = array_values($setCalls)[0];
        $this->assertSame(['AppData/Local/Mozilla'], $first['args'][0]);
        $this->assertFalse($first['args'][1]);
    }

    #[Test]
    public function it_rejects_path_traversal_attempt_in_add_exclusion(): void
    {
        $stub = $this->bindStubService();
        $admin = $this->makeAdmin('admin-reject-traversal');
        $this->actingAs($admin);

        Livewire::test($this->componentPath())
            ->set('newExclusion', '../../etc/passwd')
            ->call('addExclusion')
            ->assertDispatched('toastMagic'); // toast error

        // Aucun setExclusions ne doit avoir été déclenché : seul getExclusions
        // (mount + reload) a tourné.
        $setCalls = array_filter($stub->calls, fn ($c) => $c['op'] === 'setExclusions');
        $this->assertEmpty($setCalls, 'setExclusions ne doit JAMAIS être appelé avec une valeur path-traversal.');
    }

    #[Test]
    public function it_apply_to_gpo_calls_set_exclusions_with_version_bump(): void
    {
        $stub = $this->bindStubService(['AppData/Local/Mozilla', 'AppData/Local/INet']);
        $admin = $this->makeAdmin('admin-apply-gpo');
        $this->actingAs($admin);

        Livewire::test($this->componentPath())
            ->call('applyToGpo')
            ->assertDispatched('toastMagic');

        $setCalls = array_values(array_filter($stub->calls, fn ($c) => $c['op'] === 'setExclusions'));
        $this->assertNotEmpty($setCalls);
        $last = end($setCalls);
        // Deuxième arg = applyVersionBump TRUE.
        $this->assertTrue($last['args'][1]);
        $this->assertSame(['AppData/Local/Mozilla', 'AppData/Local/INet'], $last['args'][0]);
    }

    #[Test]
    public function it_removes_exclusion_and_persists_via_service(): void
    {
        $stub = $this->bindStubService(['A/foo', 'B/bar', 'C/baz']);
        $admin = $this->makeAdmin('admin-remove-excl');
        $this->actingAs($admin);

        Livewire::test($this->componentPath())
            ->call('removeExclusion', 1)
            ->assertDispatched('toastMagic');

        $setCalls = array_values(array_filter($stub->calls, fn ($c) => $c['op'] === 'setExclusions'));
        $this->assertNotEmpty($setCalls);
        $first = $setCalls[0];
        // L'index 1 ('B/bar') doit avoir été retiré.
        $this->assertSame(['A/foo', 'C/baz'], $first['args'][0]);
        $this->assertFalse($first['args'][1]);
    }

    #[Test]
    public function it_opens_stats_modal_on_drill_down(): void
    {
        $this->bindStubService([], [
            'AppData/Local' => ['sum' => 1024, 'average' => 64.0, 'nb' => 3, 'user' => ['u1' => 64.0]],
        ]);
        $admin = $this->makeAdmin('admin-drill-stats');
        $this->actingAs($admin);

        Livewire::test($this->componentPath())
            ->call('openStats', 'AppData/Local')
            ->assertSet('showStatsModal', true)
            ->assertSet('statsPath', 'AppData/Local');
    }
}
