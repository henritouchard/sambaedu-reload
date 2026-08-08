<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\DirectoryTemplate;
use App\Models\GroupRole;
use App\Models\NetworkShare;
use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Support\RoleCatalog;
use Database\Seeders\DirectoryTemplateSeeder;
use Database\Seeders\GroupRoleSeeder;
use Database\Seeders\GroupTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\InstallsCollegeRoleProfile;

/**
 * Story 62.6 — l'onglet « Arborescences » de /admin/settings/groups.
 *
 * Couvre AC1 (l'onglet et la liste des types), AC2 (ouvrir ne modifie RIEN),
 * AC3 (la saisie), AC4 (la matrice et les audiences dans les DEUX régimes Q5),
 * AC5 (l'inexprimable grisé ET expliqué, jamais réécrit), AC7 (les refus métier,
 * qui n'écrivent rien), AC8 (la double garde) et AC9 (enregistrer n'exécute rien).
 *
 * **Le test PIVOT est ici** : `opening_and_previewing_leave_the_stored_row_untouched`.
 * Il compare les structures STOCKÉES, pas une équivalence « à normalisation
 * près » — c'est l'oracle anti-normalisation de toute la story.
 *
 * **Aucun libellé scolaire n'est supposé.** Le profil de rôles est OPT-IN : les
 * tests de régime « fermé » l'installent eux-mêmes par la commande dédiée, les
 * autres tournent sur une base où AUCUN type n'est fermé.
 */
class DirectoryTreesTabTest extends TestCase
{
    use InstallsCollegeRoleProfile;
    use RefreshDatabase;

    private const PAGE = 'pages::admin.settings.groups.index';

    private const TAB = 'pages::admin.settings.groups._partials.trees-tab';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(GroupRoleSeeder::class);
        $this->seed(GroupTypeSeeder::class);
        $this->seed(DirectoryTemplateSeeder::class);

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();

        $this->actingAs(User::create(['login' => 'trees-admin', 'role' => 'admin', 'is_active' => true]));
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

    /** Les attributs BRUTS de la recette, tels que la base les porte. */
    private function storedRow(string $key = DirectoryTemplate::KEY_CLASSE_SE4): object
    {
        return DB::table('directory_templates')->where('key', $key)->first();
    }

    // =========================================================================
    // AC8 — accès et double garde
    // =========================================================================

    #[Test]
    public function a_non_admin_is_forbidden_at_mount(): void
    {
        Livewire::test(self::TAB)->assertForbidden();
    }

    /**
     * Le droit est retiré APRÈS le `mount()` : seule la garde de l'ÉCRITURE peut
     * encore refuser. Une garde au seul `mount()` laisserait passer la session.
     */
    #[Test]
    public function losing_the_right_mid_session_blocks_the_write(): void
    {
        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        $before = $this->storedRow();

        $component = Livewire::test(self::TAB)
            ->call('openEditor', 'classe')
            ->set('label', 'Renommée');

        $allowed = false;

        try {
            $component->call('save')->assertForbidden();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode(), '« save » a levé autre chose qu\'un 403');
        }

