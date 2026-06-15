<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\RoamingProfileService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 26.3 — Bandeau orphelins + purge native (AC #3, #4, #5, #7).
 *
 * Couvre :
 *   - rendu du bandeau « N profils orphelins » + bouton purge (server.admin) ;
 *   - gating server.admin de purgeOrphans (403 sans permission, même payload forgé) ;
 *   - purge appelle le service et rafraîchit le compteur + toast ;
 *   - INVARIANT PERF : aucun shellout/`du` au render ni au call (l'UI lit le cache).
 */
class ProfilsItinerantsOrphanPurgeTest extends TestCase
{
    use DatabaseTransactions;

    private bool $created = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createTables();
    }

    protected function tearDown(): void
    {
        if ($this->created) {
            Schema::dropIfExists('model_has_permissions');
            Schema::dropIfExists('model_has_roles');
            Schema::dropIfExists('role_has_permissions');
            Schema::dropIfExists('permissions');
            Schema::dropIfExists('roles');
            Schema::dropIfExists('system_settings');
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    private function createTables(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255)->unique();
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->json('profile_snapshot')->nullable();
                $table->timestamps();
            });
            $this->created = true;
        }

        if (!Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
            $this->created = true;
        }

        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->created = true;
        }

        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->created = true;
        }

        if (!Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_id', 'model_type'], 'opi_mhp');
            });
            $this->created = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'opi_mhr');
            });
            $this->created = true;
        }

        if (!Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
            $this->created = true;
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
     * Stub service : compteur orphelins piloté en mémoire + capture de purge.
     * getExclusions/stats neutralisés (pas de legacy). scanProfileSizes lève si
     * appelé → garantit qu'aucun scan FS n'a lieu au render/call.
     */
    private function bindStub(int $orphanCount, array $purgeResult = ['moved' => 0, 'skipped' => 0, 'errors' => 0]): object
    {
        $stub = new class($orphanCount, $purgeResult) extends RoamingProfileService {
            public int $count;
            public array $purgeResult;
            public bool $purged = false;

            public function __construct(int $count, array $purgeResult)
            {
                $this->count = $count;
                $this->purgeResult = $purgeResult;
            }

            public function getExclusions(): array
            {
                return [];
            }

            public function getProfileStatsGlobal(): array
            {
                return [];
            }

            public function getOrphanCount(): int
            {
                return $this->count;
            }

            public function getOrphanProfiles(): array
            {
                return array_map(fn ($i) => "orphan{$i}.V1", range(1, max(0, $this->count)));
            }

            public function purgeOrphanProfiles(): array
            {
                $this->purged = true;
                // Simule l'effet : plus d'orphelins après purge.
                $this->count = 0;
                return $this->purgeResult;
            }

            public function scanProfileSizes(): ?array
            {
                throw new \RuntimeException('scanProfileSizes ne doit JAMAIS être appelé depuis l\'UI (invariant perf).');
            }
        };

        $this->app->instance(RoamingProfileService::class, $stub);
        return $stub;
    }

    private function componentPath(): string
    {
        return 'pages::admin.settings._partials.profils-itinerants-tab';
    }

    #[Test]
    public function it_renders_orphan_banner_with_count(): void
    {
        $this->bindStub(3);
        $this->actingAs($this->makeAdmin('admin-banner'));

        Livewire::test($this->componentPath())
            ->assertSet('orphanCount', 3)
            ->assertSee('profil(s) itinérant(s) orphelin(s)')
            ->assertSee('Purger les profils orphelins');
    }

    #[Test]
    public function it_hides_banner_when_no_orphan(): void
    {
        $this->bindStub(0);
        $this->actingAs($this->makeAdmin('admin-no-orphan'));

        Livewire::test($this->componentPath())
            ->assertSet('orphanCount', 0)
            ->assertDontSee('Purger les profils orphelins');
    }

    #[Test]
    public function it_purges_orphans_and_refreshes_count(): void
    {
        $stub = $this->bindStub(2, ['moved' => 2, 'skipped' => 0, 'errors' => 0]);
        $this->actingAs($this->makeAdmin('admin-purge'));

        Livewire::test($this->componentPath())
            ->assertSet('orphanCount', 2)
            ->call('purgeOrphans')
            ->assertDispatched('toastMagic')
            ->assertSet('orphanCount', 0);

        $this->assertTrue($stub->purged);
    }

    #[Test]
    public function it_blocks_purge_without_server_admin_even_on_forged_payload(): void
    {
        $this->bindStub(2);

        $admin = $this->makeAdmin('admin-purge-mount');
        $this->actingAs($admin);
        $component = Livewire::test($this->componentPath());

        $viewer = User::query()->create(['login' => 'viewer-purge', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        $component->call('purgeOrphans')->assertStatus(403);
    }

    #[Test]
    public function it_never_shells_out_at_render_or_purge(): void
    {
        // Process::fake() : si un `du`/shellout fuit, on le détectera.
        Process::fake();

        $this->bindStub(1, ['moved' => 1, 'skipped' => 0, 'errors' => 0]);
        $this->actingAs($this->makeAdmin('admin-no-shell'));

        Livewire::test($this->componentPath())
            ->call('purgeOrphans');

        // Aucun process ne doit avoir été lancé par l'UI : tout passe par le
        // cache (compteur) et le service (stub sans shellout). Le stub lève une
        // exception si scanProfileSizes est appelé — non atteint ici.
        Process::assertNothingRan();
    }
}
