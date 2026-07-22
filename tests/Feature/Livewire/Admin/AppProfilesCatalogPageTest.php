<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\User;
use App\Observers\CapabilityProjectionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.7 (AC1/AC6) — page /admin/settings/app-profiles : catalogue des profils
 * applicatifs (liste, ajout, édition, activation/désactivation), violations du
 * garde-fou d'authoring remontées en erreurs (pas en 500).
 *
 * Le catalogue `roaming_app_profile` (Firefox/Thunderbird) est seedé + mis à
 * niveau par RefreshDatabase (migrations 36.5 + 36.7).
 */
class AppProfilesCatalogPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.settings.app-profiles.index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $admin = User::query()->create(['login' => 'app-profiles-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);

        // L'observer d'authoring est enregistré HORS environnement de test
        // (AppServiceProvider) : on le branche ICI pour que l'écriture du catalogue
        // via la page passe réellement par l'AppProfileAuthoringGuard (AC1).
        CapabilityProjection::observe(CapabilityProjectionObserver::class);
    }

    protected function tearDown(): void
    {
        // Ne pas fuiter l'observer dans les autres classes (même process).
        CapabilityProjection::flushEventListeners();
        parent::tearDown();
    }

    private function grant(array $abilities): void
    {
        Gate::before(fn ($user, string $ability) => in_array($ability, $abilities, true) ? true : null);
    }

    /** Les apps du catalogue (spec de la projection). */
    private function catalogApps(): array
    {
        $projection = CapabilityProjection::query()
            ->whereHas('capability', fn ($q) => $q->where('key', 'roaming_app_profile'))
            ->where('mechanism', 'app_profile')
            ->first();

        return $projection->spec['apps'];
    }

    #[Test]
    public function mount_is_forbidden_without_server_admin(): void
    {
        // Aucun grant → gate server.admin refuse (403).
        Livewire::test(self::PAGE)->assertForbidden();
    }

    #[Test]
    public function lists_seeded_catalog_entries(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSee('firefox')
            ->assertSee('thunderbird')
            ->assertSet('catalogExists', true);
    }

    #[Test]
    public function adds_a_valid_entry_to_the_catalog(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->call('openCreate')
            ->set('app', 'chrome')
            ->set('link', 'AppData\\Local\\Google\\Chrome\\managed.default')
            ->set('server', '.config\\chrome\\managed.default')
            ->set('profileName', 'managed.default')
            ->set('enabled', true)
            ->call('save')
            ->assertSet('isModalOpen', false);

        $apps = array_column($this->catalogApps(), 'app');
        self::assertContains('chrome', $apps);
    }

    #[Test]
    public function guard_violation_surfaces_as_error_not_500(): void
    {
        $this->grant(['server.admin']);

        // Radical `sambaedu` interdit (piège n°1) → violation du guard, PAS un 500.
        Livewire::test(self::PAGE)
            ->call('openCreate')
            ->set('app', 'evil')
            ->set('link', 'AppData\\Roaming\\sambaedu.default')
            ->set('server', '.evil\\sambaedu.default')
            ->set('profileName', 'sambaedu.default')
            ->call('save')
            ->assertOk()
            ->assertSet('isModalOpen', true); // reste ouverte : rien persisté.

        self::assertNotContains('evil', array_column($this->catalogApps(), 'app'));
    }

    #[Test]
    public function disables_an_entry_without_removing_it(): void
    {
        $this->grant(['server.admin']);

        // firefox est à l'index 0 du catalogue seedé.
        Livewire::test(self::PAGE)->call('toggleEnabled', 0);

        $apps = $this->catalogApps();
        self::assertSame('firefox', $apps[0]['app'], 'entrée conservée (off réel, pas de suppression)');
        self::assertFalse($apps[0]['enabled'], 'entrée désactivée');
    }

    #[Test]
    public function edits_an_existing_entry(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->call('openEdit', 0)
            ->assertSet('app', 'firefox')
            ->set('cacheLocal', 'cacheFirefoxV2')
            ->call('save')
            ->assertSet('isModalOpen', false);

        self::assertSame('cacheFirefoxV2', $this->catalogApps()[0]['cache_local']);
    }

    #[Test]
    public function required_fields_are_validated(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->call('openCreate')
            ->set('app', '')
            ->call('save')
            ->assertHasErrors(['app' => 'required']);
    }
}
