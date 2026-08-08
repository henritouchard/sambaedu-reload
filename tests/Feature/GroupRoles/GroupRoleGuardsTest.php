<?php

declare(strict_types=1);

namespace Tests\Feature\GroupRoles;

use App\Exceptions\Filesystem\InvalidTreeSpecException;
use App\Exceptions\Filesystem\PlanResolutionException;
use App\Models\DirectoryTemplate;
use App\Models\GroupRole;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\Plan\GroupNameNormalizer;
use App\Services\Filesystem\Plan\PlanResolutionContext;
use App\Services\Filesystem\Plan\PlanResolver;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Support\RoleCatalog;
use Database\Seeders\GroupRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Plan\ClassTreeRecipe;

/**
 * Story 62.1 — LES DEUX GARDES DU CATALOGUE.
 *
 *  1. **La clé est immuable, et la suppression REFUSE au lieu de cascader.** Un
 *     rôle porté par des arêtes ou visé par une recette n'est pas supprimable, et
 *     le refus NOMME le décompte. Aucune écriture n'a lieu sur un refus — ni sur le
 *     rôle, ni sur les arêtes, ni sur les recettes.
 *  2. **Un renommage de libellé ne touche AUCUNE donnée dérivée.** C'est la
 *     matérialisation exécutable de la séparation clé/libellé : le plan résolu d'un
 *     groupe de classe est identique OCTET POUR OCTET avant et après renommage.
 */