        $this->assertEquals($before, $this->storedRow(), 'un refus d\'accès a écrit quelque chose');
    }

    /** L'APERÇU aussi est une action gardée, pas seulement les écritures. */
    #[Test]
    public function losing_the_right_mid_session_blocks_the_preview(): void
    {
        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');

        $allowed = false;

        try {
            $component->call('preview')->assertForbidden();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // =========================================================================
    // AC1 — l'onglet et la liste
    // =========================================================================

    #[Test]
    public function the_trees_tab_is_reachable_by_query_parameter(): void
    {
        $this->grant(['server.admin']);

        Livewire::withQueryParams(['tab' => 'trees'])
            ->test(self::PAGE)
            ->assertOk()
            ->assertSet('tab', 'trees')
            ->assertSeeHtml('data-testid="tab-trees"');
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

    /**
     * Aucune UI ORPHELINE : le partiel n'est monté que lorsque son onglet est actif
     * (leçon du correctif ef55abe3, où un onglet retiré laissait son écran derrière
     * lui).
     */
    #[Test]
    public function the_tree_editor_does_not_leak_into_the_other_tabs(): void
    {
        $this->grant(['server.admin']);

        $html = Livewire::withQueryParams(['tab' => 'roles'])->test(self::PAGE)->assertOk()->html();

        $this->assertStringContainsString('data-testid="tab-trees"', $html, 'l\'onglet doit être proposé');
        $this->assertStringNotContainsString('data-testid="flat-recipes-note"', $html);
        $this->assertStringNotContainsString('data-testid="tree-row-classe"', $html);
    }

    #[Test]
    public function the_tab_lists_every_type_with_its_tree_or_none(): void
    {
        $this->grant(['server.admin']);

        UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);

        $rows = collect(Livewire::test(self::TAB)->assertOk()->get('rows'))->keyBy('key');

        $this->assertSame(DirectoryTemplate::KEY_CLASSE_SE4, $rows['classe']['template_key']);
        $this->assertSame(6, $rows['classe']['nodes']);
        $this->assertSame(1, $rows['classe']['groups']);

        $this->assertNull($rows['projet']['template_key'], 'un type sans arbre doit dire « aucune »');
    }

    /**
     * Les recettes PLATES ne sont pas l'affaire de cet onglet : leur
     * matérialisation reste un geste manuel sur l'écran des partages.
     */
    #[Test]
    public function flat_recipes_never_appear_in_this_tab(): void
    {
        $this->grant(['server.admin']);

        $html = Livewire::test(self::TAB)->assertOk()->html();

        foreach ([
            DirectoryTemplate::KEY_PROFS_TO_ELEVES,
            DirectoryTemplate::KEY_DIRECTION_TO_ALL,
            DirectoryTemplate::KEY_USER_TO_USER,
            DirectoryTemplate::KEY_GROUP_SPACE,
        ] as $flat) {
            $this->assertStringNotContainsString($flat, $html);
        }

        $this->assertStringContainsString('data-testid="flat-recipes-note"', $html);
    }

    // =========================================================================
    // AC2 — LE TEST PIVOT : ouvrir (et prévisualiser) ne modifie RIEN
    // =========================================================================

    #[Test]
    public function opening_and_previewing_leave_the_stored_row_untouched(): void
    {
        $this->grant(['server.admin']);

        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $eleve = User::create(['login' => 'dupontj', 'role' => 'eleve', 'is_active' => true]);
        $group->users()->attach($eleve->id, ['role' => 'member']);

        $before = $this->storedRow();

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');
        $this->assertEquals($before, $this->storedRow(), 'ouvrir l\'éditeur a écrit');

        $component->call('preview')->assertSet('previewError', '');
        $this->assertEquals($before, $this->storedRow(), 'prévisualiser a écrit');

        // La comparaison porte sur les STRUCTURES stockées, jamais sur une
        // équivalence « à normalisation près ».
        $this->assertSame(
            json_decode((string) $before->nodes_spec, true),
            $component->get('nodesSpec'),
            'le formulaire a normalisé les nœuds à l\'ouverture',
        );
        $this->assertSame(
            json_decode((string) $before->roles_spec, true),
            $component->get('rolesSpec'),
            'le formulaire a normalisé les audiences à l\'ouverture',
        );
    }

    /**
     * Les clés FACULTATIVES restent exactement là où elles sont : ni ajoutées, ni
     * retirées, ni déplacées. C'est ce qu'une re-sérialisation par objets typés
     * casserait sans un mot.
     */
    #[Test]
    public function optional_keys_and_order_survive_the_round_trip(): void
    {
        $this->grant(['server.admin']);

        $nodes = Livewire::test(self::TAB)->call('openEditor', 'classe')->get('nodesSpec');

        $paths = array_map(static fn (array $node): string => $node['path'], $nodes);
        $this->assertSame(
            ['.', '_travail', '_travail/devoirs', '_profs', '_echange', '{member.login}'],
            $paths,
            'l\'ordre des nœuds stockés a changé',
        );

        $echange = $nodes[4];
        $this->assertArrayHasKey('activable', $echange, 'la clé facultative « activable » a disparu');
        $this->assertArrayNotHasKey('activable', $nodes[0], 'la clé « activable » a été inventée');
        $this->assertSame(
            ['role', 'verbs', 'suspendable'],
            array_keys($echange['grants'][1]),
            'l\'ordre des clés d\'un octroi a changé',
        );
    }

    #[Test]
    public function previewing_an_invalid_recipe_writes_nothing(): void
    {
        $this->grant(['server.admin']);

        UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $before = $this->storedRow();

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');
        $nodes = $component->get('nodesSpec');
        $nodes[] = ['path' => 'a/b/c', 'label' => 'Orphelin', 'nature' => 'partagee', 'grants' => []];

        $component->set('nodesSpec', $nodes)->call('preview');

        $this->assertStringContainsString('inatteignable', $component->get('previewError'));
        $this->assertEquals($before, $this->storedRow());
    }

    // =========================================================================
    // AC3 — la saisie
    // =========================================================================

    #[Test]
    public function the_closed_placeholder_vocabulary_is_offered_by_click(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');
        $nodes = $component->get('editorNodes');

        // Nœud PARTAGÉ : le jeton du membre n'y est pas proposé.
        $shared = array_column($nodes[1]['placeholders'], 'token');
        $this->assertSame(DirectoryTemplate::TREE_PLACEHOLDERS, $shared);

        // Nœud PAR MEMBRE : il l'est.
        $perMember = array_column($nodes[5]['placeholders'], 'token');
        $this->assertContains(DirectoryTemplate::PLACEHOLDER_MEMBER_LOGIN, $perMember);

        foreach ($nodes[1]['placeholders'] as $placeholder) {
            $this->assertNotSame('', $placeholder['help'], 'un jeton sans description se devine');
        }
    }

    #[Test]
    public function inserting_a_placeholder_appends_the_token_to_the_path(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)
            ->call('openEditor', 'classe')
            ->call('addNode');

        $index = count($component->get('nodesSpec')) - 1;
        $component->call('insertPlaceholder', $index, DirectoryTemplate::PLACEHOLDER_GROUP_BARE_NAME);

        $this->assertSame('{group.bare_name}', $component->get('nodesSpec')[$index]['path']);
    }

    /** Un jeton hors vocabulaire n'entre pas, même par un appel forgé. */
    #[Test]
    public function an_unknown_placeholder_is_never_inserted(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe')->call('addNode');
        $index = count($component->get('nodesSpec')) - 1;

        $component->call('insertPlaceholder', $index, 'group.secret');

        $this->assertSame('', $component->get('nodesSpec')[$index]['path']);
    }

    #[Test]
    public function changing_the_nature_synchronises_the_activable_flag(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');

        // `_echange` porte `activable => true`. En changeant sa nature, la clé
        // disparaît : la nature est la source unique.
        $component->set('nodesSpec.4.nature', 'partagee');
        $this->assertArrayNotHasKey('activable', $component->get('nodesSpec')[4]);

        // Revenir à la nature d'origine ne RÉINVENTE pas la clé : elle est
        // facultative, et son absence n'est pas une contradiction. Le drapeau est
        // synchronisé ou retiré — jamais ajouté.
        $component->set('nodesSpec.4.nature', 'activable');
        $this->assertArrayNotHasKey('activable', $component->get('nodesSpec')[4]);
        $component->call('save')->assertHasNoErrors();
    }

    /** Un drapeau PRÉSENT et contredit par la nature est remis d'aplomb, pas laissé. */
    #[Test]
    public function a_present_activable_flag_follows_its_nature_back(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');

        $nodes = $component->get('nodesSpec');
        $nodes[1]['activable'] = false;
        $component->set('nodesSpec', $nodes)->set('nodesSpec.1.nature', 'activable');

        $this->assertTrue($component->get('nodesSpec')[1]['activable']);
    }

    /** Le drapeau n'est jamais AJOUTÉ à un nœud qui ne le portait pas. */
    #[Test]
    public function the_activable_flag_is_never_invented(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)
            ->call('openEditor', 'classe')
            ->set('nodesSpec.1.nature', 'activable');

        $this->assertArrayNotHasKey('activable', $component->get('nodesSpec')[1]);
    }

    #[Test]
    public function the_plafond_is_typed_in_bytes_and_shown_in_human_terms(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)
            ->call('openEditor', 'classe')
            ->set('nodesSpec.3.plafond', '1073741824');

        $this->assertSame(1073741824, $component->get('nodesSpec')[3]['plafond']);
        $this->assertSame('1,0 Go', $component->get('editorNodes')[3]['plafond_human']);

        $component->set('nodesSpec.3.plafond', '');
        $this->assertArrayNotHasKey('plafond', $component->get('nodesSpec')[3]);
    }

    /**
     * `suspendable` n'est PAS un cinquième verbe : c'est un drapeau d'octroi, et il
     * ne se propose que là où quelque chose peut suspendre.
     */
    #[Test]
    public function suspendable_is_offered_only_where_something_can_suspend(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');
        $nodes = $component->get('editorNodes');

        $shared = collect($nodes[1]['columns'])->firstWhere('role', 'equipe');
        $this->assertFalse($shared['suspendable_offered'], 'un dossier partagé n\'a rien à suspendre');

        $activable = collect($nodes[4]['columns'])->firstWhere('role', 'classe');
        $this->assertTrue($activable['suspendable_offered']);
        $this->assertTrue($activable['suspendable']);
        $this->assertFalse($activable['suspendable_orphan']);
    }

    /**
     * Un drapeau devenu ORPHELIN reste proposé — sinon la contradiction stockée
     * serait irréparable à l'écran, alors même que le modèle la refuse.
     */
    #[Test]
    public function an_orphan_suspendable_flag_stays_repairable(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)
            ->call('openEditor', 'classe')
            ->set('nodesSpec.4.nature', 'partagee');

        $column = collect($component->get('editorNodes')[4]['columns'])->firstWhere('role', 'classe');

        $this->assertTrue($column['suspendable_offered'], 'le drapeau orphelin n\'est plus atteignable');
        $this->assertTrue($column['suspendable_orphan']);

        // Le modèle refuse tant qu'il est là…
        $component->call('save');
        $this->assertStringContainsString('suspendable', $component->errors()->get('tree')[0]);

        // …et l'écran permet de le retirer.
        $component->call('toggleSuspendable', 4, 'classe')->call('save')->assertHasNoErrors();
    }

    #[Test]
    public function a_bare_type_opens_the_editor_in_creation_mode(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openEditor', 'projet')
            ->assertSet('editId', null)
            ->assertSet('typeKey', 'projet')
            ->assertSet('rootAnchor', 'classes')
            ->assertSet('nodesSpec', [])
            ->assertSet('rolesSpec', []);
    }

    #[Test]
    public function the_created_key_is_a_slug_frozen_at_creation(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)
            ->call('openEditor', 'projet')
            ->set('label', 'Projet — arbre 2026');

        $this->assertSame('projet_arbre_2026', $component->get('previewKey'));
    }

    // =========================================================================
    // AC4 — la matrice et les audiences, dans les DEUX régimes Q5
    // =========================================================================

    #[Test]
    public function matrix_columns_are_the_recipe_roles_not_the_catalog(): void
    {
        $this->grant(['server.admin']);

        $nodes = Livewire::test(self::TAB)->call('openEditor', 'classe')->get('editorNodes');

        $this->assertSame(['equipe', 'classe'], array_column($nodes[1]['columns'], 'role'));
    }

    #[Test]
    public function the_member_token_column_lives_only_on_per_member_nodes(): void
    {
        $this->grant(['server.admin']);

        $nodes = Livewire::test(self::TAB)->call('openEditor', 'classe')->get('editorNodes');

        $this->assertNotContains(DirectoryTemplate::TREE_ROLE_MEMBER, array_column($nodes[1]['columns'], 'role'));
        $this->assertContains(DirectoryTemplate::TREE_ROLE_MEMBER, array_column($nodes[5]['columns'], 'role'));
    }

    /**
     * **Régime Q5 « instance fraîche »** : la table des déclarations est VIDE,
     * aucun type n'est fermé, tout le catalogue est proposé.
     */
    #[Test]
    public function on_a_bare_instance_every_catalog_role_is_offered_as_an_audience(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'projet');

        $offered = array_keys($component->get('audienceOptions'));

        $this->assertSame('@groupe', $offered[0]);
        foreach (RoleCatalog::keys() as $roleKey) {
            if ($roleKey === UserGroupUserPivot::ROLE_OWNER) {
                continue; // Réservé au type `classe` — vérifié à part.
            }
            $this->assertContains($roleKey, $offered);
        }

        $this->assertSame([], $component->get('undeclaredRoles'), 'aucun type n\'est fermé sur une base nue');
    }

    /**
     * **Régime Q5 « profil posé »** : le type est FERMÉ à ses rôles déclarés, et
     * l'écran DIT ce qu'il ne propose pas, avec le chemin pour l'ajouter.
     */
    #[Test]
    public function once_the_profile_is_installed_a_closed_type_says_what_it_hides(): void
    {
        $this->grant(['server.admin']);
        // Un rôle du catalogue qu'AUCUN type ne déclare : c'est lui que la ligne
        // d'aide doit nommer. Sans lui, le catalogue livré se confondrait avec les
        // déclarations du profil et le test passerait sans rien mesurer.
        GroupRole::create(['key' => 'referent', 'label' => 'Référent', 'sort_order' => 90]);
        $this->installCollegeRoleProfile();

        $component = Livewire::test(self::TAB)->call('openEditor', 'projet');

        $offered = array_keys($component->get('audienceOptions'));
        $declared = RoleCatalog::assignableKeys('projet');

        $this->assertSame(['@groupe', ...$declared], $offered);

        $undeclared = $component->get('undeclaredRoles');
        $this->assertNotSame([], $undeclared, 'un type fermé doit nommer ce qu\'il ne déclare pas');
        foreach (array_keys($undeclared) as $roleKey) {
            $this->assertNotContains($roleKey, $declared);
        }

        $this->assertStringContainsString('data-testid="undeclared-roles-note"', $component->html());
    }

    /** Review 62.3 #1 — `owner` ne se propose que sur `classe`. */
    #[Test]
    public function owner_is_only_offered_on_the_classe_type(): void
    {
        $this->grant(['server.admin']);

        $owner = UserGroupUserPivot::ROLE_OWNER;

        $this->assertArrayHasKey(
            $owner,
            Livewire::test(self::TAB)->call('openEditor', 'classe')->get('audienceOptions'),
        );
        $this->assertArrayNotHasKey(
            $owner,
            Livewire::test(self::TAB)->call('openEditor', 'projet')->get('audienceOptions'),
        );
    }

    #[Test]
    public function adding_an_audience_follows_the_seeded_pattern(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)
            ->call('openEditor', 'projet')
            ->set('pendingAudience', '@groupe')
            ->call('addAudience');

        $role = $component->get('rolesSpec')[0];

        // Le jeton du MENU est `@groupe` ; la clé STOCKÉE reste `groupe` (décision SM 2).
        $this->assertSame('groupe', $role['key']);
        $this->assertSame(UserGroup::class, $role['maille']);
        $this->assertSame('projet', $role['group_type']);
        $this->assertSame('one', $role['cardinality']);
        $this->assertSame([PlanGrant::VERB_LIRE], $role['verbs']);
        $this->assertSame(['strategy' => 'self'], $role['resolution']);

        $component->set('pendingAudience', 'manager')->call('addAudience');
        $second = $component->get('rolesSpec')[1];
        $this->assertSame(['strategy' => 'edge_role', 'edge_roles' => ['manager']], $second['resolution']);
    }

    #[Test]
    public function a_colliding_audience_key_is_refused(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)
            ->call('openEditor', 'projet')
            ->set('pendingAudience', '@groupe')
            ->call('addAudience')
            ->set('pendingAudience', '@groupe')
            ->call('addAudience');

        $this->assertCount(1, $component->get('rolesSpec'));
    }

    /**
     * Review 62.6 #2 — un rôle du catalogue peut légitimement s'appeler « Groupe » :
     * `GroupRole::KEY_PATTERN` n'a aucun mot réservé, et le slug est `groupe`.
     *
     * Il ne doit PAS écraser l'entrée « tout le groupe » du menu. Sans le jeton
     * préfixé `@groupe`, l'ajouter écrirait une résolution `self` à la place de
     * `edge_role` : une audience qui ne vise pas ce que l'administrateur a demandé,
     * stockée sans un mot.
     */
    #[Test]
    public function a_catalog_role_named_groupe_does_not_shadow_the_whole_group_audience(): void
    {
        $this->grant(['server.admin']);
        GroupRole::create(['key' => 'groupe', 'label' => 'Groupe', 'sort_order' => 95]);
        RoleCatalog::flush();

        $component = Livewire::test(self::TAB)->call('openEditor', 'projet');
        $options = $component->get('audienceOptions');

        $this->assertSame('Tout le groupe', $options['@groupe'] ?? null);
        $this->assertArrayHasKey('groupe', $options);
        $this->assertNotSame('Tout le groupe', $options['groupe']);

        // Le rôle du catalogue s'ajoute bien en rôle d'ARÊTE…
        $component->set('pendingAudience', 'groupe')->call('addAudience');
        $this->assertSame(
            ['strategy' => 'edge_role', 'edge_roles' => ['groupe']],
            $component->get('rolesSpec')[0]['resolution'],
        );

        // …et « tout le groupe » se heurte alors à la clé STOCKÉE déjà prise :
        // refus métier, pas une seconde audience homonyme (décision SM 2).
        $component->set('pendingAudience', '@groupe')->call('addAudience');
        $this->assertCount(1, $component->get('rolesSpec'));
    }

    /**
     * Review 62.6 #3 — AC7 : la clé est vérifiée AVANT l'écriture
     * (`where('key')->exists()`), donc deux soumissions concurrentes peuvent se
     * disputer la même clé. La perdante doit recevoir un message MÉTIER, jamais un
     * SQLSTATE brut, et ne rien écrire.
     *
     * La course est reproduite POUR DE VRAI, sans mock : la ligne rivale est
     * insérée depuis l'événement `creating`, c'est-à-dire dans la fenêtre exacte
     * qui sépare la vérification de l'insertion.
     */
    #[Test]
    public function a_race_on_the_recipe_key_is_refused_in_business_language(): void
    {
        $this->grant(['server.admin']);

        $before = DirectoryTemplate::count();

        DirectoryTemplate::creating(static function (DirectoryTemplate $template): void {
            static $raced = false;
            if ($raced) {
                return;
            }
            $raced = true;

            DirectoryTemplate::withoutEvents(static function () use ($template): void {
                $rival = new DirectoryTemplate;
                $rival->key = (string) $template->key;
                $rival->label = 'Recette concurrente';
                $rival->roles_spec = [];
                $rival->save();
            });
        });

        $component = Livewire::test(self::TAB)
            ->call('openEditor', 'projet')
            ->set('label', 'Arbre de projet')
            ->set('pathPattern', 'Projet_{group.bare_name}')
            ->set('pendingAudience', '@groupe')
            ->call('addAudience')
            ->call('addNode')
            ->set('nodesSpec.0.path', '.')
            ->set('nodesSpec.0.label', 'Racine')
            ->call('toggleVerb', 0, 'groupe', PlanGrant::VERB_LIRE)
            ->call('save')
            ->assertHasErrors('tree');

        $message = (string) ($component->errors()->first('tree') ?? '');
        $this->assertStringContainsString('modifiées ailleurs', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);

        // Seule la rivale a atterri : le refus n'écrit rien.
        $this->assertSame($before + 1, DirectoryTemplate::count());
        $this->assertNull(DirectoryTemplate::where('key', 'arbre_de_projet')->first()?->attachedGroupType());
    }

    #[Test]
    public function removing_an_audience_that_still_grants_is_refused_with_the_count(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)
            ->call('openEditor', 'classe')
            ->call('removeAudience', 'classe');

        $this->assertCount(2, $component->get('rolesSpec'), 'une audience portant des octrois a été retirée');
        $component->assertDispatched('toastMagic');
        $this->assertStringContainsString(
            '4 octrois',
            json_decode(json_encode($component->effects['dispatches'] ?? []) ?: '[]', true)[0]['params']['message'] ?? '',
        );
    }

    #[Test]
    public function unchecking_the_last_verb_removes_the_grant_instead_of_emptying_it(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');

        // `.` (racine) : l'octroi de `classe` ne porte que `lire`.
        $component->call('toggleVerb', 0, 'classe', PlanGrant::VERB_LIRE);

        $roles = array_column($component->get('nodesSpec')[0]['grants'], 'role');
        $this->assertSame(['equipe'], $roles, 'un octroi vide a été stocké au lieu d\'être retiré');
    }

    #[Test]
    public function checking_a_verb_on_a_bare_audience_creates_the_grant_at_the_seeded_shape(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');

        // `_profs` n'octroie rien à `classe`.
        $component->call('toggleVerb', 3, 'classe', PlanGrant::VERB_LIRE);

        $grant = $component->get('nodesSpec')[3]['grants'][1];
        $this->assertSame(['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE]], $grant);
    }

    // =========================================================================
    // AC5 — l'inexprimable : grisé ET expliqué, jamais réécrit
    // =========================================================================

    #[Test]
    public function supprimer_without_creer_is_greyed_and_explained(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');
        // `_profs` : on part d'un octroi neuf pour `classe`, en lecture seule.
        $component->call('toggleVerb', 3, 'classe', PlanGrant::VERB_LIRE);

        $cells = collect($component->get('editorNodes')[3]['columns'])
            ->firstWhere('role', 'classe')['cells'];
        $supprimer = collect($cells)->firstWhere('verb', PlanGrant::VERB_SUPPRIMER);

        $this->assertTrue($supprimer['disabled'], 'la case n\'est pas grisée');
        $this->assertStringContainsString('suppression sans la création', $supprimer['reason']);
        $this->assertStringNotContainsStringIgnoringCase('sticky', $supprimer['reason']);
    }

    /** Un appel forgé ne compose pas ce que le clic ne compose pas. */
    #[Test]
    public function a_forged_toggle_cannot_compose_the_inexpressible_combination(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');
        $component->call('toggleVerb', 3, 'classe', PlanGrant::VERB_LIRE);
        $component->call('toggleVerb', 3, 'classe', PlanGrant::VERB_SUPPRIMER);

        $grant = collect($component->get('nodesSpec')[3]['grants'])->firstWhere('role', 'classe');
        $this->assertSame([PlanGrant::VERB_LIRE], $grant['verbs']);
    }

    /**
     * **Le grisé ne DÉCOCHE jamais.** Une recette stockée portant `supprimer` sans
     * `creer` est valide (le vocabulaire du plan est neutre) : elle s'ouvre telle
     * quelle, marquée non exprimable, et reste décochable.
     */
    #[Test]
    public function a_stored_inexpressible_grant_is_shown_as_is_and_never_rewritten(): void
    {
        $this->grant(['server.admin']);

        $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();
        $nodes = $template->nodes_spec;
        $nodes[3]['grants'][] = ['role' => 'classe', 'verbs' => [PlanGrant::VERB_SUPPRIMER]];
        $template->nodes_spec = $nodes;
        $template->save();

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');

        $cells = collect($component->get('editorNodes')[3]['columns'])
            ->firstWhere('role', 'classe')['cells'];
        $supprimer = collect($cells)->firstWhere('verb', PlanGrant::VERB_SUPPRIMER);

        $this->assertTrue($supprimer['checked'], 'un octroi stocké a été décoché d\'office');
        $this->assertTrue($supprimer['inexpressible'], 'la case n\'est pas marquée non exprimable');
        $this->assertFalse($supprimer['disabled'], 'une case cochée doit rester décochable');
        $this->assertNotSame('', $supprimer['reason']);

        // Enregistrer une modification ANODINE ailleurs n'ampute pas cet octroi.
        $component->set('label', 'Classe (arbre de partage) — révisé')->call('save')->assertHasNoErrors();

        $reloaded = DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->firstOrFail();
        $this->assertSame(
            [PlanGrant::VERB_SUPPRIMER],
            collect($reloaded->nodes_spec[3]['grants'])->firstWhere('role', 'classe')['verbs'],
        );
    }

    #[Test]
    public function creer_without_supprimer_carries_a_declared_degradation_note(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');
        $nodes = $component->get('nodesSpec');
        // Un dépôt : lire + créer, sans supprimer, seul octroi du nœud.
        $nodes[3]['grants'] = [['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE, PlanGrant::VERB_CREER]]];
        $component->set('nodesSpec', $nodes);

        $notes = collect($component->get('editorNodes')[3]['columns'])->firstWhere('role', 'classe')['notes'];

        $this->assertNotSame([], $notes);
        $this->assertStringContainsString('retirer ses propres fichiers', implode(' ', $notes));
        $this->assertNull($component->get('editorNodes')[3]['node_note']);
    }

    #[Test]
    public function a_mixed_node_says_the_restriction_cannot_be_posed(): void
    {
        $this->grant(['server.admin']);

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');
        $nodes = $component->get('nodesSpec');
        $nodes[3]['grants'] = [
            ['role' => 'equipe', 'verbs' => PlanGrant::VERBS],
            ['role' => 'classe', 'verbs' => [PlanGrant::VERB_LIRE, PlanGrant::VERB_CREER]],
        ];
        $component->set('nodesSpec', $nodes);

        $node = $component->get('editorNodes')[3];

        $this->assertNotNull($node['node_note'], 'le nœud mixte ne dit rien');
        $this->assertStringContainsString('ne peut donc pas s\'y poser', $node['node_note']);

        // La case `creer` reste SAISISSABLE : ce n'est pas une limite permanente.
        $cells = collect($node['columns'])->firstWhere('role', 'classe')['cells'];
        $creer = collect($cells)->firstWhere('verb', PlanGrant::VERB_CREER);
        $this->assertFalse($creer['disabled']);
    }

    // =========================================================================
    // AC7 — les refus métier, et le fait qu'ils n'écrivent RIEN
    // =========================================================================

    /**
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'ancêtre non déclaré' => [
                static fn (array $nodes): array => [...$nodes, [
                    'path' => 'a/b/c', 'label' => 'Profond', 'nature' => 'partagee', 'grants' => [],
                ]],
                'inatteignable',
            ],
            'nœud sous un contenu libre' => [
                static fn (array $nodes): array => [...$nodes, [
                    'path' => '_travail/devoirs/rendus', 'label' => 'Rendus', 'nature' => 'partagee', 'grants' => [],
                ]],
                'n\'est pas gouverné par le plan',
            ],
            'clé d\'octroi inconnue' => [
                static function (array $nodes): array {
                    $nodes[1]['grants'][0]['priorite'] = 1;

                    return $nodes;
                },
                'champ(s) inconnu(s)',
            ],
            'placeholder inconnu' => [
                static function (array $nodes): array {
                    $nodes[1]['path'] = '{group.secret}';

                    return $nodes;
                },
                'placeholder inconnu',
            ],
            'nature inconnue' => [
                static function (array $nodes): array {
                    $nodes[1]['nature'] = 'coffre_fort';

                    return $nodes;
                },
                'nature inconnue',
            ],
            'plafond invalide' => [
                static function (array $nodes): array {
                    $nodes[1]['plafond'] = -3;

                    return $nodes;
                },
                'strictement positif',
            ],
            'suspendable hors nature activable' => [
                static function (array $nodes): array {
                    $nodes[1]['grants'][0]['suspendable'] = true;

                    return $nodes;
                },
                'suspendable',
            ],
            'deux octrois du même rôle' => [
                static function (array $nodes): array {
                    $nodes[1]['grants'][] = ['role' => 'equipe', 'verbs' => ['lire']];

                    return $nodes;
                },
                'deux octrois',
            ],
        ];
    }

    #[Test]
    #[DataProvider('refusalProvider')]
    public function an_invalid_recipe_is_refused_with_its_business_message_and_writes_nothing(
        callable $mutate,
        string $expected,
    ): void {
        $this->grant(['server.admin']);

        $before = $this->storedRow();

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');
        $component->set('nodesSpec', $mutate($component->get('nodesSpec')))->call('save');

        $errors = $component->errors()->get('tree');
        $this->assertNotSame([], $errors, 'aucun refus n\'a été rendu');
        $this->assertStringContainsString($expected, $errors[0]);

        $this->assertEquals($before, $this->storedRow(), 'un refus a écrit');
    }

    /**
     * Règle 4 de 62.5 : un dossier par membre dont l'ancêtre n'octroie rien à une
     * audience qui contient ces membres.
     */
    #[Test]
    public function a_per_member_node_without_a_covering_ancestor_is_refused(): void
    {
        $this->grant(['server.admin']);

        $before = $this->storedRow();

        $component = Livewire::test(self::TAB)->call('openEditor', 'classe');
        $nodes = $component->get('nodesSpec');
        // La racine n'octroie plus rien à l'audience du groupe entier.
        $nodes[0]['grants'] = [['role' => 'equipe', 'verbs' => ['lire']]];
        $component->set('nodesSpec', $nodes)->call('save');

        $this->assertStringContainsString('inatteignable', $component->errors()->get('tree')[0]);
        $this->assertEquals($before, $this->storedRow());
    }

    /** 60.5/62.2 — un type ne porte qu'UNE recette d'arbre. */
    #[Test]
    public function attaching_a_second_tree_to_a_type_is_refused(): void
    {
        $this->grant(['server.admin']);

        // L'écran ouvre `projet` en CRÉATION (aucun arbre)… puis un arbre arrive
        // par un autre chemin avant l'enregistrement. C'est exactement la course
        // que le check-then-act du modèle doit perdre proprement.
        $component = Livewire::test(self::TAB)
            ->call('openEditor', 'projet')
            ->assertSet('editId', null)
            ->set('label', 'Second arbre')
            ->set('newKey', 'projet_arbre_2')
            ->set('pathPattern', 'Projet2_{group.bare_name}');

        DirectoryTemplate::create([
            'key' => 'projet_arbre_1',
            'label' => 'Arbre projet',
            'roles_spec' => [],
            'path_pattern' => 'Projet_{group.bare_name}',
            'nodes_spec' => [],
            'attached_group_type' => 'projet',
            'root_anchor' => 'classes',
        ]);

        $count = DirectoryTemplate::count();

        $component->call('save');

        $this->assertStringContainsString('porte déjà la recette d\'arbre', $component->errors()->get('tree')[0]);
        $this->assertSame($count, DirectoryTemplate::count(), 'un refus a créé une recette');
    }

    // =========================================================================
    // AC9 — enregistrer arme la matérialisation FUTURE, et n'exécute rien
    // =========================================================================

    #[Test]
    public function saving_writes_the_recipe_and_triggers_no_materialisation(): void
    {
        $this->grant(['server.admin']);

        UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $shares = NetworkShare::count();

        // La création du groupe, elle, a bien enfilé sa réconciliation (story
        // 60.5) : on repart d'une file NEUVE pour ne mesurer que l'enregistrement.
        Queue::fake();

        $component = Livewire::test(self::TAB)
            ->call('openEditor', 'classe')
            ->set('label', 'Classe (arbre révisé)')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('isEditorOpen', false);

        $this->assertSame(
            'Classe (arbre révisé)',
            (string) DirectoryTemplate::where('key', DirectoryTemplate::KEY_CLASSE_SE4)->value('label'),
        );

        $this->assertSame($shares, NetworkShare::count(), 'l\'enregistrement a matérialisé un partage');
        Queue::assertNothingPushed();
        $this->assertNotNull($component);
    }

    #[Test]
    public function the_screen_says_that_future_groups_will_carry_the_tree(): void
    {
        $this->grant(['server.admin']);

        $html = Livewire::test(self::TAB)->call('openEditor', 'classe')->html();

        $this->assertStringContainsString('data-testid="materialization-notice"', $html);
        $this->assertStringContainsString('Les groupes', $html);
    }

    /** Créer une arborescence pour un type nu, en un seul geste. */
    #[Test]
    public function creating_a_tree_for_a_bare_type_attaches_it_in_one_gesture(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::TAB)
            ->call('openEditor', 'projet')
            ->set('label', 'Arbre de projet')
            ->set('pathPattern', 'Projet_{group.bare_name}')
            ->set('pendingAudience', '@groupe')
            ->call('addAudience')
            ->call('addNode')
            ->set('nodesSpec.0.path', '.')
            ->set('nodesSpec.0.label', 'Racine')
            ->call('toggleVerb', 0, 'groupe', PlanGrant::VERB_LIRE)
            ->call('save')
            ->assertHasNoErrors();

        $created = DirectoryTemplate::where('key', 'arbre_de_projet')->firstOrFail();

        $this->assertSame('projet', $created->attachedGroupType());
        $this->assertTrue($created->materializesOnGroupCreation());
        $this->assertSame('classes', (string) $created->root_anchor);
    }
}
