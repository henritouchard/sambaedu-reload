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
 * Tests Feature — Liens profonds sections natives (Story 16.3a, AC4.4).
 *
 * Couvre les 6 tests AC4.4 :
 * 1. Chip success visible en listing quand displayName matche
 * 2. Cellule vide en listing quand pas de match
 * 3. CTA natif primaire sur page détail quand match
 * 4. N CTAs pour multi-match
 * 5. Paramètre ?from_gpo propagé dans les URLs CTA
 * 6. Bouton legacy en btn-ghost btn-xs + sous-texte "non recommandé" si match natif
 */
class GpoNativeSectionLinksTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    private const VALID_GUID = '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}';

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

    private function makeAdmin(string $login = 'admin-native-links'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeGpoSummary(string $displayName, string $name = self::VALID_GUID): GpoSummary
    {
        return new GpoSummary(
            name: $name,
            displayName: $displayName,
            versionNumber: 3,
            dn: 'CN=' . $name . ',CN=Policies,CN=System,DC=example,DC=org',
            path: '\\\\example.org\\sysvol\\example.org\\Policies\\' . $name,
        );
    }

    /** @return Collection<int, GpoSummary> */
    private function makeGpoCollection(string $displayName = 'firefox-policy', string $name = self::VALID_GUID): Collection
    {
        return collect([$this->makeGpoSummary($displayName, $name)]);
    }

    // =========================================================================
    // AC4.4 — Test 1 : chip success visible en listing quand match
    // =========================================================================

    #[Test]
    public function it_displays_native_chip_on_listing_when_displayname_matches(): void
    {
        $admin = $this->makeAdmin('admin-chip-match');
        $this->actingAs($admin);

        // GPO avec displayName matchant 'app-customizations' via 'firefox'
        FakesGpoService::make()
            ->withGpos($this->makeGpoCollection('firefox-policy'))
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.index')
            ->assertStatus(200)
            // Cible spécifiquement le chip natif via data-testid (review 16.3a #6).
            ->assertSee('data-testid="native-chip-single"', false)
            ->assertSee('1 section', false)
            // Et NON une cellule vide.
            ->assertDontSee('data-testid="native-empty"', false);
    }

    // =========================================================================
    // AC4.4 — Test 2 : cellule vide en listing quand pas de match
    // =========================================================================

    #[Test]
    public function it_does_not_display_native_chip_when_no_match(): void
    {
        $admin = $this->makeAdmin('admin-chip-no-match');
        $this->actingAs($admin);

        // GPO sans match heuristique
        FakesGpoService::make()
            ->withGpos($this->makeGpoCollection('gpo-custom-foo'))
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.index')
            ->assertStatus(200)
            ->assertSee('Édition native', false)
            // Cellule vide pour cette ligne (review 16.3a #6) — pas de chip natif.
            ->assertSee('data-testid="native-empty"', false)
            ->assertDontSee('data-testid="native-chip-single"', false)
            ->assertDontSee('data-testid="native-chip-multi"', false);
    }

    // =========================================================================
    // AC4.4 — Test 3 : CTA natif primaire sur page détail quand match
    // =========================================================================

    #[Test]
    public function it_displays_primary_native_cta_on_detail_page_when_match(): void
    {
        $admin = $this->makeAdmin('admin-cta-detail');
        $this->actingAs($admin);

        // GPO matchant 'wallpapers'
        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary('wallpaper-default'))
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            // CTA natif présent et identifié par data-testid (review 16.3a #6).
            ->assertSee('data-testid="native-cta-wallpapers"', false)
            // escape=true (default) : l'apostrophe est rendue via `{{ $link['label'] }}`
            // qui passe par `e()` → le HTML contient `&#039;`, on doit chercher la
            // version escapée. Avec `false`, le test cherche `'` littéral et fail.
            ->assertSee("Gérer les fonds d'écran")
            // Vérifie l'ordre : CTA natif AVANT le bouton legacy (assertSeeInOrder).
            ->assertSeeInOrder([
                'data-testid="native-cta-wallpapers"',
                'data-testid="legacy-edit-button"',
            ], false);
    }

    // =========================================================================
    // AC4.4 — Test 4 : N CTAs pour multi-match
    // =========================================================================

    #[Test]
    public function it_displays_n_ctas_for_multi_match(): void
    {
        $admin = $this->makeAdmin('admin-multi-match');
        $this->actingAs($admin);

        // GPO matchant 3 sections : firefox (app-customizations), wallpaper, roaming (profils-itinerants)
        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary('firefox-wallpaper-roaming'))
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        $rendered = Livewire::test('pages::admin.settings.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            // Les 3 CTAs natifs identifiés via data-testid (review 16.3a #6).
            ->assertSee('data-testid="native-cta-wallpapers"', false)
            ->assertSee('data-testid="native-cta-app-customizations"', false)
            ->assertSee('data-testid="native-cta-profils-itinerants"', false);

        // Assertion forte sur le nombre exact de CTAs natifs (review 16.3a #6).
        $html = $rendered->html();
        $this->assertSame(
            3,
            substr_count($html, 'data-testid="native-cta-'),
            'Le multi-match doit produire exactement 3 CTAs natifs',
        );
    }

    // =========================================================================
    // AC4.4 — Test 5 : paramètre ?from_gpo propagé dans les URLs CTA
    // =========================================================================

    #[Test]
    public function it_propagates_from_gpo_param_in_cta_urls(): void
    {
        $admin = $this->makeAdmin('admin-from-gpo-param');
        $this->actingAs($admin);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary('wallpaper-default'))
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        // Le GUID URL-encodé attendu dans le href du CTA.
        $encodedGuid = rawurlencode(self::VALID_GUID);

        Livewire::test('pages::admin.settings.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            // Vérification précise (review 16.3a #6) — URL CTA complète attendue.
            ->assertSee('/app/parc-settings/wallpapers?from_gpo=' . $encodedGuid, false);
    }

    // =========================================================================
    // AC4.4 — Test 6 : bouton legacy en btn-ghost btn-xs + sous-texte "non recommandé"
    // =========================================================================

    #[Test]
    public function it_displays_secondary_legacy_button_when_native_match(): void
    {
        $admin = $this->makeAdmin('admin-legacy-secondary');
        $this->actingAs($admin);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary('wallpaper-default'))
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        $rendered = Livewire::test('pages::admin.settings.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            // Bouton legacy identifié via data-testid (review 16.3a #6/#9).
            ->assertSee('data-testid="legacy-edit-button"', false)
            ->assertSee('Non recommandé', false)
            ->assertSee('Éditer dans l\'ancienne UI', false);

        // Vérifie spécifiquement que le bouton legacy porte les classes secondaires
        // (btn-ghost btn-xs) et NON pas primaires — sans coupler à une chaîne CSS générique.
        // L'ordre des attributs `class` vs `data-testid` n'est pas garanti (le blade
        // émet `class` avant `data-testid`), on couvre les 2 sens.
        $html = $rendered->html();
        $this->assertMatchesRegularExpression(
            '/(data-testid="legacy-edit-button"[^>]*class="[^"]*btn-ghost btn-xs)|(class="[^"]*btn-ghost btn-xs[^"]*"[^>]*data-testid="legacy-edit-button")/s',
            $html,
            'Le bouton legacy doit avoir les classes secondaires btn-ghost btn-xs',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(data-testid="legacy-edit-button"[^>]*class="[^"]*btn-primary btn-sm)|(class="[^"]*btn-primary btn-sm[^"]*"[^>]*data-testid="legacy-edit-button")/s',
            $html,
            'Le bouton legacy ne doit PAS être primaire sur une GPO matchant une section native',
        );
    }

    // =========================================================================
    // Bonus — Colonne "Édition native" visible dans l'en-tête du tableau listing
    // =========================================================================

    #[Test]
    public function it_shows_native_edit_column_header_in_listing(): void
    {
        $admin = $this->makeAdmin('admin-native-col-header');
        $this->actingAs($admin);

        FakesGpoService::make()
            ->withGpos($this->makeGpoCollection('firefox-policy'))
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.index')
            ->assertStatus(200)
            ->assertSee('Édition native', false);
    }

    // =========================================================================
    // Bonus — Multi-match en listing : chip "N sections" visible
    // =========================================================================

    #[Test]
    public function it_shows_multi_count_chip_for_multi_match_gpo_in_listing(): void
    {
        $admin = $this->makeAdmin('admin-multi-chip');
        $this->actingAs($admin);

        // GPO matchant firefox (app-customizations) + wallpaper → 2 sections
        FakesGpoService::make()
            ->withGpos($this->makeGpoCollection('firefox-wallpaper-conf'))
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.index')
            ->assertStatus(200)
            ->assertSee('sections', false);
    }
}
