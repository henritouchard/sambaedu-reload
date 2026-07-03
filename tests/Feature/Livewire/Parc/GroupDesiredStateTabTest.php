<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Shortcut;
use App\Models\WorkstationGroup;
use App\Observers\AppProfileObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 37.1 (AC2, AC6) — onglet « État cible » de la page PARC (SFC Livewire,
 * consultation pure). Contribution du parc (direct + via profil), planchers socle
 * commun, distinction salle vs parc logique.
 */
class GroupDesiredStateTabTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.groups.[id]._partials.desired-state-tab';

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::$syncEnabled = false;
        AppProfileObserver::$syncEnabled = false;
        // SFC #[Lazy] (correction P1) : rendu EAGER par défaut pour les tests de
        // contenu/verrou ; le test lazy dédié réactive le placeholder.
        Livewire::withoutLazyLoading();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::$syncEnabled = true;
        AppProfileObserver::$syncEnabled = true;
        parent::tearDown();
    }

    #[Test]
    public function renders_group_contribution_direct_via_profile_and_socle(): void
    {
        $parc = WorkstationGroup::create(['name' => 'ParcInfo', 'is_physical' => false]);

        $direct = Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);
        $parc->applications()->attach([$direct->id]);

        $viaProfile = Application::create(['app_id' => 'libreoffice', 'name' => 'LibreOffice']);
        $profile = AppProfile::create(['name' => 'ProfilBureautique', 'is_active' => true]);
        $profile->applications()->attach([$viaProfile->id]);
        $parc->appProfiles()->attach([$profile->id]);

        $socle = Application::create(['app_id' => '7za', 'name' => '7-Zip']);
        $socle->is_parc_default = true;
        $socle->save();

        $sc = Shortcut::create([
            'key' => 'k-parc', 'name' => 'RaccourciParc', 'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'C:\\p.exe', 'is_active' => true,
        ]);
        $sc->workstationGroups()->attach($parc->id);

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->assertOk()
            ->assertSee('Firefox')
            ->assertSee('Ce parc')
            ->assertSee('LibreOffice')
            ->assertSee('via profil ProfilBureautique')
            ->assertSee('7-Zip')
            ->assertSee('Socle commun')
            ->assertSee('RaccourciParc')
            // Review #6b — mention UI de l'écart : ordres amont ciblés par label
            // poste-portés, non affichés sur la page du groupe.
            ->assertSee('ciblés par label');
    }

    #[Test]
    public function physical_room_page_says_cette_salle(): void
    {
        // Review #5 — sur la page d'une SALLE physique : badge « Cette salle »
        // (room_self, badge-warning) + textes salle-centriques (encart d'intro,
        // états vides) — plus aucun « Ce parc ».
        $salle = WorkstationGroup::create(['name' => 'Salle101', 'is_physical' => true]);

        $app = Application::create(['app_id' => 'roomapp', 'name' => 'Room App']);
        $salle->applications()->attach([$app->id]);

        Livewire::test(self::COMPONENT, ['groupId' => $salle->id])
            ->assertOk()
            ->assertSee('État cible de la salle')
            ->assertSee('Cette salle')
            ->assertSee('Room App')
            ->assertDontSee('Ce parc')
            ->assertDontSee('État cible du parc');
    }

    #[Test]
    public function physical_room_empty_states_are_salle_centric(): void
    {
        // Review #5 — états vides conditionnés sur is_physical.
        $salle = WorkstationGroup::create(['name' => 'SalleVide', 'is_physical' => true]);

        Livewire::test(self::COMPONENT, ['groupId' => $salle->id])
            ->assertOk()
            ->assertSee("Cette salle n'assigne aucun raccourci", false)
            ->assertSee("Cette salle n'apporte aucune application", false);
    }

    #[Test]
    public function empty_group_shows_empty_states(): void
    {
        $parc = WorkstationGroup::create(['name' => 'ParcVide', 'is_physical' => false]);

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->assertOk()
            ->assertSee("n'assigne aucun raccourci", false)
            ->assertSee("n'apporte aucune application", false);
    }

    #[Test]
    public function group_id_is_locked(): void
    {
        $parc = WorkstationGroup::create(['name' => 'ParcLock', 'is_physical' => false]);

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->assertOk()
            ->assertSet('groupId', $parc->id);
    }

    #[Test]
    public function group_id_mutation_is_rejected_by_locked(): void
    {
        // Review #4 — tentative de mutation client sur la propriété #[Locked] :
        // doit lever (preuve réelle du verrou, pas seulement la valeur initiale).
        $parc = WorkstationGroup::create(['name' => 'ParcLock2', 'is_physical' => false]);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->set('groupId', 999);
    }

    #[Test]
    public function renders_lazy_placeholder_before_loading_real_content(): void
    {
        // Correction P1 — premier rendu = squelette sous lazy-loading actif.
        SupportLazyLoading::$disableWhileTesting = false;

        $parc = WorkstationGroup::create(['name' => 'ParcLazy', 'is_physical' => false]);

        Livewire::test(self::COMPONENT, ['groupId' => $parc->id])
            ->assertOk()
            ->assertSee('Chargement')
            ->assertSee('skeleton', false)
            ->assertDontSee('Identifiant WPKG');
    }
}
