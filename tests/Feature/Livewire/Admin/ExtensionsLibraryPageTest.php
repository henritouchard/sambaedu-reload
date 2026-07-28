<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\Extension;
use App\Models\ExtensionSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 54.1 (AC1) — page `/admin/extensions` : bibliothèque en lecture seule.
 *
 * Couvre : liste alimentée par le registre multi-sources (nom, type, éditeur,
 * source, état), état vide propre, et la garde `server.admin` (403 + middleware
 * de route).
 */
class ExtensionsLibraryPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.extensions.index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $admin = User::query()->create([
            'login' => 'extensions-admin',
            'role' => 'autre',
            'is_active' => true,
        ]);
        $this->actingAs($admin);
    }

    /** @param list<string> $abilities */
    private function grant(array $abilities): void
    {
        Gate::before(fn ($user, string $ability) => in_array($ability, $abilities, true) ? true : null);
    }

    // ── Sécurité ──────────────────────────────────────────────────────────

    #[Test]
    public function mount_is_forbidden_without_server_admin(): void
    {
        Livewire::test(self::PAGE)->assertForbidden();
    }

    #[Test]
    public function the_route_carries_the_server_admin_middleware(): void
    {
        // Defense-in-depth : la garde `mount()` ne remplace pas le middleware.
        $route = Route::getRoutes()->getByName('admin.extensions');

        self::assertNotNull($route, 'la route admin.extensions est déclarée');
        self::assertContains('can:server.admin', $route->gatherMiddleware());
        self::assertContains('sambaedu.admin', $route->gatherMiddleware());
    }

    // ── AC1 — la bibliothèque liste le registre ───────────────────────────

    #[Test]
    public function lists_extensions_with_name_publisher_source_and_state(): void
    {
        $this->grant(['server.admin']);

        $source = ExtensionSource::factory()->bundled()->create();
        Extension::factory()->create([
            'extension_source_id' => $source->id,
            'key' => 'doc',
            'name' => 'Documentation',
            'publisher' => 'SambaEdu',
        ]);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSee('Documentation')
            ->assertSee('SambaEdu')
            ->assertSee(ExtensionSource::NAME_BUNDLED)
            ->assertSee('Lien')          // libellé du type `link`
            ->assertSee('Disponible');   // libellé de l'état
    }

    #[Test]
    public function an_integrated_extension_is_displayed_as_such(): void
    {
        $this->grant(['server.admin']);

        Extension::factory()->fromBundled()->integrated()->create(['name' => 'Visio']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSee('Visio')
            ->assertSee('Intégrée');
    }

    #[Test]
    public function lists_extensions_coming_from_several_sources(): void
    {
        $this->grant(['server.admin']);

        $bundled = ExtensionSource::factory()->bundled()->create();
        $remote = ExtensionSource::factory()->remote()->create(['name' => 'Dépôt partenaire']);

        Extension::factory()->create(['extension_source_id' => $bundled->id, 'name' => 'Documentation']);
        Extension::factory()->create(['extension_source_id' => $remote->id, 'name' => 'Extension tierce']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSee('Documentation')
            ->assertSee('Extension tierce')
            ->assertSee(ExtensionSource::NAME_BUNDLED)
            ->assertSee('Dépôt partenaire');
    }

    #[Test]
    public function the_component_exposes_the_rows_and_the_integrated_count(): void
    {
        $this->grant(['server.admin']);

        Extension::factory()->fromBundled()->create(['name' => 'A']);
        Extension::factory()->fromBundled()->integrated()->create(['name' => 'B']);

        $component = Livewire::test(self::PAGE)->assertOk();

        self::assertCount(2, $component->get('extensions'));
        self::assertSame(1, $component->instance()->integratedCount());
    }

    #[Test]
    public function an_empty_registry_renders_a_clean_empty_state(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSee('Aucune extension')
            ->assertSet('extensions', []);
    }
}
