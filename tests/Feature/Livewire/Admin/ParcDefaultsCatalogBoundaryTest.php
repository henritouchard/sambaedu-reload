<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\Application;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 31.1 (correction post-review #M2) — bornage du DÉFAUT DIFFUSÉ d'apps
 * (`is_parc_default`, onglet « Applications » de /admin/settings/parc-defaults)
 * au catalogue applicatif amont.
 *
 * Passer une app en défaut parc l'installe sur TOUS les postes (couche Broadcast
 * 27.17) : c'est l'install la plus large, donc bornée par FR5. Refus serveur
 * (`is_parc_default` inchangé) pour une app hors catalogue ; consultation filtrée ;
 * standalone/retrait non bornés (NFR3 / D4).
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`. R3 : aucun « central ».
 */
class ParcDefaultsCatalogBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const APPS_TAB = 'pages::admin.settings.parc-defaults._partials.apps-tab';

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        WorkstationGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        Mockery::close();
        parent::tearDown();
    }

    /** Admin (server.admin autorisé via before-hook ciblé). */
    private function actAsAdmin(): void
    {
        $user = Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class,
        );
        $user->shouldReceive('can')->andReturn(true);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $this->actingAs($user);
        Gate::before(fn ($u, string $ability) => $ability === 'server.admin' ? true : null);
    }

    private function makeApp(string $appId, bool $parcDefault = false): Application
    {
        return Application::create([
            'app_id' => $appId,
            'name' => ucfirst($appId),
            'is_parc_default' => $parcDefault,
        ]);
    }

    private function activeContractWithCatalog(array $appKeys): void
    {
        $contract = ControlHubContract::factory()->create();
        foreach ($appKeys as $key) {
            ControlHubContractCatalogApp::factory()->create([
                'controlhub_contract_id' => $contract->id,
                'app_key' => $key,
            ]);
        }
    }

    #[Test]
    public function set_parc_default_is_refused_for_app_out_of_catalog(): void
    {
        $this->actAsAdmin();
        $this->activeContractWithCatalog(['firefox']);
        $chrome = $this->makeApp('chrome'); // hors catalogue

        \Livewire\Livewire::test(self::APPS_TAB)
            ->call('setParcDefault', $chrome->id, true)
            ->assertDispatched('toastMagic', fn ($event, $params): bool => ($params['status'] ?? null) === 'error');

        self::assertFalse(
            (bool) Application::query()->find($chrome->id)->is_parc_default,
            '#M2 : une app hors catalogue ne peut pas devenir défaut parc',
        );
    }

    #[Test]
    public function set_parc_default_succeeds_for_app_in_catalog(): void
    {
        $this->actAsAdmin();
        $this->activeContractWithCatalog(['firefox']);
        $firefox = $this->makeApp('firefox');

        \Livewire\Livewire::test(self::APPS_TAB)->call('setParcDefault', $firefox->id, true);

        self::assertTrue((bool) Application::query()->find($firefox->id)->is_parc_default);
    }

    #[Test]
    public function removing_an_out_of_catalog_default_is_always_allowed(): void
    {
        // D4 : le retrait n'est jamais borné (sinon on bloquerait le nettoyage d'un
        // défaut posé avant le contrat).
        $this->actAsAdmin();
        $this->activeContractWithCatalog(['firefox']);
        $chrome = $this->makeApp('chrome', parcDefault: true); // défaut hérité hors catalogue

        \Livewire\Livewire::test(self::APPS_TAB)->call('setParcDefault', $chrome->id, false);

        self::assertFalse((bool) Application::query()->find($chrome->id)->is_parc_default);
    }

    #[Test]
    public function standalone_allows_any_app_as_parc_default(): void
    {
        // NFR3 : sans contrat actif, comportement 27.17 inchangé.
        $this->actAsAdmin();
        $chrome = $this->makeApp('chrome');

        \Livewire\Livewire::test(self::APPS_TAB)->call('setParcDefault', $chrome->id, true);

        self::assertTrue((bool) Application::query()->find($chrome->id)->is_parc_default);
    }

    // NB : le filtrage de consultation (searchResults → `inUpstreamCatalog()`) n'est pas
    // testé ici car le scope `search()` utilise `ILIKE` (PG-only, KO en SQLite). Le scope
    // `inUpstreamCatalog` qui réalise le bornage est couvert par
    // UpstreamCatalogBoundaryTest::ac1_scope_only_returns_apps_in_catalog.
}
