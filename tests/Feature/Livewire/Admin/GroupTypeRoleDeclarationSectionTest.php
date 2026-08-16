<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\GroupRole;
use App\Models\GroupType;
use App\Models\GroupTypeRole;
use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Support\RoleCatalog;
use Database\Seeders\GroupRoleSeeder;
use Database\Seeders\GroupTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\InstallsCollegeRoleProfile;

/**
 * Story 62.3 — AC8 : la section « Rôles disponibles » de la modale d'édition d'un
 * type, et AC5 : le refus de retrait, en TOUT-OU-RIEN.
 *
 * Le test qui compte le plus ici est
 * {@see self::a_refused_removal_writes_absolutely_nothing()} : un refus tardif,
 * après écriture partielle, serait pire que pas de refus du tout — l'admin
 * croirait avoir annulé quand il aurait à moitié enregistré.
 */
class GroupTypeRoleDeclarationSectionTest extends TestCase
{
    use InstallsCollegeRoleProfile;
    use RefreshDatabase;

    private const TAB = 'pages::admin.settings.groups._partials.types-tab';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GroupRoleSeeder::class);
        $this->seed(GroupTypeSeeder::class);
        // Story 62.3 — l'écran édite des déclarations ; la migration n'en pose
        // plus. On installe le profil scolaire pour disposer d'un état de départ
        // réaliste (classe déclarée avec surcharges, projet déclaré partiellement,
        // cours sans aucune déclaration).
        $this->installCollegeRoleProfile();

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();

        $this->actingAs(User::create(['login' => 'declare-admin', 'role' => 'admin', 'is_active' => true]));
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

    private function typeId(string $key): int
    {
        return (int) GroupType::where('key', $key)->firstOrFail()->id;
    }

    // =========================================================================
    // Accès — la double garde, prouvée par un retrait EN COURS DE SESSION
    // =========================================================================

    #[Test]
    public function a_non_admin_cannot_reach_the_section(): void
    {
        Livewire::test(self::TAB)->assertForbidden();
    }

    /**
     * Le droit est retiré APRÈS le `mount()` : seule la garde de l'écriture peut
     * encore refuser. C'est le patron éprouvé des pages de réglages — une garde
     * au seul `mount()` laisserait passer toute la session.
     */
    #[Test]
    public function losing_the_right_mid_session_blocks_the_write(): void
    {
        // Fermeture CLASSIQUE et capture par RÉFÉRENCE : une fonction fléchée
        // capturerait `$allowed` par valeur, le droit ne serait jamais retiré, et
        // le test passerait sans rien prouver (patron 62.1/62.2).
        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        $component = Livewire::test(self::TAB)
            ->call('openEdit', $this->typeId('projet'))
            ->assertSet('isModalOpen', true)
            ->set('selectedRoleKeys', ['member']);

        $allowed = false;

        try {
            $component->call('save')->assertForbidden();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode(), '« save » a levé autre chose qu\'un 403');
        }

        // Rien n'a été écrit.
        $this->assertSame(
            2,
            DB::table('group_type_roles')->where('group_type_key', 'projet')->count(),
        );
    }

    // =========================================================================
    // AC8 — l'état de la section
    // =========================================================================

    #[Test]
    public function opening_a_declared_type_loads_its_declarations_and_local_labels(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEdit', $this->typeId('classe'));

        $this->assertSame(['member', 'manager', 'owner'], $component->get('selectedRoleKeys'));
        $this->assertSame(
            ['member' => 'Élève', 'manager' => 'Enseignant', 'owner' => 'Professeur principal'],
            $component->get('roleLabels'),
        );

        // Une déclaration SANS surcharge revient bien à un champ vide, pas au
        // libellé du catalogue : le placeholder dit ce qui s'appliquera.
        $projet = Livewire::test(self::TAB)->call('openEdit', $this->typeId('projet'));
        $this->assertSame(['member', 'manager'], $projet->get('selectedRoleKeys'));
        $this->assertSame(['member' => '', 'manager' => 'Porteur', 'owner' => ''], $projet->get('roleLabels'));
    }

    #[Test]
    public function a_type_without_declaration_shows_the_fallback_notice(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openEdit', $this->typeId('cours'))
            ->assertSet('selectedRoleKeys', [])
            ->assertSeeHtml('data-testid="no-declaration-notice"')
            ->assertSee('tous les rôles du catalogue sont disponibles');
    }

    /** La modale de CRÉATION reste intouchée : un type neuf naît sans déclaration. */
    #[Test]
    public function the_creation_modal_carries_no_declaration_section(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openCreate')
            ->assertSet('selectedRoleKeys', [])
            ->assertDontSeeHtml('data-testid="declare-role-member"');

        Livewire::test(self::TAB)
            ->call('openCreate')
            ->set('label', 'Club de lecture')
            ->call('save')
            ->assertSet('isModalOpen', false);

        $this->assertSame(
            0,
            DB::table('group_type_roles')->where('group_type_key', 'club_de_lecture')->count(),
            'un type neuf naît en régime de repli, sans déclaration',
        );
    }

    // =========================================================================
    // AC8 — l'enregistrement du delta
    // =========================================================================

    #[Test]
    public function saving_applies_additions_removals_and_local_labels_at_once(): void
    {
        $this->grant(['server.admin']);
        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        Livewire::test(self::TAB)
            ->call('openEdit', $this->typeId('projet'))
            // ajout de `tuteur`, retrait de `member`, surcharge de `manager`
            ->set('selectedRoleKeys', ['manager', 'tuteur'])
            ->set('roleLabels.manager', 'Chef de projet')
            ->set('roleLabels.tuteur', 'Parrain')
            ->call('save')
            ->assertSet('isModalOpen', false)
            ->assertHasNoErrors();

        $this->assertSame(['manager', 'tuteur'], RoleCatalog::assignableKeys('projet'));
        $this->assertSame('Chef de projet', RoleCatalog::label('projet', 'manager'));
        $this->assertSame('Parrain', RoleCatalog::label('projet', 'tuteur'));
    }

    #[Test]
    public function clearing_a_local_label_restores_the_catalog_one(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openEdit', $this->typeId('equipe'))
            ->set('roleLabels.manager', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(
            DB::table('group_type_roles')
                ->where('group_type_key', 'equipe')
                ->where('group_role_key', 'manager')
                ->value('label'),
        );
        $this->assertSame('Gestionnaire', RoleCatalog::label('equipe', 'manager'));
    }

    /** Une clé de rôle forgée est écartée en silence : elle n'est pas au catalogue. */
    #[Test]
    public function a_forged_role_key_never_becomes_a_declaration(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openEdit', $this->typeId('equipe'))
            ->set('selectedRoleKeys', ['member', 'manager', 'inexistant'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            0,
            DB::table('group_type_roles')->where('group_role_key', 'inexistant')->count(),
        );
    }

    // =========================================================================
    // AC5 — le refus de retrait, en TOUT-OU-RIEN
    // =========================================================================

    #[Test]
    public function a_refused_removal_writes_absolutely_nothing(): void
    {
        $this->grant(['server.admin']);

        // Deux enseignants `manager` dans une classe : retirer la déclaration
        // `classe`×`manager` est refusé.
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        foreach (['prof.un', 'prof.deux'] as $login) {
            $user = User::create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
            DB::table('user_group_user')->insert([
                'user_id' => $user->id,
                'user_group_id' => $group->id,
                'role' => UserGroupUserPivot::ROLE_MANAGER,
            ]);
        }

        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        $declarationsBefore = DB::table('group_type_roles')->orderBy('id')->get()->toJson();
        $typeBefore = GroupType::where('key', 'classe')->firstOrFail()->only(['label', 'icon']);

        Livewire::test(self::TAB)
            ->call('openEdit', $this->typeId('classe'))
            // Une soumission qui MÊLE tout : un retrait refusé, un ajout, une
            // surcharge, et un renommage du type lui-même.
            ->set('label', 'Division')
            ->set('selectedRoleKeys', ['member', 'owner', 'tuteur'])
            ->set('roleLabels.member', 'Apprenant')
            ->call('save');

        // RIEN n'est passé — ni les retraits, ni les ajouts, ni les libellés, ni
        // même le renommage du type.
        $this->assertSame(
            $declarationsBefore,
            DB::table('group_type_roles')->orderBy('id')->get()->toJson(),
            'un refus de retrait a laissé passer une écriture',
        );
        $this->assertSame(
            $typeBefore,
            GroupType::where('key', 'classe')->firstOrFail()->only(['label', 'icon']),
            'un refus de retrait a laissé passer le renommage du type',
        );
        $this->assertSame('Élève', RoleCatalog::label('classe', 'member'));
    }

    #[Test]
    public function a_removal_no_edge_carries_goes_through(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openEdit', $this->typeId('classe'))
            ->set('selectedRoleKeys', ['member', 'manager'])
            ->call('save')
            ->assertSet('isModalOpen', false)
            ->assertHasNoErrors();

        $this->assertSame(['member', 'manager'], RoleCatalog::assignableKeys('classe'));
    }

    // =========================================================================
    // AC8 — la liste et l'encart des rôles
    // =========================================================================

    #[Test]
    public function the_list_shows_the_declared_roles_of_each_type(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->assertOk();
        $rows = collect($component->get('rows'))->keyBy('key');

        $this->assertSame(
            ['Élève', 'Enseignant', 'Professeur principal'],
            array_column($rows['classe']['declared_roles'], 'label'),
        );
        // Une déclaration SANS surcharge affiche le libellé du CATALOGUE.
        $this->assertSame(['Membre', 'Porteur'], array_column($rows['projet']['declared_roles'], 'label'));
        // Un type sans déclaration : rien à montrer.
        $this->assertSame([], $rows['cours']['declared_roles']);

        $component->assertSeeHtml('data-testid="declared-roles-classe"')
            ->assertSee('Porteur');
    }

    /**
     * Clôture de la review 62.1 #4 : l'onglet « Rôles » renvoie au mécanisme.
     *
     * Le renvoi vivait dans un bandeau permanent en tête d'onglet ; il vit
     * désormais SOUS LE CHAMP « Libellé », c'est-à-dire au moment précis où
     * l'administrateur saisit la valeur qu'un type de groupe peut surcharger.
     * Ce qui est épinglé ici est le RENVOI, pas son emplacement — mais il doit
     * exister : sans lui, on renomme un rôle en croyant avoir renommé partout.
     */
    #[Test]
    public function the_roles_tab_now_points_to_the_administrable_mechanism(): void
    {
        $this->grant(['server.admin']);

        Livewire::test('pages::admin.settings.groups._partials.roles-tab')
            ->assertOk()
            ->assertSeeHtml('data-testid="hint-role-label-translated"')
            ->assertSee('traduit par type de groupe')
            ->assertSee('Types de groupes')
            ->assertSee('Porteur')
            ->assertDontSee('certains types de groupes le traduisent déjà');
    }

    /**
     * Une clé de type HÉRITÉE (non-slug) est éditable ET déclarable depuis
     * l'écran — le scénario que la review 62.2 #1 a rendu jouable.
     */
    #[Test]
    public function an_inherited_type_key_is_declarable_from_the_screen(): void
    {
        $this->grant(['server.admin']);

        DB::table('group_types')->insert([
            'key' => 'Custom',
            'label' => 'Custom hérité',
            'icon' => null,
            'sort_order' => 42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test(self::TAB)
            ->call('openEdit', $this->typeId('Custom'))
            ->set('selectedRoleKeys', ['member'])
            ->set('roleLabels.member', 'Adhérent')
            ->call('save')
            ->assertSet('isModalOpen', false)
            ->assertHasNoErrors();

        $this->assertSame('Adhérent', RoleCatalog::label('Custom', 'member'));
        $this->assertSame(['member'], RoleCatalog::assignableKeys('Custom'));
        // `custom`, son homonyme de casse, n'a rien attrapé.
        $this->assertSame([], GroupTypeRole::declaredFor('custom'));
    }
}
