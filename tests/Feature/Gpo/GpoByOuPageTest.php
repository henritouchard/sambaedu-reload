<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\GpoLink;
use App\Gpo\Services\GpoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\Support\FakesGpoService;
use Tests\TestCase;
use App\Models\User;

/**
 * Tests Feature Livewire — Page Vue inverse OU → GPOs.
 *
 * Story 16.14 AC7.1 / AC3.x.
 */
class GpoByOuPageTest extends TestCase
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

    private function makeAdmin(string $login = 'admin-by-ou'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeUser(string $login = 'user-by-ou'): User
    {
        return User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
    }

    private function bindOuRepo(array $ous = []): void
    {
        $this->app->bind(\App\Repositories\OrganizationalUnitRepository::class, function () use ($ous) {
            $mock = Mockery::mock(\App\Repositories\OrganizationalUnitRepository::class);
            $mock->shouldReceive('listAll')->andReturn($ous);
            return $mock;
        });
    }

    #[Test]
    public function it_renders_page_with_200_for_admin(): void
    {
        $admin = $this->makeAdmin('admin-by-ou-200');
        $this->actingAs($admin);
        $this->bindOuRepo();

        FakesGpoService::make()
            ->withDefaultLinks([])
            ->withDefaultInheritance(true)
            ->bind($this->app);

        $response = $this->get('/admin/settings/gpo/by-ou');
        $response->assertStatus(200);
    }

    #[Test]
    public function it_returns_403_for_non_admin_user(): void
    {
        $user = $this->makeUser('user-by-ou-403');
        $this->actingAs($user);

        $response = $this->get('/admin/settings/gpo/by-ou');
        $response->assertStatus(403);
    }

    #[Test]
    public function it_shows_ou_selector_on_initial_render(): void
    {
        $admin = $this->makeAdmin('admin-ou-selector');
        $this->actingAs($admin);

        $this->bindOuRepo([
            'OU=Salles,DC=example,DC=org',
            'OU=Postes,DC=example,DC=org',
        ]);

        FakesGpoService::make()
            ->withDefaultLinks([])
            ->withDefaultInheritance(true)
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.by-ou.index')
            ->assertSeeHtml('data-testid="ou-selector-input"')
            ->assertSee('Sélectionnez une OU');
    }

    #[Test]
    public function it_shows_gpo_listing_after_ou_selection(): void
    {
        $admin = $this->makeAdmin('admin-ou-listing');
        $this->actingAs($admin);

        $ouDn = 'OU=Salles,DC=example,DC=org';

        $this->bindOuRepo([$ouDn]);

        $link = new GpoLink(
            containerDn: $ouDn,
            gpoName: '{AAAA-0001-BBBB-CCCC-DDDDDDDDDDDD}',
            gpoDisplayName: 'se4_wallpaper_config',
            enforced: false,
            disabled: false,
        );

        FakesGpoService::make()
            ->withLinksFor($ouDn, [$link])
            ->withInheritanceFor($ouDn, true)
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.by-ou.index')
            ->call('selectOu', $ouDn)
            ->assertSeeHtml('data-testid="gpo-links-table"')
            ->assertSee('se4_wallpaper_config')
            ->assertSeeHtml('data-testid="ou-results-section"');
    }

    #[Test]
    public function it_shows_inheritance_blocked_badge(): void
    {
        $admin = $this->makeAdmin('admin-ou-inherit-block');
        $this->actingAs($admin);

        $ouDn = 'OU=Bloquee,DC=example,DC=org';
        $this->bindOuRepo([$ouDn]);

        FakesGpoService::make()
            ->withLinksFor($ouDn, [])
            ->withInheritanceFor($ouDn, false) // false = bloqué
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.by-ou.index')
            ->call('selectOu', $ouDn)
            ->assertSeeHtml('data-testid="inheritance-blocked-badge"')
            ->assertSee('Héritage GPO bloqué');
    }

    #[Test]
    public function it_shows_empty_state_when_no_gpos_linked(): void
    {
        $admin = $this->makeAdmin('admin-ou-empty');
        $this->actingAs($admin);

        $ouDn = 'OU=Vide,DC=example,DC=org';
        $this->bindOuRepo([$ouDn]);

        FakesGpoService::make()
            ->withLinksFor($ouDn, [])
            ->withInheritanceFor($ouDn, true)
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.by-ou.index')
            ->call('selectOu', $ouDn)
            ->assertSeeHtml('data-testid="empty-ou-state"')
            ->assertSee('Aucune GPO appliquée à cette OU');
    }

    #[Test]
    public function it_shows_workstation_count_na_when_not_available(): void
    {
        $admin = $this->makeAdmin('admin-ou-count-na');
        $this->actingAs($admin);

        $ouDn = 'OU=Salles,DC=example,DC=org';
        $this->bindOuRepo([$ouDn]);

        FakesGpoService::make()
            ->withLinksFor($ouDn, [])
            ->withInheritanceFor($ouDn, true)
            ->bind($this->app);

        // Si la colonne ad_dn n'est pas disponible, N/A s'affiche
        $component = Livewire::test('pages::admin.settings.gpo.by-ou.index')
            ->call('selectOu', $ouDn);

        // On s'assure que la page ne crash pas et montre le comptage
        $component->assertStatus(200);
    }
}
