<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\GpoSummary;
use App\Gpo\Services\GpoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\Support\FakesGpoService;
use Tests\TestCase;
use App\Models\User;

/**
 * Tests Feature Livewire — Filtres avancés sur la page listing GPO.
 *
 * Story 16.14 AC7.1 / AC2.1-2.3.
 */
class GpoIndexAdvancedFiltersTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->bootstrapSpatieTables();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private function makeAdmin(string $login = 'admin-adv-filters'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    /** @return Collection<int,GpoSummary> */
    private function makeGpoCollection(): Collection
    {
        return collect([
            new GpoSummary(
                name: '{AAAA-0001-BBBB-CCCC-DDDDDDDDDDDD}',
                displayName: 'se4_machine_proxy',
                versionNumber: 65539,
                dn: 'CN={AAAA...},CN=Policies,CN=System,DC=example,DC=org',
                path: '\\\\example.org\\sysvol\\example.org\\Policies\\{AAAA...}',
            ),
            new GpoSummary(
                name: '{BBBB-0002-CCCC-DDDD-EEEEEEEEEEEE}',
                displayName: 'se4_user_profile_redirections',
                versionNumber: 65539,
                dn: 'CN={BBBB...},CN=Policies,CN=System,DC=example,DC=org',
                path: null,
            ),
            new GpoSummary(
                name: '{CCCC-0003-DDDD-EEEE-FFFFFFFFFFFF}',
                displayName: 'se4_logon_script_salles',
                versionNumber: 0,
                dn: null,
                path: null,
            ),
            new GpoSummary(
                name: '{DDDD-0004-EEEE-FFFF-000000000000}',
                displayName: 'Default Domain Policy',
                versionNumber: 12,
                dn: null,
                path: null,
            ),
            new GpoSummary(
                name: '{EEEE-0005-FFFF-0000-111111111111}',
                displayName: 'se4_wallpaper_linux',
                versionNumber: 65539,
                dn: null,
                path: null,
            ),
        ]);
    }

    #[Test]
    public function it_filters_by_machine_type(): void
    {
        $admin = $this->makeAdmin('admin-filter-machine');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        // Stub OrganizationalUnitRepository
        $this->app->bind(\App\Repositories\OrganizationalUnitRepository::class, function () {
            $mock = Mockery::mock(\App\Repositories\OrganizationalUnitRepository::class);
            $mock->shouldReceive('listAll')->andReturn([]);
            return $mock;
        });

        Livewire::test('pages::admin.settings.gpo.index')
            ->set('filterType', 'machine')
            ->assertSee('se4_machine_proxy')
            ->assertDontSee('se4_user_profile_redirections')
            ->assertDontSee('se4_logon_script_salles');
    }

    #[Test]
    public function it_filters_by_user_type(): void
    {
        $admin = $this->makeAdmin('admin-filter-user');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        $this->app->bind(\App\Repositories\OrganizationalUnitRepository::class, function () {
            $mock = Mockery::mock(\App\Repositories\OrganizationalUnitRepository::class);
            $mock->shouldReceive('listAll')->andReturn([]);
            return $mock;
        });

        Livewire::test('pages::admin.settings.gpo.index')
            ->set('filterType', 'user')
            ->assertSee('se4_user_profile_redirections')
            ->assertDontSee('se4_machine_proxy')
            ->assertDontSee('Default Domain Policy');
    }

    #[Test]
    public function it_filters_by_logon_type(): void
    {
        $admin = $this->makeAdmin('admin-filter-logon');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        $this->app->bind(\App\Repositories\OrganizationalUnitRepository::class, function () {
            $mock = Mockery::mock(\App\Repositories\OrganizationalUnitRepository::class);
            $mock->shouldReceive('listAll')->andReturn([]);
            return $mock;
        });

        Livewire::test('pages::admin.settings.gpo.index')
            ->set('filterType', 'logon')
            ->assertSee('se4_logon_script_salles')
            ->assertDontSee('se4_machine_proxy');
    }

    #[Test]
    public function it_filters_native_only(): void
    {
        $admin = $this->makeAdmin('admin-filter-native');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        $this->app->bind(\App\Repositories\OrganizationalUnitRepository::class, function () {
            $mock = Mockery::mock(\App\Repositories\OrganizationalUnitRepository::class);
            $mock->shouldReceive('listAll')->andReturn([]);
            return $mock;
        });

        Livewire::test('pages::admin.settings.gpo.index')
            ->set('filterNativeOnly', true)
            // wallpaper et redirections matchent NativeSectionResolver
            ->assertSee('se4_wallpaper_linux')
            ->assertSee('se4_user_profile_redirections')
            // machine_proxy et logon_script ne matchent pas de section native
            ->assertDontSee('se4_machine_proxy')
            ->assertDontSee('Default Domain Policy');
    }

    #[Test]
    public function it_combines_filters_with_and_logic(): void
    {
        $admin = $this->makeAdmin('admin-filter-and');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        $this->app->bind(\App\Repositories\OrganizationalUnitRepository::class, function () {
            $mock = Mockery::mock(\App\Repositories\OrganizationalUnitRepository::class);
            $mock->shouldReceive('listAll')->andReturn([]);
            return $mock;
        });

        // Type=user ET filterNativeOnly=true → seul se4_user_profile_redirections (a "redirections" → profils-itinerants)
        Livewire::test('pages::admin.settings.gpo.index')
            ->set('filterType', 'user')
            ->set('filterNativeOnly', true)
            ->assertSee('se4_user_profile_redirections')
            ->assertDontSee('se4_wallpaper_linux') // wallpaper mais pas user type
            ->assertDontSee('se4_machine_proxy');
    }

    #[Test]
    public function it_resets_advanced_filters(): void
    {
        $admin = $this->makeAdmin('admin-reset-filters');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        $this->app->bind(\App\Repositories\OrganizationalUnitRepository::class, function () {
            $mock = Mockery::mock(\App\Repositories\OrganizationalUnitRepository::class);
            $mock->shouldReceive('listAll')->andReturn([]);
            return $mock;
        });

        Livewire::test('pages::admin.settings.gpo.index')
            ->set('filterType', 'machine')
            ->set('filterNativeOnly', true)
            ->assertSet('filterType', 'machine')
            ->call('resetAdvancedFilters')
            ->assertSet('filterType', '')
            ->assertSet('filterNativeOnly', false)
            ->assertSet('filterVersionMin', null)
            ->assertSet('filterVersionMax', null)
            // Story 16.14 Q1 — filtre santé MULTI désormais.
            ->assertSet('filterHealthStatuses', []);
    }

    #[Test]
    public function it_filters_by_version_min(): void
    {
        $admin = $this->makeAdmin('admin-filter-version-min');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        $this->app->bind(\App\Repositories\OrganizationalUnitRepository::class, function () {
            $mock = Mockery::mock(\App\Repositories\OrganizationalUnitRepository::class);
            $mock->shouldReceive('listAll')->andReturn([]);
            return $mock;
        });

        // versionNumber 65539 = major 1 (65539 >> 16 = 1), minor 3 (65539 & 0xFFFF = 3)
        // Default Domain Policy versionNumber = 12 → major 0, minor 12
        Livewire::test('pages::admin.settings.gpo.index')
            ->set('filterVersionMin', 1) // seuls major >= 1 passent
            ->assertSee('se4_machine_proxy')     // major 1
            ->assertSee('se4_user_profile_redirections')
            ->assertSee('se4_wallpaper_linux')
            ->assertDontSee('Default Domain Policy') // versionNumber=12 → major 0
            ->assertDontSee('se4_logon_script_salles'); // version 0 → major 0
    }
}
