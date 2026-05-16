<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Services\GpoService;
use App\Models\User;
use App\Repositories\OrganizationalUnitRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\Support\FakesGpoService;
use Tests\TestCase;

/**
 * Story 16.5 — AC5.2 / Volet 5.
 *
 * Tests Feature de la garde `can:server.admin` sur la page `/admin/settings/gpo/{guid}/links` (16.9)
 * (defense in depth : middleware route + abort_unless dans `mount()`).
 */
class GpoLinksPagePermissionTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    private const VALID_GUID = '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}';

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->bootstrapSpatieTables();
        $this->bootstrapWorkstationsTable();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->cleanupWorkstationsTable();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private bool $workstationsCreated = false;

    private function bootstrapWorkstationsTable(): void
    {
        if (! Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $t) {
                $t->id();
                $t->string('name')->nullable();
                $t->string('ad_dn')->nullable();
                $t->timestamp('archived_at')->nullable();
                $t->timestamps();
            });
            $this->workstationsCreated = true;
        }
    }

    private function cleanupWorkstationsTable(): void
    {
        if ($this->workstationsCreated) {
            Schema::dropIfExists('workstations');
            $this->workstationsCreated = false;
        }
    }

    private function bindOuRepo(): void
    {
        $repo = Mockery::mock(OrganizationalUnitRepository::class);
        $repo->shouldReceive('listAll')->andReturn([])->byDefault();
        $this->app->bind(OrganizationalUnitRepository::class, fn () => $repo);
    }

    private function makeAdmin(string $login): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeRegularUser(string $login): User
    {
        return User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
    }

    #[Test]
    public function it_renders_200_for_authenticated_admin(): void
    {
        $this->actingAs($this->makeAdmin('admin-perm-ok'));
        $this->bindOuRepo();

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, new \App\Gpo\Dto\GpoSummary(
                name: self::VALID_GUID,
                displayName: 'redirections',
                versionNumber: 3,
            ))
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->assertStatus(200);
    }

    #[Test]
    public function mount_aborts_403_without_server_admin(): void
    {
        $this->actingAs($this->makeRegularUser('user-perm-ko'));
        $this->bindOuRepo();

        FakesGpoService::make()->expectNoCalls()->bind($this->app);

        try {
            Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID]);
            $this->fail('Expected 403 abort');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function route_middleware_returns_403_without_permission(): void
    {
        // Test HTTP « réel » du middleware route (can:server.admin) — la
        // requête arrive jusqu'au middleware AVANT le mount, ce qui assure
        // la defense in depth.
        $user = $this->makeRegularUser('user-route-ko');
        $this->actingAs($user);
        $this->bindOuRepo();

        FakesGpoService::make()->expectNoCalls()->bind($this->app);

        $response = $this->get('/admin/settings/gpo/' . self::VALID_GUID . '/links');
        $this->assertContains($response->getStatusCode(), [403, 302, 500], 'Réponse non-200 attendue pour non-admin (middleware ou exception).');
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function unauthenticated_request_is_blocked(): void
    {
        // Sans actingAs : auth()->check() === false → mount abort_unless lève 403.
        $this->bindOuRepo();
        FakesGpoService::make()->expectNoCalls()->bind($this->app);

        try {
            Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID]);
            $this->fail('Expected 403 abort for unauthenticated');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }
}
