<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\GpoLink;
use App\Gpo\Dto\GpoSummary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\Support\FakesGpoService;
use Tests\TestCase;
use App\Models\User;

/**
 * Tests Feature Livewire — Page détail GPO `/app/gpo/{guid}` (Story 16.2, AC5.2).
 *
 * Stratégie mock : helper {@see FakesGpoService} → binding container Laravel.
 */
class GpoDetailPageTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    private const VALID_GUID = '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}';
    private const DN_1 = 'OU=Salles,DC=example,DC=org';
    private const DN_2 = 'OU=Profs,OU=Salles,DC=example,DC=org';

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

    private function makeAdmin(string $login = 'admin-detail-test'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeUser(string $login = 'user-detail-test'): User
    {
        return User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
    }

    private function makeGpoSummary(string $displayName = 'redirections', string $name = self::VALID_GUID): GpoSummary
    {
        return new GpoSummary(
            name: $name,
            displayName: $displayName,
            versionNumber: 3,
            dn: 'CN=' . $name . ',CN=Policies,CN=System,DC=example,DC=org',
            path: '\\\\example.org\\sysvol\\example.org\\Policies\\' . $name,
        );
    }

    // =========================================================================
    // AC5.2 — Page détail
    // =========================================================================

    #[Test]
    public function it_renders_detail_page_with_200_for_valid_existing_guid(): void
    {
        $admin = $this->makeAdmin('admin-detail-200');
        $this->actingAs($admin);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary())
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        Livewire::test('pages::app.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            ->assertSee('redirections');
    }

    #[Test]
    public function it_returns_404_for_malformed_guid_without_calling_service(): void
    {
        $admin = $this->makeAdmin('admin-detail-malformed');
        $this->actingAs($admin);

        FakesGpoService::make()->expectNoCalls()->bind($this->app);

        Livewire::test('pages::app.gpo.[guid].index', ['guid' => 'INJECTION_ATTACK'])
            ->assertStatus(404);
    }

    #[Test]
    public function it_accepts_guid_without_braces_and_normalizes(): void
    {
        // Fix #9 : la regex de route accepte le GUID avec ou sans accolades
        // (URLs partagées plus tolérantes), et mount() normalise vers le format
        // canonique avec accolades avant tout appel samba-tool.
        $admin = $this->makeAdmin('admin-detail-nobrace-norm');
        $this->actingAs($admin);

        // Le service est appelé avec le GUID NORMALISÉ (avec accolades).
        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary())
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        $guidWithoutBraces = 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE';
        Livewire::test('pages::app.gpo.[guid].index', ['guid' => $guidWithoutBraces])
            ->assertStatus(200)
            ->assertSet('guid', self::VALID_GUID); // normalisé avec accolades
    }

    #[Test]
    public function it_returns_404_for_valid_format_guid_when_service_returns_null(): void
    {
        $admin = $this->makeAdmin('admin-detail-notfound');
        $this->actingAs($admin);

        // Fix #10 : abort(404) sorti du try/catch — get() retourne null
        // doit produire un 404 propre, sans listContainers appelé.
        $fake = FakesGpoService::make()->withGpo(self::VALID_GUID, null);
        $fake->mock()->shouldNotReceive('listContainers');
        $fake->bind($this->app);

        Livewire::test('pages::app.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(404);
    }

    #[Test]
    public function it_shows_error_state_when_get_throws_runtime_exception(): void
    {
        // Fix #10 : si get() lève une exception réelle (samba-tool down),
        // la page reste navigable (status 200), affiche un bandeau d'erreur,
        // et ne masque PAS l'erreur derrière un 404 trompeur (AC2.7).
        $admin = $this->makeAdmin('admin-detail-svc-down');
        $this->actingAs($admin);

        FakesGpoService::make()
            ->withGetThrowing(self::VALID_GUID, new \RuntimeException('samba-tool unreachable'))
            ->bind($this->app);

        Livewire::test('pages::app.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            ->assertSet('hasError', true);
    }

    #[Test]
    public function it_returns_403_without_server_admin_permission(): void
    {
        $user = $this->makeUser('user-detail-403');
        $this->actingAs($user);

        Livewire::test('pages::app.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(403);
    }

    #[Test]
    public function it_displays_containers_linked_to_gpo(): void
    {
        $admin = $this->makeAdmin('admin-containers');
        $this->actingAs($admin);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary())
            ->withContainersFor(self::VALID_GUID, [self::DN_1, self::DN_2, 'DC=example,DC=org'])
            ->withDefaultLinks([])
            ->withDefaultInheritance(true)
            ->bind($this->app);

        Livewire::test('pages::app.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertSet('containers', [self::DN_1, self::DN_2, 'DC=example,DC=org'])
            ->assertSee('OU=Salles');
    }

    #[Test]
    public function it_displays_gpo_links_per_container(): void
    {
        $admin = $this->makeAdmin('admin-links');
        $this->actingAs($admin);

        $link1 = new GpoLink(
            containerDn: self::DN_1,
            gpoName: self::VALID_GUID,
            gpoDisplayName: 'redirections',
            enforced: false,
            disabled: false,
            optionsRaw: 0,
        );
        $link2 = new GpoLink(
            containerDn: self::DN_1,
            gpoName: '{31B2F340-016D-11D2-945F-00C04FB984F9}',
            gpoDisplayName: 'Default Domain Policy',
            enforced: true,
            disabled: false,
            optionsRaw: 2,
        );

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary())
            ->withContainersFor(self::VALID_GUID, [self::DN_1])
            ->withLinksFor(self::DN_1, [$link1, $link2])
            ->withInheritanceFor(self::DN_1, true)
            ->bind($this->app);

        Livewire::test('pages::app.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertSee('redirections')
            ->assertSee('Default Domain Policy');
    }

    #[Test]
    public function it_displays_inheritance_status_per_container(): void
    {
        $admin = $this->makeAdmin('admin-inheritance');
        $this->actingAs($admin);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary())
            ->withContainersFor(self::VALID_GUID, [self::DN_1])
            ->withLinksFor(self::DN_1, [])
            ->withInheritanceFor(self::DN_1, false) // bloqué
            ->bind($this->app);

        Livewire::test('pages::app.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertSet('inheritanceByContainer', [self::DN_1 => false])
            ->assertSee('Héritage bloqué');
    }

    #[Test]
    public function it_shows_native_sections_encart_when_display_name_matches_heuristic(): void
    {
        $admin = $this->makeAdmin('admin-heuristic');
        $this->actingAs($admin);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary('redirections')) // matche profils-itinerants
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        Livewire::test('pages::app.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertSee('Sections de cette GPO gérables nativement')
            ->assertSee('profils-itinerants');
    }

    #[Test]
    public function it_shows_edit_legacy_button_with_target_blank(): void
    {
        $admin = $this->makeAdmin('admin-legacy-button');
        $this->actingAs($admin);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary('redirections'))
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        Livewire::test('pages::app.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertSee('Éditer dans l\'ancienne UI')
            ->assertSee('target="_blank"', false)
            ->assertSee('gestion_gpo.php', false);
    }

    // =========================================================================
    // AC1.4 / Story 16.3a — Test smoke : SFC utilise bien NativeSectionResolver
    // =========================================================================

    /**
     * Test smoke : la page détail utilise NativeSectionResolver (AC1.4).
     *
     * Vérifie que la SFC câble correctement le resolver — si nativeSectionLinks()
     * retourne des matches, les CTAs natifs primaires sont bien présents dans
     * le rendu (cf. AC2.1). La couverture exhaustive des patterns est dans
     * NativeSectionResolverTest (tests Unit purs).
     *
     * Migre la couverture du dataProvider 5-cas supprimé (Story 16.2 AC5.4 / Fix #7).
     */
    #[Test]
    public function it_uses_native_section_resolver_for_links(): void
    {
        $admin = $this->makeAdmin('admin-resolver-smoke');
        $this->actingAs($admin);

        // GPO dont le displayName matche 'profils-itinerants' via 'redirections'
        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary('redirections-test'))
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        $rendered = Livewire::test('pages::app.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            // L'encart "sections natives" est affiché
            ->assertSee('Sections de cette GPO gérables nativement')
            // Le libellé de la section profils-itinerants est visible
            ->assertSee('profils itinérants')
            // CTA natif identifié par data-testid (review 16.3a #6).
            ->assertSee('data-testid="native-cta-profils-itinerants"', false)
            ->assertSee('Gérer les profils itinérants nativement')
            // L'URL contient le paramètre from_gpo (AC2.3 — encart enrichi)
            ->assertSee('from_gpo=', false)
            ->assertSee('Non recommandé', false);

        // Bouton legacy dégradé — assertion ciblée sur les classes du bouton identifié.
        $html = $rendered->html();
        $this->assertMatchesRegularExpression(
            '/data-testid="legacy-edit-button"[^>]*class="[^"]*btn-ghost btn-xs/',
            $html,
            'Bouton legacy doit être dégradé (btn-ghost btn-xs) sur une GPO matchant une section native',
        );
    }

    /**
     * Test smoke no-match : GPO sans section native → bouton legacy primaire.
     */
    #[Test]
    public function it_keeps_legacy_button_primary_when_no_native_match(): void
    {
        $admin = $this->makeAdmin('admin-no-native-smoke');
        $this->actingAs($admin);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary('default-domain-policy'))
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        $rendered = Livewire::test('pages::app.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            ->assertDontSee('Sections de cette GPO gérables nativement')
            ->assertSee('data-testid="legacy-edit-button"', false)
            ->assertDontSee('Non recommandé');

        // Bouton legacy primaire — assertion ciblée sur le bouton identifié.
        $html = $rendered->html();
        $this->assertMatchesRegularExpression(
            '/data-testid="legacy-edit-button"[^>]*class="[^"]*btn-primary btn-sm/',
            $html,
            'Bouton legacy doit rester primaire (btn-primary btn-sm) sur une GPO sans section native',
        );
    }
}
