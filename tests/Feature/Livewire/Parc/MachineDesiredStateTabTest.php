<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\Application;
use App\Models\Shortcut;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Observers\WorkstationObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 37.1 (AC1, AC6) — onglet « État cible » de la fiche POSTE (SFC Livewire,
 * consultation pure). Rendu des sections Raccourcis/Applications, badges d'origine,
 * liens vers les groupes sources, note session, poste nu.
 */
class MachineDesiredStateTabTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.machines.[id]._partials.desired-state-tab';

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationObserver::$syncEnabled = false;
        WorkstationGroupObserver::$syncEnabled = false;
        // Le SFC est #[Lazy] (correction P1) : par défaut `Livewire::test()`
        // rendrait le PLACEHOLDER (mount court-circuité). On force le rendu EAGER
        // pour les tests de CONTENU/verrou ci-dessous ; le test dédié au lazy
        // réactive explicitement le lazy-loading.
        Livewire::withoutLazyLoading();
    }

    protected function tearDown(): void
    {
        WorkstationObserver::$syncEnabled = true;
        WorkstationGroupObserver::$syncEnabled = true;
        parent::tearDown();
    }

    private function newShortcut(string $name): Shortcut
    {
        return Shortcut::create([
            'key' => 'k-'.$name,
            'name' => $name,
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'C:\\'.$name.'.exe',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function renders_shortcuts_and_applications_with_origins(): void
    {
        $ws = Workstation::create(['name' => 'PCUI1', 'status' => 'active']);
        $parc = WorkstationGroup::create(['name' => 'ParcBureautique', 'is_physical' => false]);
        $ws->groups()->attach($parc);

        $app = Application::create(['app_id' => 'firefox', 'name' => 'Mozilla Firefox']);
        $ws->applications()->attach([$app->id]);

        $sc = $this->newShortcut('MonRaccourci');
        $sc->workstationGroups()->attach($parc->id);

        Livewire::test(self::COMPONENT, ['workstationId' => $ws->id])
            ->assertOk()
            ->assertSee('MonRaccourci')
            ->assertSee('Mozilla Firefox')
            ->assertSee('firefox')
            ->assertSee('Ce poste')          // origine app directe
            ->assertSee('ParcBureautique')   // origine parc (raccourci) + lien
            ->assertSee(route('app.parc.groups.show', $parc->id))
            // Note session (D3).
            ->assertSee('dépendent de la session', false);
    }

    #[Test]
    public function bare_workstation_shows_empty_states_without_error(): void
    {
        $ws = Workstation::create(['name' => 'PCNAKED', 'status' => 'active']);

        Livewire::test(self::COMPONENT, ['workstationId' => $ws->id])
            ->assertOk()
            ->assertSee('Aucun raccourci résolu')
            ->assertSee('Aucune application résolue');
    }

    #[Test]
    public function socle_commun_app_shows_badge(): void
    {
        $ws = Workstation::create(['name' => 'PCSOCLE', 'status' => 'active']);
        $app = Application::create(['app_id' => '7za', 'name' => '7-Zip']);
        $app->is_parc_default = true;
        $app->save();

        Livewire::test(self::COMPONENT, ['workstationId' => $ws->id])
            ->assertOk()
            ->assertSee('7-Zip')
            ->assertSee('Socle commun');
    }

    #[Test]
    public function workstation_id_is_locked(): void
    {
        $ws = Workstation::create(['name' => 'PCLOCK', 'status' => 'active']);

        Livewire::test(self::COMPONENT, ['workstationId' => $ws->id])
            ->assertOk()
            ->assertSet('workstationId', $ws->id);
    }

    #[Test]
    public function workstation_id_mutation_is_rejected_by_locked(): void
    {
        // Review #4 — le verrou #[Locked] doit RÉELLEMENT rejeter une mutation
        // client (le simple assertSet initial ne prouve pas le verrou : le retrait
        // de #[Locked] resterait vert). Une tentative de set côté client lève.
        $ws = Workstation::create(['name' => 'PCLOCK2', 'status' => 'active']);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(self::COMPONENT, ['workstationId' => $ws->id])
            ->set('workstationId', 999);
    }

    #[Test]
    public function renders_lazy_placeholder_before_loading_real_content(): void
    {
        // Correction P1 — sous lazy-loading actif, le premier rendu est le
        // SQUELETTE (placeholder) : la page parente n'attend pas la résolution du
        // service. On réactive le lazy (que le setUp a désactivé) pour prouver que
        // le placeholder est bien rendu et que le contenu réel ne l'est pas encore.
        SupportLazyLoading::$disableWhileTesting = false;

        $ws = Workstation::create(['name' => 'PCLAZY', 'status' => 'active']);

        Livewire::test(self::COMPONENT, ['workstationId' => $ws->id])
            ->assertOk()
            ->assertSee('Chargement')            // texte du squelette
            ->assertSee('skeleton', false)       // classes DaisyUI du squelette
            ->assertDontSee('Identifiant WPKG'); // en-tête présent SEULEMENT dans le contenu réel
    }
}
