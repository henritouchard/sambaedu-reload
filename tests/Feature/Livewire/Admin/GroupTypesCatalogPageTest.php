<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\DirectoryTemplate;
use App\Models\GroupType;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Support\GroupTypeCatalog;
use Database\Seeders\GroupRoleSeeder;
use Database\Seeders\GroupTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Story 62.2 — l'onglet « Types de groupes » de /admin/settings/groups.
 *
 * Couvre AC9 (page hôte, onglet, liste + usages + accrochages, modales, double
 * garde), AC6 (l'invariant d'arbre DIT à l'écran) et AC10 (accès : `server.admin`
 * SEUL, aucune route nouvelle).
 */
class GroupTypesCatalogPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.settings.groups.index';

    private const TAB = 'pages::admin.settings.groups._partials.types-tab';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GroupRoleSeeder::class);
        $this->seed(GroupTypeSeeder::class);

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();

        $this->actingAs(User::create(['login' => 'types-admin', 'role' => 'admin', 'is_active' => true]));
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    /** @param list<string> $abilities */
    private function grant(array $abilities): void
    {
        Gate::before(fn ($user, string $ability) => in_array($ability, $abilities, true) ? true : null);
    }

    // =========================================================================
    // AC10 — accès
    // =========================================================================

    #[Test]
    public function a_non_admin_is_forbidden_at_mount(): void
    {
        Livewire::test(self::TAB)->assertForbidden();
    }

    #[Test]
    public function no_new_route_is_introduced(): void
    {
        // La page EXISTE depuis 62.1 : cette story y ajoute un onglet, rien de plus.
        $this->assertSame('/admin/settings/groups', route('admin.settings.groups', absolute: false));
        $this->assertNull(\Illuminate\Support\Facades\Route::getRoutes()->getByName('admin.settings.group-types'));
    }

    // =========================================================================
    // AC9 — la page hôte et son onglet
    // =========================================================================

    #[Test]
    public function the_types_tab_is_reachable_by_query_parameter(): void
    {
        $this->grant(['server.admin']);

        Livewire::withQueryParams(['tab' => 'types'])
            ->test(self::PAGE)
            ->assertOk()
            ->assertSet('tab', 'types')
            ->assertSeeHtml('data-testid="tab-types"');
    }

    #[Test]
    public function an_unknown_tab_still_falls_back_to_roles(): void
    {
        $this->grant(['server.admin']);

        Livewire::withQueryParams(['tab' => 'inconnu'])
            ->test(self::PAGE)
            ->assertOk()
            ->assertSet('tab', 'roles');
    }

    #[Test]
    public function the_tab_lists_the_catalog_in_display_order_with_its_group_counts(): void
    {
        $this->grant(['server.admin']);

        UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        UserGroup::create(['name' => 'Classe_4emeB', 'type' => 'classe']);
        UserGroup::create(['name' => 'Projet_jardin', 'type' => 'projet']);

        $rows = Livewire::test(self::TAB)->assertOk()->get('rows');

        $this->assertSame([
            'custom', 'classe', 'cours', 'matiere', 'matiere_classe', 'projet', 'equipe', 'role', 'function',
        ], array_column($rows, 'key'));

        $byKey = array_column($rows, null, 'key');
        $this->assertSame(2, $byKey['classe']['usage']['groups']);
        $this->assertSame(1, $byKey['projet']['usage']['groups']);
        $this->assertSame(0, $byKey['cours']['usage']['groups']);
        $this->assertTrue($byKey['classe']['protected']);
        $this->assertSame('fa-solid fa-graduation-cap', $byKey['classe']['icon']);
    }

    /**
     * AC6 — l'écran DIT l'invariant : une recette d'ARBRE, plusieurs plates.
     */
    #[Test]
    public function the_tab_shows_the_attached_tree_and_counts_the_flat_ones(): void
    {
        $this->grant(['server.admin']);
        $this->seed(\Database\Seeders\DirectoryTemplateSeeder::class);

        $component = Livewire::test(self::TAB)->assertOk();
        $byKey = array_column($component->get('rows'), null, 'key');

        // Le seeder accroche DEUX recettes à `classe` : un arbre et une plate.
        $this->assertNotNull($byKey['classe']['attachment']['tree']);
        $this->assertSame(1, $byKey['classe']['attachment']['flat']);
        $this->assertNull($byKey['projet']['attachment']['tree']);

        // Et la règle est énoncée sous la liste, en français.
        $component->assertSeeHtml('data-testid="tree-attachment-note"')
            ->assertSee('recette d\'arborescence', escape: false);
    }

    // =========================================================================
    // AC2 / AC9 — création, édition, ordre
    // =========================================================================

    #[Test]
    public function creating_a_type_derives_and_freezes_its_key(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openCreate')
            ->set('label', 'Club de lecture')
            ->assertSet('isModalOpen', true)
            ->assertSeeHtml('club_de_lecture')
            ->call('save')
            ->assertSet('isModalOpen', false);

        $type = GroupType::where('key', 'club_de_lecture')->firstOrFail();
        $this->assertSame('Club de lecture', $type->label);
        $this->assertNull($type->icon);
        $this->assertSame(10, $type->sort_order);

        // Il entre IMMÉDIATEMENT dans le vocabulaire (mémo vidée par le hook).
        $this->assertContains('club_de_lecture', GroupTypeCatalog::keys());
    }

    #[Test]
    public function a_type_can_carry_an_icon_typed_freely(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openCreate')
            ->set('label', 'Club')
            ->set('icon', 'fa-solid fa-guitar')
            ->call('save');

        $this->assertSame('fa-solid fa-guitar', GroupType::where('key', 'club')->value('icon'));
        $this->assertSame('fa-solid fa-guitar', GroupTypeCatalog::icon('club'));
    }

    #[Test]
    public function a_label_that_slugifies_to_nothing_is_refused(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openCreate')
            ->set('label', '123')
            ->call('save')
            ->assertHasErrors('label');

        $this->assertSame(9, GroupType::count());
    }

    #[Test]
    public function a_colliding_key_is_refused_with_a_business_message(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openCreate')
            ->set('label', 'Classe')
            ->call('save')
            ->assertHasErrors('label');

        $this->assertSame(1, GroupType::where('key', 'classe')->count());
    }

    #[Test]
    public function editing_changes_only_the_label_and_the_icon(): void
    {
        $this->grant(['server.admin']);

        $classe = GroupType::where('key', 'classe')->firstOrFail();

        Livewire::test(self::TAB)
            ->call('openEdit', $classe->id)
            ->assertSet('isEditing', true)
            ->assertSet('label', 'Classe')
            ->set('label', 'Division')
            ->set('icon', 'fa-solid fa-school')
            ->call('save')
            ->assertSet('isModalOpen', false);

        $classe->refresh();
        $this->assertSame('classe', $classe->key);
        $this->assertSame('Division', $classe->label);
        $this->assertSame('fa-solid fa-school', $classe->icon);
    }

    #[Test]
    public function the_order_can_be_changed_up_and_down(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB);
        $rows = $component->get('rows');
        $classeId = $rows[1]['id'];

        $component->call('moveUp', $classeId);
        $this->assertSame(['classe', 'custom'], array_slice(array_column($component->get('rows'), 'key'), 0, 2));

        $component->call('moveDown', $classeId);
        $this->assertSame(['custom', 'classe'], array_slice(array_column($component->get('rows'), 'key'), 0, 2));
    }

    // =========================================================================
    // AC7 — les refus de suppression, à l'écran
    // =========================================================================

    #[Test]
    public function deleting_a_structural_type_is_refused_without_a_confirmation_modal(): void
    {
        $this->grant(['server.admin']);

        $classe = GroupType::where('key', 'classe')->firstOrFail();

        Livewire::test(self::TAB)
            ->call('confirmDelete', $classe->id)
            ->assertSet('isDeleteOpen', false);

        $this->assertSame(1, GroupType::where('key', 'classe')->count());
    }

    #[Test]
    public function deleting_a_type_carried_by_groups_is_refused_and_writes_nothing(): void
    {
        $this->grant(['server.admin']);

        GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);
        UserGroup::create(['name' => 'Echecs', 'type' => 'club']);

        $club = GroupType::where('key', 'club')->firstOrFail();

        Livewire::test(self::TAB)
            ->call('confirmDelete', $club->id)
            ->assertSet('isDeleteOpen', false);

        $this->assertSame(1, GroupType::where('key', 'club')->count());
        $this->assertSame('club', UserGroup::where('name', 'Echecs')->value('type'));
    }

    #[Test]
    public function deleting_a_type_targeted_by_a_template_is_refused(): void
    {
        $this->grant(['server.admin']);

        GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);
        DirectoryTemplate::create([
            'key' => 'recette_club',
            'label' => 'Recette club',
            'attached_group_type' => 'club',
            'roles_spec' => [],
            'nodes_spec' => [],
        ]);

        $club = GroupType::where('key', 'club')->firstOrFail();

        Livewire::test(self::TAB)
            ->call('confirmDelete', $club->id)
            ->assertSet('isDeleteOpen', false);

        $this->assertSame(1, GroupType::where('key', 'club')->count());
        $this->assertSame(1, DirectoryTemplate::where('key', 'recette_club')->count());
    }

    #[Test]
    public function an_unused_custom_type_is_deletable_after_confirmation(): void
    {
        $this->grant(['server.admin']);

        $club = GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);

        Livewire::test(self::TAB)
            ->call('confirmDelete', $club->id)
            ->assertSet('isDeleteOpen', true)
            ->call('delete')
            ->assertSet('isDeleteOpen', false);

        $this->assertSame(0, GroupType::where('key', 'club')->count());
        $this->assertNotContains('club', GroupTypeCatalog::keys());
    }

    /**
     * Re-vérification côté serveur : entre l'ouverture de la confirmation et le
     * clic, un groupe a pu naître.
     */
    #[Test]
    public function a_usage_appearing_between_confirmation_and_click_still_refuses(): void
    {
        $this->grant(['server.admin']);

        $club = GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);

        $component = Livewire::test(self::TAB)
            ->call('confirmDelete', $club->id)
            ->assertSet('isDeleteOpen', true);

        UserGroup::create(['name' => 'Echecs', 'type' => 'club']);

        $component->call('delete');

        $this->assertSame(1, GroupType::where('key', 'club')->count());
    }

    // =========================================================================
    // AC9 — la double garde sur CHAQUE écriture
    // =========================================================================

    #[Test]
    public function every_write_re_checks_server_admin(): void
    {
        // Le droit est accordé pour le MONTAGE, puis retiré : une garde posée au
        // seul `mount()` laisserait passer toutes les écritures suivantes.
        // Fermeture CLASSIQUE et capture par référence : une fonction fléchée
        // capturerait `$allowed` par valeur et le droit ne serait jamais retiré —
        // le test passerait alors sans rien prouver (patron 62.1).
        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        $classe = GroupType::where('key', 'classe')->firstOrFail();
        $before = GroupType::orderBy('sort_order')->orderBy('id')->pluck('key')->all();

        // Un composant NEUF par méthode : après un 403, l'état Livewire n'est plus
        // rejouable — enchaîner les appels sur la même instance testerait le
        // mécanisme de test, pas la garde.
        foreach (['openCreate', 'openEdit', 'save', 'moveUp', 'moveDown', 'confirmDelete', 'delete'] as $method) {
            $allowed = true;
            $component = Livewire::test(self::TAB)->assertOk();
            $allowed = false;

            try {
                $component->call($method, $classe->id)->assertForbidden();
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(), '« ' . $method . ' » a levé autre chose qu\'un 403');
            }
        }

        // Et RIEN n'a bougé : ni le catalogue, ni son ordre.
        $this->assertSame(9, GroupType::count());
        $this->assertSame($before, GroupType::orderBy('sort_order')->orderBy('id')->pluck('key')->all());
    }

    // =========================================================================
    // AC9 — la carte du sommaire des paramètres
    // =========================================================================

    #[Test]
    public function the_settings_card_mentions_group_types_without_a_new_card(): void
    {
        $this->grant(['server.admin']);

        Livewire::test('pages::admin.settings.index')
            ->assertOk()
            ->assertSeeHtml('data-testid="card-settings-groups"')
            ->assertSee('types de groupes');
    }
}
