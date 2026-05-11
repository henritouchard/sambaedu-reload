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
 * Tests Feature Livewire — Page listing GPO `/app/gpo` (Story 16.2, AC5.1).
 *
 * Stratégie mock : helper {@see FakesGpoService} → binding container Laravel.
 * Pas d'appel `samba-tool` réel.
 */
class GpoIndexPageTest extends TestCase
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

    private function makeAdmin(string $login = 'admin-gpo-test'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeUser(string $login = 'user-gpo-test'): User
    {
        return User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
    }

    /** @return Collection<int,GpoSummary> */
    private function makeGpoCollection(): Collection
    {
        return collect([
            new GpoSummary(
                name: '{31B2F340-016D-11D2-945F-00C04FB984F9}',
                displayName: 'Default Domain Policy',
                versionNumber: 65539,
                dn: 'CN={31B2F340-016D-11D2-945F-00C04FB984F9},CN=Policies,CN=System,DC=example,DC=org',
                path: '\\\\example.org\\sysvol\\example.org\\Policies\\{31B2F340-016D-11D2-945F-00C04FB984F9}',
            ),
            new GpoSummary(
                name: '{6AC1786C-016F-11D2-945F-00C04FB984F9}',
                displayName: 'Default Domain Controllers Policy',
                versionNumber: 12,
                dn: 'CN={6AC1786C-016F-11D2-945F-00C04FB984F9},CN=Policies,CN=System,DC=example,DC=org',
                path: '\\\\example.org\\sysvol\\example.org\\Policies\\{6AC1786C-016F-11D2-945F-00C04FB984F9}',
            ),
            new GpoSummary(
                name: '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
                displayName: 'redirections',
                versionNumber: 0,
                dn: 'CN={AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE},CN=Policies,CN=System,DC=example,DC=org',
                path: '\\\\example.org\\sysvol\\example.org\\Policies\\{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
            ),
        ]);
    }

    // =========================================================================
    // AC5.1 — Page listing
    // =========================================================================

    #[Test]
    public function it_renders_listing_page_with_200_for_admin(): void
    {
        $admin = $this->makeAdmin('admin-render-200');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        Livewire::test('pages::app.gpo.index')
            ->assertStatus(200)
            ->assertSee('Gestion des GPOs');
    }

    #[Test]
    public function it_returns_403_without_server_admin_permission(): void
    {
        $user = $this->makeUser('user-403-listing');
        $this->actingAs($user);

        Livewire::test('pages::app.gpo.index')
            ->assertStatus(403);
    }

    #[Test]
    public function it_displays_gpos_from_service(): void
    {
        $admin = $this->makeAdmin('admin-gpos-display');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        Livewire::test('pages::app.gpo.index')
            ->assertSee('Default Domain Policy')
            ->assertSee('Default Domain Controllers Policy')
            ->assertSee('redirections');
    }

    #[Test]
    public function it_filters_by_search_reducing_visible_rows(): void
    {
        $admin = $this->makeAdmin('admin-search-filter');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        Livewire::test('pages::app.gpo.index')
            ->set('search', 'redirections')
            ->assertSee('redirections')
            ->assertDontSee('Default Domain Policy');
    }

    #[Test]
    public function it_filters_active_gpos_by_version_greater_than_zero(): void
    {
        $admin = $this->makeAdmin('admin-active-filter');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        Livewire::test('pages::app.gpo.index')
            ->set('statusFilter', 'active')
            ->assertSee('Default Domain Policy')      // version=65539 > 0
            ->assertSee('Default Domain Controllers Policy')  // version=12 > 0
            ->assertDontSee('redirections');           // version=0
    }

    #[Test]
    public function it_filters_inactive_gpos_by_version_zero(): void
    {
        $admin = $this->makeAdmin('admin-inactive-filter');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        Livewire::test('pages::app.gpo.index')
            ->set('statusFilter', 'inactive')
            ->assertSee('redirections')                // version=0
            ->assertDontSee('Default Domain Policy');  // version=65539
    }

    #[Test]
    public function it_sorts_gpos_by_display_name_asc_by_default(): void
    {
        $admin = $this->makeAdmin('admin-sort-asc');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        $component = Livewire::test('pages::app.gpo.index');
        $component->assertSet('sortBy', 'displayName');
        $component->assertSet('sortDirection', 'asc');
    }

    #[Test]
    public function it_reinvokes_service_on_refresh(): void
    {
        $admin = $this->makeAdmin('admin-refresh');
        $this->actingAs($admin);

        // Le builder ne contraint pas le nombre d'appels, mais on l'enrichit
        // ad hoc pour exiger 2 appels (mount + refresh).
        $fake = FakesGpoService::make();
        $fake->mock()->shouldReceive('list')->twice()->andReturn($this->makeGpoCollection());
        $fake->bind($this->app);

        Livewire::test('pages::app.gpo.index')
            ->call('refresh');
    }

    #[Test]
    public function it_shows_error_toast_when_service_throws(): void
    {
        $admin = $this->makeAdmin('admin-error-toast');
        $this->actingAs($admin);

        FakesGpoService::make()
            ->withListThrowing(new \RuntimeException('samba-tool unavailable'))
            ->bind($this->app);

        Livewire::test('pages::app.gpo.index')
            ->assertSet('hasError', true)
            ->assertSet('gpos', []);
    }

    #[Test]
    public function it_shows_empty_state_when_collection_is_empty(): void
    {
        $admin = $this->makeAdmin('admin-empty-state');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos(collect([]))->bind($this->app);

        Livewire::test('pages::app.gpo.index')
            ->assertSet('totalGpos', 0)
            ->assertSee('Aucune GPO');
    }

    // =========================================================================
    // Fix #4 — méthode clearFilters() reset les deux filtres
    // =========================================================================

    #[Test]
    public function it_clears_both_search_and_status_filter_when_clear_filters_called(): void
    {
        $admin = $this->makeAdmin('admin-clear-filters');
        $this->actingAs($admin);

        FakesGpoService::make()->withGpos($this->makeGpoCollection())->bind($this->app);

        Livewire::test('pages::app.gpo.index')
            ->set('search', 'redirections')
            ->set('statusFilter', 'active')
            ->assertSet('search', 'redirections')
            ->assertSet('statusFilter', 'active')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('statusFilter', 'all')
            ->assertSet('currentPage', 1);
    }
}
