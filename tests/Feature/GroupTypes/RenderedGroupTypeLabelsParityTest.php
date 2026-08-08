<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypes;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use Database\Seeders\GroupRoleSeeder;
use Database\Seeders\GroupTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.2 — les libellés RENDUS, pas seulement calculés.
 *
 * La parité en littéraux ({@see GroupTypeCatalogParityTest}) prouve que le
 * catalogue rend ce que les `match` rendaient. Ce fichier-ci prouve que les
 * ÉCRANS lisent bien le catalogue — c'est-à-dire que la bascule a été faite
 * partout, et pas seulement là où on l'a cherchée.
 *
 * Il épingle aussi la SEULE divergence assumée : la fiche groupe rendait
 * « Role »/« Function » (son `match` ignorait ces deux clés et retombait sur
 * `ucfirst`) là où la fiche utilisateur disait « Rôle »/« Fonction ». Les deux
 * lisent désormais la même ligne.
 */
class RenderedGroupTypeLabelsParityTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_PAGE = 'pages::users.groups.[id].index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GroupTypeSeeder::class);
        $this->seed(GroupRoleSeeder::class);

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();

        $this->actingAs(User::create(['login' => 'types-admin', 'role' => 'admin', 'is_active' => true]));
        Gate::before(fn () => true);
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function the_group_page_renders_the_catalog_label(): void
    {
        $group = UserGroup::create(['name' => 'Matiere_maths@3A', 'type' => 'matiere_classe']);

        Livewire::test(self::GROUP_PAGE, ['id' => $group->id])
            ->assertOk()
            ->assertSee('Matière / Classe');
    }

    /**
     * LA DIVERGENCE CORRIGÉE : la fiche groupe disait « Role », elle dit « Rôle ».
     */
    #[Test]
    public function the_group_page_no_longer_renders_the_ucfirst_of_role_and_function(): void
    {
        $roleGroup = UserGroup::create(['name' => 'Profs', 'type' => 'role']);
        Livewire::test(self::GROUP_PAGE, ['id' => $roleGroup->id])
            ->assertOk()
            ->assertSee('Rôle')
            ->assertDontSee('>Role<', escape: false);

        $functionGroup = UserGroup::create(['name' => 'Direction', 'type' => 'function']);
        Livewire::test(self::GROUP_PAGE, ['id' => $functionGroup->id])
            ->assertOk()
            ->assertSee('Fonction')
            ->assertDontSee('>Function<', escape: false);
    }

    /**
     * Un renommage au catalogue se voit IMMÉDIATEMENT à l'écran : c'est bien la
     * table qui est lue, pas une constante recopiée.
     */
    #[Test]
    public function renaming_a_type_changes_what_the_group_page_reads(): void
    {
        $group = UserGroup::create(['name' => '3emeA', 'type' => 'classe']);

        Livewire::test(self::GROUP_PAGE, ['id' => $group->id])->assertOk()->assertSee('Classe');

        \App\Models\GroupType::where('key', 'classe')->first()->update(['label' => 'Division']);

        Livewire::test(self::GROUP_PAGE, ['id' => $group->id])
            ->assertOk()
            ->assertSee('Division');
    }

    /**
     * Le tiroir de sélection rendait la VALEUR TECHNIQUE nue en description
     * (`matiere_classe`, et « » pour un groupe sans type). Il lit le catalogue.
     */
    #[Test]
    public function the_groups_drawer_no_longer_renders_a_raw_technical_value(): void
    {
        UserGroup::create(['name' => 'Matiere_maths@3A', 'type' => 'matiere_classe']);

        $rows = Livewire::test('components::organisms.groups-drawer')
            ->call('loadGroups')
            ->get('availableGroups');

        $descriptions = array_column($rows, 'description');

        $this->assertContains('Matière / Classe', $descriptions);
        $this->assertNotContains('matiere_classe', $descriptions);
    }
}
