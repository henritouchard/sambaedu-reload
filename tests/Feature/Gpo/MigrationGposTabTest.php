<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Http\Middleware\Auth\SambaEduAuth;
use App\Http\Middleware\RequireAdminRights;
use App\Models\User;
use App\Services\Gpo\GpoEffectivenessResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\TestCase;

/**
 * Onglet « GPO » de /admin/settings/migration — écran d'audit de l'extinction.
 *
 * Il remplace le listing `/admin/settings/gpo`, dont le badge « Active » valait
 * `versionNumber > 0` : sur un parc neutralisé par blocage d'héritage, 14 GPO
 * inertes s'affichaient en vert. Ces tests verrouillent la sémantique corrigée
 * ET la propriété de sûreté qui va avec — annuaire injoignable doit produire une
 * ERREUR visible, jamais une liste vide qui se lirait « plus aucune GPO ».
 */
class MigrationGposTabTest extends TestCase
{
    use BootstrapsSpatieTables;
    use DatabaseTransactions;

    private const COMPONENT = 'pages::admin.settings.migration._partials.gpos-tab';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        if (! config('app.key')) {
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

    private function actingAsAdmin(string $login): User
    {
        $user = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $user->givePermissionTo('server.admin');
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  list<array<string, mixed>>  $gpos
     */
    private function bindResolver(array $gpos, bool $available = true, ?string $error = null): void
    {
        $resolver = Mockery::mock(GpoEffectivenessResolver::class);
        $resolver->shouldReceive('resolve')->andReturn([
            'available' => $available,
            'error' => $error,
            'perimeters' => [
                GpoEffectivenessResolver::PERIMETER_COMPUTERS => [
                    'dn' => 'ou=computers,dc=localdev,dc=fr',
                    'chain' => ['ou=computers,dc=localdev,dc=fr', 'dc=localdev,dc=fr'],
                    'blockedNodes' => ['ou=computers,dc=localdev,dc=fr'],
                ],
                GpoEffectivenessResolver::PERIMETER_PEOPLE => [
                    'dn' => 'ou=Utilisateurs,dc=localdev,dc=fr',
                    'chain' => ['ou=utilisateurs,dc=localdev,dc=fr', 'dc=localdev,dc=fr'],
                    'blockedNodes' => [],
                ],
            ],
            'gpos' => $gpos,
        ]);
        $this->app->instance(GpoEffectivenessResolver::class, $resolver);
    }

    /**
     * @return array<string, mixed>
     */
    private function gpo(string $name, string $computers, ?string $people = null, bool $enforced = false): array
    {
        $verdict = static fn (string $status): array => [
            'status' => $status,
            'detail' => 'motif de test',
            'holderDn' => 'dc=localdev,dc=fr',
            'enforced' => $enforced,
        ];

        return [
            'guid' => '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
            'displayName' => $name,
            'versionNumber' => 9,
            'statuses' => [
                GpoEffectivenessResolver::PERIMETER_COMPUTERS => $verdict($computers),
                GpoEffectivenessResolver::PERIMETER_PEOPLE => $verdict($people ?? $computers),
            ],
        ];
    }

    #[Test]
    public function it_reports_the_real_effective_count_not_the_edited_once_count(): void
    {
        $this->actingAsAdmin('gpos-tab-counts');

        // Situation réelle du parc : 1 seule GPO atteint les postes, les autres
        // sont liées à la racine mais neutralisées par blocage d'héritage.
        $this->bindResolver([
            $this->gpo('SE_agent_bootstrap', GpoEffectivenessResolver::STATUS_EFFECTIVE),
            $this->gpo('wpkg', GpoEffectivenessResolver::STATUS_NEUTRALIZED),
            $this->gpo('lecteurs reseau', GpoEffectivenessResolver::STATUS_NEUTRALIZED),
        ]);

        Livewire::test(self::COMPONENT)
            ->assertStatus(200)
            ->assertSee('Effective')
            ->assertSee('Neutralisée')
            ->assertSee('SE_agent_bootstrap')
            ->assertSeeHtml('data-testid="gpos-effective-computers"')
            ->assertSeeHtml('data-testid="gpos-effective-people"');
    }

    #[Test]
    public function a_gpo_neutralized_on_machines_can_still_be_effective_on_users(): void
    {
        $this->actingAsAdmin('gpos-tab-two-perimeters');

        // Le piège opérationnel : bloquer l'héritage sur l'OU des postes éteint
        // la moitié MACHINE, pas la moitié UTILISATEUR. L'écran doit montrer les
        // deux, sans quoi « Neutralisée » serait faussement rassurant.
        $this->bindResolver([
            $this->gpo(
                'redirections',
                GpoEffectivenessResolver::STATUS_NEUTRALIZED,
                GpoEffectivenessResolver::STATUS_EFFECTIVE,
            ),
        ]);

        Livewire::test(self::COMPONENT)
            ->assertStatus(200)
            ->assertSeeHtml('data-testid="gpo-cell-computers-neutralized"')
            ->assertSeeHtml('data-testid="gpo-cell-people-effective"')
            // Le compteur utilisateur doit refléter l'exposition réelle.
            ->assertSee('Utilisateurs');
    }

    #[Test]
    public function it_warns_when_a_perimeter_has_no_inheritance_blocking(): void
    {
        $this->actingAsAdmin('gpos-tab-no-block');
        $this->bindResolver([$this->gpo('Bureau', GpoEffectivenessResolver::STATUS_EFFECTIVE)]);

        // L'OU des comptes n'a aucun blocage dans le double lié plus haut.
        Livewire::test(self::COMPONENT)
            ->assertStatus(200)
            // Texte littéral dans la vue : pas d'échappement Blade sur l'apostrophe.
            ->assertSee('aucun blocage d\'héritage', false);
    }

    #[Test]
    public function an_unreachable_directory_shows_an_error_and_never_an_empty_list(): void
    {
        $this->actingAsAdmin('gpos-tab-unavailable');
        $this->bindResolver([], available: false, error: 'Annuaire injoignable : connexion refusée');

        Livewire::test(self::COMPONENT)
            ->assertStatus(200)
            ->assertSeeHtml('data-testid="gpos-unavailable"')
            ->assertSee('Effectivité indéterminée')
            ->assertSee('connexion refusée')
            // Surtout PAS le tableau : une liste vide se lirait « tout est éteint ».
            ->assertDontSeeHtml('data-testid="gpos-table"')
            ->assertDontSee('Aucune GPO dans le domaine.');
    }

    #[Test]
    public function it_flags_an_enforced_link_which_traverses_inheritance_blocking(): void
    {
        $this->actingAsAdmin('gpos-tab-enforced');
        $this->bindResolver([
            $this->gpo('applications', GpoEffectivenessResolver::STATUS_EFFECTIVE, enforced: true),
        ]);

        Livewire::test(self::COMPONENT)
            ->assertStatus(200)
            ->assertSee('Enforced');
    }

    #[Test]
    public function it_distinguishes_out_of_scope_from_orphaned(): void
    {
        $this->actingAsAdmin('gpos-tab-scope');
        $this->bindResolver([
            $this->gpo('Default Domain Controllers Policy', GpoEffectivenessResolver::STATUS_OUT_OF_SCOPE),
        ]);

        // AD fédéré : on ne prétend pas savoir si elle est liée ailleurs.
        Livewire::test(self::COMPONENT)
            ->assertStatus(200)
            ->assertSee('Hors périmètre')
            ->assertDontSee('Orpheline');
    }

    #[Test]
    public function the_migration_page_exposes_the_gpos_tab(): void
    {
        $this->actingAsAdmin('gpos-tab-host');
        $this->bindResolver([$this->gpo('SE_agent_bootstrap', GpoEffectivenessResolver::STATUS_EFFECTIVE)]);

        Livewire::test('pages::admin.settings.migration.index', ['tab' => 'gpos'])
            ->assertStatus(200)
            ->assertSeeHtml('data-testid="tab-gpos"');
    }

    #[Test]
    public function the_former_listing_url_redirects_to_the_migration_tab(): void
    {
        $this->actingAsAdmin('gpos-tab-redirect');

        $this->withoutMiddleware([SambaEduAuth::class, RequireAdminRights::class])
            ->get('/admin/settings/gpo')
            ->assertRedirect('/admin/settings/migration?tab=gpos');
    }
}
