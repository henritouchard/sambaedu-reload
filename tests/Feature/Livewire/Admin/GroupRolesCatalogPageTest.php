<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\GroupRole;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use Database\Seeders\GroupRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Story 62.1 — /admin/settings/groups et son onglet « Rôles ».
 *
 * Couvre AC9 (page hôte, onglet, liste + usages, modales, double garde) et AC10
 * (route + accès). `server.admin` SEUL — Q4 = A, aucune permission Spatie
 * nouvelle.
 */
class GroupRolesCatalogPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.settings.groups.index';

    private const TAB = 'pages::admin.settings.groups._partials.roles-tab';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GroupRoleSeeder::class);

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();

        $this->actingAs(User::create(['login' => 'groups-admin', 'role' => 'admin', 'is_active' => true]));
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
    // AC10 — la route, l'accès
    // =========================================================================

    #[Test]
    public function the_route_exists_and_is_named(): void
    {
        $this->assertSame('/admin/settings/groups', route('admin.settings.groups', absolute: false));
    }

    /**
     * AC10 — la route porte `can:server.admin`, et LUI SEUL (Q4 = A). Le statut
     * rendu à un non-admin dépend de la chaîne de middlewares du groupe
     * `/admin/*` (qui redirige avant d'arriver à la gate) : ce qui se vérifie
     * ici, c'est la déclaration — l'effet au montage est épinglé juste en dessous.
     */
    #[Test]
    public function the_route_is_guarded_by_server_admin_alone(): void
    {
        $middleware = collect(\Illuminate\Support\Facades\Route::getRoutes()->getByName('admin.settings.groups')->gatherMiddleware());

        $this->assertContains('can:server.admin', $middleware->all());
        $this->assertSame(
            ['can:server.admin'],
            $middleware->filter(fn ($m) => is_string($m) && str_starts_with($m, 'can:'))->values()->all(),
            'aucune permission Spatie nouvelle ne doit garder cette route (Q4 = A)',
        );
    }

    #[Test]
    public function a_non_admin_is_forbidden_at_mount_on_both_components(): void
    {
        Livewire::test(self::PAGE)->assertForbidden();
        Livewire::test(self::TAB)->assertForbidden();
    }

    #[Test]
    public function the_settings_landing_carries_the_card(): void
    {
        $this->grant(['server.admin']);

        Livewire::test('pages::admin.settings.index')
            ->assertOk()
            ->assertSeeHtml('data-testid="card-settings-groups"')
            ->assertSee('Groupes &amp; droits', false);
    }

    // =========================================================================
    // AC9 — la page hôte et son onglet unique
    // =========================================================================

    #[Test]
    public function the_host_page_opens_on_the_roles_tab(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSet('tab', 'roles')
            ->assertSeeHtml('data-testid="tab-roles"');
    }

    #[Test]
    public function an_unknown_tab_falls_back_to_roles(): void
    {
        $this->grant(['server.admin']);

        Livewire::withQueryParams(['tab' => 'inconnu'])
            ->test(self::PAGE)
            ->assertOk()
            ->assertSet('tab', 'roles');

        // Story 62.2 — l'onglet `types` EXISTE désormais : ce qui doit retomber
        // sur `roles`, c'est un jeton qui n'est dans aucune des deux listes.
        Livewire::test(self::PAGE)->call('setTab', 'arborescences')->assertSet('tab', 'roles');
    }

    /**
     * PIÈGE NOMMÉ : pas d'onglet fantôme.
     *
     * Story 62.2 — « Types de groupes » a cessé d'être une story future : son
     * onglet est RENDU, donc il s'annonce. « Arborescences » (62.6), elle, ne
     * s'annonce toujours pas. La règle n'a pas changé, seul l'inventaire de ce
     * qui existe a changé.
     */
    #[Test]
    public function no_ghost_tab_is_rendered_for_future_stories(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSeeHtml('data-testid="tab-types"')
            ->assertDontSeeHtml('data-testid="tab-arborescences"');
    }

    #[Test]
    public function the_tab_lists_the_catalog_in_display_order_with_its_usages(): void
    {
        $this->grant(['server.admin']);

        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $user = User::create(['login' => 'alecoz', 'role' => 'prof', 'is_active' => true]);
        DB::table('user_group_user')->insert([
            'user_id' => $user->id,
            'user_group_id' => $group->id,
            'role' => 'manager',
        ]);

        $component = Livewire::test(self::TAB)->assertOk();

        $rows = $component->get('rows');
        $this->assertSame(['member', 'manager', 'owner'], array_column($rows, 'key'));
        $this->assertSame(['Membre', 'Gestionnaire', 'Propriétaire'], array_column($rows, 'label'));
        $this->assertSame(1, $rows[1]['usage']['edges']);
        $this->assertSame(1, $rows[1]['usage']['group_types']);
        $this->assertSame(0, $rows[1]['usage']['templates']);

        // La clé est rendue, discrètement, mais elle est là : c'est ce que porte
        // la donnée, l'admin doit pouvoir le lire.
        $component->assertSee('manager');
    }

    // =========================================================================
    // AC2 — création : clé dérivée, prévisualisée, figée
    // =========================================================================

    #[Test]
    public function creating_a_role_derives_and_freezes_its_key(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openCreate')
            ->set('label', 'Tuteur de stage')
            ->assertSet('isModalOpen', true)
            ->call('save')
            ->assertSet('isModalOpen', false);

        $role = GroupRole::where('label', 'Tuteur de stage')->firstOrFail();
        $this->assertSame('tuteur_de_stage', $role->key);
        $this->assertSame(4, $role->sort_order);
    }

    #[Test]
    public function the_derived_key_is_previewed_before_validation(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openCreate')
            ->set('label', 'Référent numérique')
            ->assertSeeHtml('data-testid="preview-role-key"')
            ->assertSee('referent_numerique');
    }

    #[Test]
    public function a_label_that_slugifies_beyond_the_cap_is_truncated_not_refused(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openCreate')
            ->set('label', 'Référent numérique de circonscription')
            ->call('save')
            ->assertSet('isModalOpen', false);

        $role = GroupRole::where('label', 'Référent numérique de circonscription')->firstOrFail();
        $this->assertLessThanOrEqual(GroupRole::KEY_MAX_LENGTH, strlen($role->key));
    }

    #[Test]
    public function a_colliding_key_is_refused_with_a_business_message(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openCreate')
            ->set('label', 'Manager')
            ->call('save')
            ->assertHasErrors('label')
            ->assertSet('isModalOpen', true);

        $this->assertSame(3, GroupRole::count());
    }

    #[Test]
    public function a_label_without_a_single_letter_is_refused(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openCreate')
            ->set('label', '### 42')
            ->call('save')
            ->assertHasErrors('label');

        $this->assertSame(3, GroupRole::count());
    }

    // =========================================================================
    // AC2 — édition : le libellé seul
    // =========================================================================

    #[Test]
    public function editing_changes_the_label_and_never_the_key(): void
    {
        $this->grant(['server.admin']);

        $manager = GroupRole::where('key', 'manager')->firstOrFail();

        Livewire::test(self::TAB)
            ->call('openEdit', $manager->id)
            ->assertSet('isEditing', true)
            ->assertSet('label', 'Gestionnaire')
            ->set('label', 'Encadrant')
            ->call('save')
            ->assertSet('isModalOpen', false);

        $manager->refresh();
        $this->assertSame('manager', $manager->key);
        $this->assertSame('Encadrant', $manager->label);
    }

    // =========================================================================
    // AC2 — réordonnancement
    // =========================================================================

    #[Test]
    public function the_display_order_is_reorderable_up_and_down(): void
    {
        $this->grant(['server.admin']);

        $owner = GroupRole::where('key', 'owner')->firstOrFail();

        $component = Livewire::test(self::TAB)
            ->call('moveUp', $owner->id);

        $this->assertSame(['member', 'owner', 'manager'], array_column($component->get('rows'), 'key'));

        $component->call('moveDown', $owner->id);
        $this->assertSame(['member', 'manager', 'owner'], array_column($component->get('rows'), 'key'));
    }

    #[Test]
    public function moving_past_the_edges_of_the_list_does_nothing(): void
    {
        $this->grant(['server.admin']);

        $member = GroupRole::where('key', 'member')->firstOrFail();

        $component = Livewire::test(self::TAB)->call('moveUp', $member->id);

        $this->assertSame(['member', 'manager', 'owner'], array_column($component->get('rows'), 'key'));
    }

    // =========================================================================
    // AC7 — suppression : refus nommé, jamais de cascade
    // =========================================================================

    #[Test]
    public function deleting_a_historical_role_is_refused_without_any_write(): void
    {
        $this->grant(['server.admin']);

        $manager = GroupRole::where('key', 'manager')->firstOrFail();

        Livewire::test(self::TAB)
            ->call('confirmDelete', $manager->id)
            ->assertSet('isDeleteOpen', false);

        $this->assertSame(3, GroupRole::count());
    }

    #[Test]
    public function deleting_a_role_carried_by_edges_is_refused_without_any_write(): void
    {
        $this->grant(['server.admin']);

        $role = GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $user = User::create(['login' => 'alecoz', 'role' => 'prof', 'is_active' => true]);
        DB::table('user_group_user')->insert([
            'user_id' => $user->id,
            'user_group_id' => $group->id,
            'role' => 'tuteur',
        ]);

        Livewire::test(self::TAB)
            ->call('confirmDelete', $role->id)
            ->assertSet('isDeleteOpen', false);

        $this->assertTrue(GroupRole::where('key', 'tuteur')->exists());
        $this->assertSame(1, DB::table('user_group_user')->where('role', 'tuteur')->count());
    }

    #[Test]
    public function an_unused_new_role_is_deleted_after_confirmation(): void
    {
        $this->grant(['server.admin']);

        $role = GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        Livewire::test(self::TAB)
            ->call('confirmDelete', $role->id)
            ->assertSet('isDeleteOpen', true)
            ->call('delete')
            ->assertSet('isDeleteOpen', false);

        $this->assertFalse(GroupRole::where('key', 'tuteur')->exists());
    }

    // =========================================================================
    // AC9 — double garde serveur : chaque écriture re-vérifie
    // =========================================================================

    #[Test]
    public function every_write_re_checks_server_admin(): void
    {
        // Le droit est accordé pour le MONTAGE, puis retiré : une garde posée au
        // seul `mount()` laisserait passer toutes les écritures suivantes.
        $allowed = true;
        // Fermeture CLASSIQUE et capture par référence : une fonction fléchée
        // capturerait `$allowed` par valeur et le droit ne serait jamais retiré —
        // le test passerait alors sans rien prouver.
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        $role = GroupRole::where('key', 'owner')->firstOrFail();
        $before = GroupRole::orderBy('sort_order')->orderBy('id')->pluck('key')->all();

        // Un composant NEUF par méthode : après un 403, l'état Livewire n'est plus
        // rejouable — enchaîner les appels sur la même instance testerait le
        // mécanisme de test, pas la garde.
        foreach (['openCreate', 'save', 'moveUp', 'moveDown', 'confirmDelete', 'delete'] as $method) {
            $allowed = true;
            $component = Livewire::test(self::TAB)->assertOk();
            $allowed = false;

            try {
                $component->call($method, $role->id)->assertForbidden();
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(), '« ' . $method . ' » a levé autre chose qu\'un 403');
            }
        }

        // Et RIEN n'a bougé : ni le catalogue, ni son ordre.
        $this->assertSame(3, GroupRole::count());
        $this->assertSame($before, GroupRole::orderBy('sort_order')->orderBy('id')->pluck('key')->all());
    }
}
