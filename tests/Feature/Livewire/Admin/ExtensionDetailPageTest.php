<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\Extension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 54.1 (AC1) — page `/admin/extensions/{id}` : fiche d'une extension.
 *
 * Couvre : les champs issus du MANIFEST (version, description, scopes,
 * dépendances, cible, visibilité), le rendu PROPRE des listes vides, la 404 sur
 * identifiant inconnu et la garde `server.admin`.
 */
class ExtensionDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.extensions.[id].index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $admin = User::query()->create([
            'login' => 'extension-detail-admin',
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
        $extension = Extension::factory()->fromBundled()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])->assertForbidden();
    }

    #[Test]
    public function the_route_carries_the_server_admin_middleware(): void
    {
        $route = Route::getRoutes()->getByName('admin.extensions.show');

        self::assertNotNull($route, 'la route admin.extensions.show est déclarée');
        self::assertContains('can:server.admin', $route->gatherMiddleware());
        self::assertSame('[0-9]+', $route->wheres['id'] ?? null, 'identifiant borné à un entier');
    }

    #[Test]
    public function an_unknown_id_returns_404(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE, ['id' => 999_999])->assertNotFound();
    }

    // ── AC1 — la fiche affiche ce que dit le manifest ─────────────────────

    #[Test]
    public function displays_the_manifest_driven_fields(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()
            ->fromBundled()
            ->link('/doc')
            ->withManifestExtras(['profile', 'groups'], ['doc'])
            ->create([
                'name' => 'Documentation',
                'version' => '1.2.3',
                'publisher' => 'SambaEdu',
                'description' => 'Documentation publique SambaEdu.',
            ]);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->assertSee('Documentation')
            ->assertSee('1.2.3')
            ->assertSee('SambaEdu')
            ->assertSee('Documentation publique SambaEdu.')
            ->assertSee('/doc')
            ->assertSee('profile')
            ->assertSee('groups')
            ->assertSee('Lien')
            ->assertSee('Disponible')
            ->assertSee('Embarquée');
    }

    #[Test]
    public function empty_scopes_and_dependencies_render_cleanly(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()->fromBundled()->create(['name' => 'Documentation']);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->assertSee('Aucun scope demandé.')
            ->assertSee('Aucune dépendance.');
    }

    #[Test]
    public function displays_the_business_roles_of_the_manifest_visibility(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()->fromBundled()->create();
        $manifest = $extension->manifest;
        $manifest['visibility'] = ['roles' => ['admin', 'prof', 'eleve']];
        $extension->manifest = $manifest;
        $extension->save();

        $component = Livewire::test(self::PAGE, ['id' => $extension->id])->assertOk();

        self::assertSame(
            ['admin', 'prof', 'eleve'],
            $component->get('extension')['visibility_roles'],
        );
        $component->assertSee('eleve');
    }

    #[Test]
    public function an_integrated_extension_shows_its_state(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()->fromBundled()->integrated()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->assertSee('Intégrée');
    }
}
