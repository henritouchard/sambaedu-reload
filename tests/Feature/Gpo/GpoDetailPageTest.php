<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\GpoLink;
use App\Gpo\Dto\GpoSummary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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
    // AC5.4 / Fix #7 — Heuristique sections natives, validée sur la SFC réelle
    // =========================================================================

    /**
     * Reproduit le test unitaire supprimé (NativeSectionHeuristicsTest), mais
     * cette fois en faisant le rendu effectif de la SFC pour qu'aucune
     * divergence silencieuse de constante ne soit possible.
     *
     * @param list<string|null> $expectedNativeLabelsFragments
     */
    #[Test]
    #[DataProvider('nativeSectionsProvider')]
    public function it_renders_correct_native_section_for_display_name(
        string $displayName,
        ?string $expectedFragment,
    ): void {
        $admin = $this->makeAdmin('admin-native-' . substr(md5($displayName), 0, 8));
        $this->actingAs($admin);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary($displayName))
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        $component = Livewire::test('pages::app.gpo.[guid].index', ['guid' => self::VALID_GUID]);

        if ($expectedFragment === null) {
            $component->assertDontSee('Sections de cette GPO gérables nativement');
        } else {
            $component
                ->assertSee('Sections de cette GPO gérables nativement')
                ->assertSee($expectedFragment);
        }
    }

    /**
     * @return array<string, array{0:string, 1:?string}>
     */
    public static function nativeSectionsProvider(): array
    {
        return [
            'firefox-conf → app-customizations' => ['Firefox - Conf', 'Personnaliser les applications'],
            'wallpaper-default → wallpapers' => ['Wallpaper - default', 'fonds d\'écran'],
            'redirections → profils-itinerants' => ['Redirections - users', 'profils itinérants'],
            'shortcut-labo → shortcuts' => ['Shortcuts - labo', 'raccourcis'],
            'gpo sans match → aucun encart' => ['GPO sans match', null],
        ];
    }
}
