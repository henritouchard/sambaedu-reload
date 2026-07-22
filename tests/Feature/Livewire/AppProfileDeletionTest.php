<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Models\AppProfile;
use App\Models\WorkstationGroup;
use App\Observers\AppProfileObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Le drapeau « actif / inactif » des profils applicatifs est remplacé par une
 * suppression DÉFINITIVE.
 *
 * Ce que ces tests verrouillent, c'est la promesse tenue : « inactif » ne
 * retirait rien des postes (le resolver ne filtre que `archived_at`), alors que
 * la suppression détache réellement le profil de ses parcs. Et le catalogue ne
 * doit plus afficher un statut qui ne veut rien dire.
 */
class AppProfileDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        AppProfileObserver::disableSync();
        WorkstationGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        AppProfileObserver::enableSync();
        WorkstationGroupObserver::enableSync();

        parent::tearDown();
    }

    #[Test]
    public function the_catalog_no_longer_shows_an_active_status(): void
    {
        AppProfile::create(['name' => 'base-windows', 'description' => 'Socle Windows']);

        Livewire::test('pages::parc-settings._partials.profiles-tab')
            ->assertSee('base-windows')
            ->assertDontSee('Statut')
            ->assertDontSee('Inactif');
    }

    #[Test]
    public function bulk_delete_removes_the_profiles_for_good(): void
    {
        $kept = AppProfile::create(['name' => 'base-windows']);
        $doomed = AppProfile::create(['name' => 'ancien-profil']);

        Livewire::test('pages::parc-settings._partials.profiles-tab')
            ->set('selectedProfiles', [$doomed->id])
            ->call('deleteProfiles')
            ->assertSet('selectedProfiles', []);

        self::assertNull(AppProfile::find($doomed->id));
        self::assertNotNull(AppProfile::find($kept->id));
    }

    #[Test]
    public function deleting_a_profile_detaches_it_from_the_parcs_that_carried_it(): void
    {
        $profile = AppProfile::create(['name' => 'multimedia']);
        $group = WorkstationGroup::create(['name' => 'salle-101', 'is_physical' => true]);
        $profile->workstationGroups()->attach($group->id);

        Livewire::test('pages::parc-settings._partials.profiles-tab')
            ->set('selectedProfiles', [$profile->id])
            ->call('deleteProfiles');

        self::assertNull(AppProfile::find($profile->id));
        self::assertSame(
            0,
            DB::table('app_profile_workstation_group')->where('app_profile_id', $profile->id)->count(),
            'Le pivot doit partir avec le profil : les applications ne sont plus déployées sur ce parc.'
        );
    }

    #[Test]
    public function the_detail_page_deletes_and_returns_to_the_catalog(): void
    {
        $profile = AppProfile::create(['name' => 'graphisme']);

        Livewire::test('pages::parc-settings.profiles.index', ['id' => $profile->id])
            ->call('deleteProfile')
            ->assertRedirect(route('app.parc-settings.index', ['tab' => 'profiles']));

        self::assertNull(AppProfile::find($profile->id));
    }

    #[Test]
    public function the_detail_page_no_longer_shows_an_active_badge(): void
    {
        $profile = AppProfile::create(['name' => 'education', 'description' => 'Applications pédagogiques']);

        Livewire::test('pages::parc-settings.profiles.index', ['id' => $profile->id])
            ->assertSee('education')
            ->assertDontSee('Inactif');
    }
}
