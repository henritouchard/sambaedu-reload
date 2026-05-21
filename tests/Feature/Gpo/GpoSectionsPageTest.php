<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\TestCase;
use App\Models\User;

/**
 * Tests Feature Livewire — Page catalogue sections natives GPO.
 *
 * Story 16.14 AC7.1 / AC4.x.
 */
class GpoSectionsPageTest extends TestCase
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

    private function makeAdmin(string $login = 'admin-sections'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeUser(string $login = 'user-sections'): User
    {
        return User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
    }

    #[Test]
    public function it_renders_sections_page_for_admin(): void
    {
        $admin = $this->makeAdmin('admin-sections-200');
        $this->actingAs($admin);

        // sambaedu.auth lit $_SESSION (non touché par actingAs), bypass requis
        // pour atteindre la page — iso-pattern WinePageTest (Story 16.9).
        $this->withoutMiddleware([
            \App\Http\Middleware\Auth\SambaEduAuth::class,
            \App\Http\Middleware\RequireAdminRights::class,
        ]);

        $response = $this->get('/admin/settings/gpo/sections');
        $response->assertStatus(200);
    }

    #[Test]
    public function it_returns_403_for_non_admin(): void
    {
        $user = $this->makeUser('user-sections-403');
        $this->actingAs($user);

        $this->withoutMiddleware([
            \App\Http\Middleware\Auth\SambaEduAuth::class,
            \App\Http\Middleware\RequireAdminRights::class,
        ]);

        $response = $this->get('/admin/settings/gpo/sections');
        $response->assertStatus(403);
    }

    #[Test]
    public function it_renders_all_5_section_cards(): void
    {
        $admin = $this->makeAdmin('admin-sections-cards');
        $this->actingAs($admin);

        Livewire::test('pages::admin.settings.gpo.sections.index')
            ->assertSeeHtml('data-testid="sections-grid"')
            ->assertSeeHtml('data-testid="section-card-profils-itinerants"')
            ->assertSeeHtml('data-testid="section-card-wallpapers"')
            ->assertSeeHtml('data-testid="section-card-app-customizations"')
            ->assertSeeHtml('data-testid="section-card-shortcuts"')
            ->assertSeeHtml('data-testid="section-card-wine"');
    }

    #[Test]
    public function it_renders_human_labels_for_each_section(): void
    {
        $admin = $this->makeAdmin('admin-sections-labels');
        $this->actingAs($admin);

        Livewire::test('pages::admin.settings.gpo.sections.index')
            ->assertSee('Profils itinérants')
            ->assertSee("Fonds d'écran")
            ->assertSee('Apps Wine (Linux)')
            ->assertSee('Raccourcis');
    }

    #[Test]
    public function it_displays_native_section_resolver_source_note(): void
    {
        $admin = $this->makeAdmin('admin-sections-note');
        $this->actingAs($admin);

        Livewire::test('pages::admin.settings.gpo.sections.index')
            ->assertSee('NativeSectionResolver');
    }

    #[Test]
    public function sections_property_is_loaded_from_mapping(): void
    {
        $admin = $this->makeAdmin('admin-sections-prop');
        $this->actingAs($admin);

        $component = Livewire::test('pages::admin.settings.gpo.sections.index');

        // La propriété sections doit contenir exactement 5 entrées (MAPPING de NativeSectionResolver)
        $sections = $component->get('sections');
        self::assertIsArray($sections);
        self::assertCount(5, $sections, 'Exactly 5 sections must be loaded from NativeSectionResolver::MAPPING.');
        self::assertArrayHasKey('wallpapers', $sections);
        self::assertArrayHasKey('wine', $sections);
    }
}
