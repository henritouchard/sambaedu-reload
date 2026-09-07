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
 * Tests Feature — Liens profonds sections natives.
 *
 * Couvre les 6 tests :
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

    // Les tests 1 et 2 (chip natif / cellule vide dans le LISTING) ont été
    // retirés avec `pages::admin.settings.gpo.index`, remplacé par l'onglet
    // « GPO » de /admin/settings/migration — lequel affiche l'effectivité réelle
    // et non la colonne « Édition native ». La résolution des sections natives
    // reste couverte par les tests de la page DÉTAIL ci-dessous et par
    // tests/Unit/Gpo/NativeSectionResolverTest.

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
            // CTA natif présent et identifié par data-testid.
            ->assertSee('data-testid="native-cta-wallpapers"', false)
            // escape=true (default) : l'apostrophe est rendue via `{{ $link['label'] }}`
            // qui passe par `e()` → le HTML contient `&#039;`, on doit chercher la
            // version escapée. Avec `false`, le test cherche `'` littéral et fail.
            ->assertSee("Gérer les fonds d'écran");
    }

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
            // Les 3 CTAs natifs identifiés via data-testid.
            ->assertSee('data-testid="native-cta-wallpapers"', false)
            ->assertSee('data-testid="native-cta-app-customizations"', false)
            ->assertSee('data-testid="native-cta-profils-itinerants"', false);

        // Assertion forte sur le nombre exact de CTAs natifs.
        $html = $rendered->html();
        $this->assertSame(
            3,
            substr_count($html, 'data-testid="native-cta-'),
            'Le multi-match doit produire exactement 3 CTAs natifs',
        );
    }

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
            // URL CTA complète attendue.
            ->assertSee('/app/parc-settings/wallpapers?from_gpo=' . $encodedGuid, false);
    }

}