class GroupRoleGuardsTest extends TestCase
{
    use ClassTreeRecipe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GroupRoleSeeder::class);

        // Le décor crée des groupes et des arêtes : la projection d'annuaire n'a
        // rien à faire ici (aucun AD sur l'hôte de test), patron des tests de
        // groupes existants.
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    private function role(string $key): GroupRole
    {
        return GroupRole::where('key', $key)->firstOrFail();
    }

    // =========================================================================
    // La clé est IMMUABLE
    // =========================================================================

    #[Test]
    public function the_key_cannot_be_changed_once_the_role_exists(): void
    {
        $role = GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        $role->key = 'tuteur_bis';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/immuable/');
        $role->save();
    }

    #[Test]
    public function a_malformed_or_oversized_key_is_refused(): void
    {
        foreach (['Tuteur', '2tuteurs', 'tuteur-bis', 'tuteur bis', '', str_repeat('a', 21)] as $key) {
            try {
                GroupRole::create(['key' => $key, 'label' => 'X', 'sort_order' => 9]);
                $this->fail('Clé acceptée à tort : « ' . $key . ' »');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function the_label_alone_is_freely_editable(): void
    {
        $role = $this->role('manager');
        $role->label = 'Encadrant';
        $role->save();

        $this->assertSame('manager', $role->fresh()->key);
        $this->assertSame('Encadrant', $role->fresh()->label);
    }

    #[Test]
    public function slugify_bounds_the_key_to_the_edge_column_width(): void
    {
        $slug = GroupRole::slugify('Référent numérique de circonscription');

        $this->assertLessThanOrEqual(GroupRole::KEY_MAX_LENGTH, strlen($slug));
        $this->assertMatchesRegularExpression(GroupRole::KEY_PATTERN, $slug);
    }

    // =========================================================================
    // AC4 — un rôle NOUVEAU du catalogue traverse le plan et les recettes
    // =========================================================================

    /**
     * C'est la conséquence visible de la couture de pureté : le namespace du plan
     * reçoit son vocabulaire par injection, donc une clé ajoutée au catalogue est
     * connue de la résolution SANS qu'aucune constante n'ait été touchée.
     */
    #[Test]
    public function a_catalogued_role_crosses_the_plan_without_rejection(): void
    {
        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);
        RoleCatalog::flush();

        $this->assertTrue(GroupNameNormalizer::isKnownEdgeRole('tuteur'));

        // Un sujet d'octroi qualifié par ce rôle est accepté…
        $subject = PlanSubject::group(7, 'tuteur');
        $this->assertSame('tuteur', $subject->toArray()['edge_role']);

        // …et un membre du contexte de résolution aussi.
        $context = new PlanResolutionContext(
            groupId: 7,
            groupName: 'Classe_3emeA',
            groupType: 'classe',
            members: [['id' => 101, 'login' => 'alecoz', 'edge_role' => 'tuteur']],
            roleTargets: [],
        );
        $this->assertSame([101], array_column($context->membersWithEdgeRole('tuteur'), 'id'));
    }

    #[Test]
    public function an_uncatalogued_role_is_still_rejected_by_the_plan(): void
    {
        $this->expectException(PlanResolutionException::class);
        PlanSubject::group(7, 'inconnu');
    }

    /**
     * AC4 — les DEUX points de validation d'une recette suivent le catalogue.
     */
    #[Test]
    public function a_recipe_accepts_a_catalogued_role_and_refuses_an_unknown_one(): void
    {
        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);
        RoleCatalog::flush();

        $accepted = new DirectoryTemplate([
            'key' => 'avec_tuteur',
            'label' => 'Avec tuteur',
            'roles_spec' => [[
                'key' => 'audience',
                'label' => 'Audience',
                'maille' => UserGroup::class,
                'group_type' => 'classe',
                'access' => 'rw',
                'cardinality' => 'one',
                'resolution' => ['strategy' => 'edge_role', 'edge_roles' => ['tuteur']],
            ]],
            'path_pattern' => 'Classes/Classe_{group.bare_name}',
            'nodes_spec' => [[
                'path' => '{member.login}',
                'label' => 'Dossier personnel',
                'nature' => 'par_membre',
                'edge_role' => 'tuteur',
                'grants' => [['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'access' => 'rw']],
            ]],
        ]);

        $accepted->assertValidResolutionSpec();
        $accepted->assertValidTreeSpec();
        $this->addToAssertionCount(1);

        $refused = new DirectoryTemplate([
            'key' => 'avec_inconnu',
            'label' => 'Avec inconnu',
            'roles_spec' => [[
                'key' => 'audience',
                'label' => 'Audience',
                'maille' => UserGroup::class,
                'group_type' => 'classe',
                'access' => 'rw',
                'cardinality' => 'one',
                'resolution' => ['strategy' => 'edge_role', 'edge_roles' => ['inconnu']],
            ]],
        ]);

        $this->expectException(InvalidTreeSpecException::class);
        $refused->assertValidResolutionSpec();
    }

    /**
     * AC11 — les CINQ recettes seedées restent valides : « aucune recette ne
     * casse » n'est pas une intention, c'est une assertion.
     */
    #[Test]
    public function the_five_seeded_recipes_remain_valid(): void
    {
        $this->seed(\Database\Seeders\DirectoryTemplateSeeder::class);

        $templates = DirectoryTemplate::all();
        $this->assertCount(5, $templates);

        foreach ($templates as $template) {
            $template->assertValidResolutionSpec();
            $template->assertValidTreeSpec();
        }
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // La suppression REFUSE, elle ne cascade jamais
    // =========================================================================

    #[Test]
    public function an_unused_new_role_is_deletable(): void
    {
        $role = GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        $this->assertNull($role->deletionRefusal());
    }

    #[Test]
    public function the_three_historical_roles_are_never_deletable_even_unused(): void
    {
        foreach (['member', 'manager', 'owner'] as $key) {
            $refusal = $this->role($key)->deletionRefusal();

            $this->assertNotNull($refusal, 'le rôle structurel « ' . $key . ' » devrait être protégé');
            $this->assertStringContainsString('structurel', $refusal);
            $this->assertStringContainsString($key, $refusal);
        }
    }

    #[Test]
    public function a_role_carried_by_edges_is_refused_with_its_count(): void
    {
        $role = GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);

        foreach (['u1', 'u2', 'u3'] as $login) {
            $user = User::create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
            DB::table('user_group_user')->insert([
                'user_id' => $user->id,
                'user_group_id' => $group->id,
                'role' => 'tuteur',
            ]);
        }

        $refusal = $role->deletionRefusal();

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('3 appartenances', $refusal);
        $this->assertSame(['edges' => 3, 'templates' => 0, 'group_types' => 1], $role->usage());

        // AUCUNE écriture n'a eu lieu : le rôle est toujours là, les arêtes aussi.
        $this->assertTrue(GroupRole::where('key', 'tuteur')->exists());
        $this->assertSame(3, DB::table('user_group_user')->where('role', 'tuteur')->count());
    }

    #[Test]
    public function a_role_targeted_by_a_recipe_is_refused_in_both_stored_forms(): void
    {
        $role = GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        // Forme 1 — l'audience d'un rôle de recette.
        DirectoryTemplate::create([
            'key' => 'via_edge_roles',
            'label' => 'Via edge_roles',
            'roles_spec' => [[
                'key' => 'audience',
                'label' => 'Audience',
                'maille' => UserGroup::class,
                'group_type' => 'classe',
                'access' => 'rw',
                'cardinality' => 'one',
                'resolution' => ['strategy' => 'edge_role', 'edge_roles' => ['tuteur']],
            ]],
        ]);

        // Forme 2 — le rôle d'arête qui peuple un nœud par membre.
        DirectoryTemplate::create([
            'key' => 'via_node_edge_role',
            'label' => 'Via nodes_spec',
            'roles_spec' => [],
            'nodes_spec' => [[
                'path' => '{member.login}',
                'label' => 'Dossier personnel',
                'nature' => 'par_membre',
                'edge_role' => 'tuteur',
                'grants' => [['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'access' => 'rw']],
            ]],
        ]);

        $refusal = $role->deletionRefusal();

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('2 recettes', $refusal);
        $this->assertSame(2, GroupRole::countTemplates('tuteur'));
    }

    /**
     * PIÈGE NOMMÉ : les clés LOCALES de `roles_spec` (`profs`, `eleves`, `classe`…)
     * ne référencent PAS le catalogue en 62.1. Les compter serait un faux positif
     * qui bloquerait une suppression parfaitement légitime.
     */
    #[Test]
    public function a_local_recipe_role_key_is_never_counted_as_a_catalog_reference(): void
    {
        GroupRole::create(['key' => 'classe', 'label' => 'Classe', 'sort_order' => 9]);

        DirectoryTemplate::create([
            'key' => 'homonyme',
            'label' => 'Homonymie de clé locale',
            'roles_spec' => [[
                // La clé LOCALE vaut « classe » — homonyme du rôle catalogué.
                'key' => 'classe',
                'label' => 'Élèves de la classe',
                'maille' => UserGroup::class,
                'group_type' => 'classe',
                'access' => 'ro',
                'cardinality' => 'one',
                'resolution' => ['strategy' => 'self'],
            ]],
        ]);

        $this->assertSame(0, GroupRole::countTemplates('classe'));
        $this->assertNull($this->role('classe')->deletionRefusal());
    }

    #[Test]
    public function the_seeded_recipes_are_counted_on_the_historical_keys(): void
    {
        $this->seed(\Database\Seeders\DirectoryTemplateSeeder::class);

        // 3 recettes visent `manager` (profs_to_eleves, classe_se4 en `edge_roles`)
        // ou `member` (classe_se4 en `nodes_spec`) : ce qui compte ici, c'est qu'un
        // rôle réellement visé ne soit jamais rendu supprimable.
        $this->assertGreaterThan(0, GroupRole::countTemplates('manager'));
        $this->assertGreaterThan(0, GroupRole::countTemplates('member'));
    }

    // =========================================================================
    // Un renommage ne touche AUCUNE donnée dérivée
    // =========================================================================

    #[Test]
    public function renaming_a_label_leaves_edges_recipes_and_the_resolved_plan_untouched(): void
    {
        $this->seed(\Database\Seeders\DirectoryTemplateSeeder::class);

        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $user = User::create(['login' => 'alecoz', 'role' => 'prof', 'is_active' => true]);
        DB::table('user_group_user')->insert([
            'user_id' => $user->id,
            'user_group_id' => $group->id,
            'role' => 'manager',
        ]);

        $edgesBefore = DB::table('user_group_user')->orderBy('user_id')->get()->toJson();
        $templatesBefore = DirectoryTemplate::orderBy('key')->get(['key', 'roles_spec', 'nodes_spec'])->toJson();

        $resolver = app(PlanResolver::class);
        $planBefore = $resolver->resolve($this->classTreeTemplate(), $this->classTreeContext())->toJson();

        // LE renommage.
        $manager = $this->role('manager');
        $manager->label = 'Encadrant';
        $manager->save();

        $this->assertSame('Encadrant', $this->role('manager')->label);
        $this->assertSame('manager', $this->role('manager')->key);

        // Rien d'autre n'a bougé.
        $this->assertSame(
            $edgesBefore,
            DB::table('user_group_user')->orderBy('user_id')->get()->toJson(),
            'un renommage de libellé a modifié une arête',
        );
        $this->assertSame(
            $templatesBefore,
            DirectoryTemplate::orderBy('key')->get(['key', 'roles_spec', 'nodes_spec'])->toJson(),
            'un renommage de libellé a modifié une recette',
        );

        RoleCatalog::flush();
        $planAfter = $resolver->resolve($this->classTreeTemplate(), $this->classTreeContext())->toJson();

        $this->assertSame(
            $planBefore,
            $planAfter,
            'le plan résolu doit être identique OCTET POUR OCTET : seule la clé est dérivée, jamais le libellé',
        );
    }
}
