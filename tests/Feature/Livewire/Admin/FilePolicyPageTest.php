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
 * Page hôte /admin/settings/files — deux onglets « Personnels et partagés »
 * (politique) et « Lecteurs réseaux » (gestion des partages embarquée). Couvre la
 * logique d'onglets et le rendu du composant partages embarqué selon la permission.
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
}
