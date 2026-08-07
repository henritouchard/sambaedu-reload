<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Page hôte /admin/settings/files — trois onglets « Personnels et partagés »
 * (politique), « Lecteurs réseaux » (gestion des partages embarquée) et « Profils
 * itinérants ». Couvre la logique d'onglets et le rendu du composant partages
 * embarqué selon la permission.
 *
 * « Profils itinérants » est couvert ICI en tant qu'ONGLET ATTEIGNABLE :
 * `AdminSettingsProfilsItinerantsTabTest` monte le composant en direct et restait
 * donc vert alors que la redirection `/admin/settings/profils-itinerants` pointait
 * vers une clé d'onglet inexistante — l'UI était injoignable sans qu'un test le voie.
 * D'où l'invariant `the_tabs_targeted_by_the_legacy_redirects_survive_mount()`.
 */
class FilePolicyPageTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'pages::admin.settings.files.index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $admin = User::query()->create(['login' => 'files-host-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);
    }

    private function grant(array $abilities): void
    {
        Gate::before(fn ($user, string $ability) => in_array($ability, $abilities, true) ? true : null);
    }

    #[Test]
    public function it_opens_on_the_personnels_partages_tab(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::HOST)
            ->assertSet('tab', 'personnels-partages')
            ->assertSee('Personnels et partagés');
    }

    #[Test]
    public function it_switches_to_the_lecteurs_reseaux_tab_and_embeds_the_shares_manager(): void
    {
        $this->grant(['server.admin', 'view-networkshare', 'manage-networkshare']);

        Livewire::test(self::HOST)
            ->call('setTab', 'lecteurs-reseaux')
            ->assertSet('tab', 'lecteurs-reseaux')
            // En-tête du composant partages embarqué.
            ->assertSee('Lecteurs réseau gérés');
    }

    #[Test]
    public function the_lecteurs_reseaux_tab_is_guarded_without_networkshare_permission(): void
    {
        $this->grant(['server.admin']); // pas de view-networkshare

        Livewire::test(self::HOST)
            ->call('setTab', 'lecteurs-reseaux')
            ->assertSee('permission de gérer les partages');
    }

    #[Test]
    public function an_unknown_tab_falls_back_to_the_default(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::HOST, ['tab' => 'bogus'])
            ->assertSet('tab', 'personnels-partages');
    }

    /**
     * L'onglet « Quotas & FS » a été retiré (décision Henri 2026-08-05) : sa clé
     * n'est plus dans `TABS`, donc elle retombe sur le défaut comme n'importe
     * quelle clé inconnue. Ce test empêche de la ré-ajouter par inadvertance —
     * son retour doit passer par la story 5.1e, en carte, pas en onglet.
     */
    #[Test]
    public function the_removed_quotas_tab_is_not_reachable(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::HOST, ['tab' => 'quotas-fs'])
            ->assertSet('tab', 'personnels-partages')
            ->assertDontSee('Quotas par défaut (par profil)');
    }

    #[Test]
    public function it_switches_to_the_roaming_tab(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::HOST)
            ->call('setTab', 'roaming')
            ->assertSet('tab', 'roaming')
            // Première card du partial Profils itinérants.
            ->assertSee('Exclusions du profil itinérant');
    }

    /**
     * Les onglets ciblés par les redirections nommées DOIVENT exister : sinon
     * `mount()` retombe silencieusement sur le 1er onglet et l'UI est perdue.
     */
    #[Test]
    public function the_tabs_targeted_by_the_legacy_redirects_survive_mount(): void
    {
        $this->grant(['server.admin']);

        foreach (['admin.quotas' => 'personnels-partages', 'admin.settings.profils-itinerants' => 'roaming'] as $routeName => $tab) {
            // `Route::redirect()` stocke la cible dans les defaults de la route :
            // on lit la destination réelle plutôt que de rejouer la requête HTTP
            // (le middleware `sambaedu.auth` exige une session LDAP absente en test).
            $destination = (string) (\Illuminate\Support\Facades\Route::getRoutes()
                ->getByName($routeName)?->defaults['destination'] ?? '');

            $this->assertSame('/admin/settings/files?tab='.$tab, $destination);

            Livewire::test(self::HOST, ['tab' => $tab])->assertSet('tab', $tab);
        }
    }
}
