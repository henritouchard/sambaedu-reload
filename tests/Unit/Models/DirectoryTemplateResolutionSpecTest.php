<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\RoleResolutionStrategy;
use App\Exceptions\Filesystem\InvalidTreeSpecException;
use App\Models\DirectoryTemplate;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Plan\ClassTreeRecipe;

/**
 * Story 60.2 — la RÈGLE par laquelle un rôle trouve sa cible, et l'ACCROCHAGE
 * d'une recette à un type de groupe.
 *
 * Deux vocabulaires fermés de plus, validés là où vivent déjà les autres : sur le
 * modèle. Une recette est acceptée ou rejetée en entier — jamais à moitié
 * comprise.
 */
class DirectoryTemplateResolutionSpecTest extends TestCase
{
    use ClassTreeRecipe;
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_04_150000_add_attached_group_type_to_directory_templates.php';

    /** Story 60.5 — la détente de l'unicité, empilée sur la précédente. */
    private const RELAX_MIGRATION = 'database/migrations/2026_08_05_110000_relax_attached_group_type_uniqueness.php';

    /**
     * Recette d'épreuve : un seul rôle, dont on fait varier la règle.
     *
     * @param  array<string, mixed>|null  $resolution
     */
    private function templateWithResolution(?array $resolution, string $maille = UserGroup::class): DirectoryTemplate
    {
        $role = [
            'key' => 'cible',
            'label' => 'Cible',
            'maille' => $maille,
            'group_type' => null,
            'access' => 'rw',
            'cardinality' => 'one',
        ];

        if ($resolution !== null) {
            $role['resolution'] = $resolution;
        }

        return new DirectoryTemplate([
            'key' => 'epreuve',
            'label' => 'Recette d\'épreuve',
            'roles_spec' => [$role],
        ]);
    }

    // =========================================================================
    // La migration : additive, nullable, réversible
    // =========================================================================

    /**
     * Story 60.5 — l'accrochage n'est plus l'exception absolue qu'il était.
     *
     * Deux recettes s'accrochent désormais au type `classe` : l'ARBRE de partage de
     * classe, et la recette PLATE « profs → élèves » réparée. Les trois autres
     * restent muettes. Le test dit l'état exact plutôt qu'une règle globale qui a
     * cessé d'être vraie.
     */
    #[Test]
    public function the_column_exists_and_only_the_class_recipes_are_attached(): void
    {
        $this->assertTrue(Schema::hasColumn('directory_templates', 'attached_group_type'));

        (new DirectoryTemplateSeeder())->run();

        $this->assertSame(5, DirectoryTemplate::count());

        $attached = DirectoryTemplate::whereNotNull('attached_group_type')
            ->orderBy('key')
            ->pluck('attached_group_type', 'key')
            ->all();

        $this->assertSame(
            [
                DirectoryTemplate::KEY_CLASSE_SE4 => 'classe',
                DirectoryTemplate::KEY_PROFS_TO_ELEVES => 'classe',
            ],
            $attached,
        );
    }

    /**
     * Réversibilité — DANS L'ORDRE, et c'est le point.
     *
     * La story 60.5 a détendu l'unicité posée par la 60.2 : rejouer le `down()` de
     * la 60.2 sans avoir d'abord défait la 60.5 chercherait un index unique qui
     * n'existe plus. Une réversibilité qui ne vaudrait qu'en sautant une marche
     * n'en est pas une.
     */
    #[Test]
    public function the_migration_is_reversible(): void
    {
        $attachment = require base_path(self::MIGRATION);
        $relaxation = require base_path(self::RELAX_MIGRATION);

        $relaxation->down();
        $attachment->down();
        $this->assertFalse(Schema::hasColumn('directory_templates', 'attached_group_type'));

        $attachment->up();
        $relaxation->up();
        $this->assertTrue(Schema::hasColumn('directory_templates', 'attached_group_type'));

        // Les données ont survécu au va-et-vient.
        (new DirectoryTemplateSeeder())->run();
        $this->assertSame(5, DirectoryTemplate::count());
    }

    // =========================================================================
    // Le DÉFAUT : l'absence de règle vaut « cible désignée » (iso-34.3)
    // =========================================================================

    /**
     * Story 60.5 — les TROIS recettes que cette story ne touche pas restent en
     * cible désignée, mot pour mot.
     *
     * Les deux autres changent, et chacune a son test : « profs → élèves » est
     * RÉPARÉE (elle contraignait un type de groupe qui n'existe plus), et l'arbre
     * de classe est NEUF.
     */
    #[Test]
    public function the_untouched_seeded_recipes_stay_valid_and_designated_without_any_change(): void
    {
        (new DirectoryTemplateSeeder())->run();

        $untouched = [
            DirectoryTemplate::KEY_DIRECTION_TO_ALL,
            DirectoryTemplate::KEY_USER_TO_USER,
            DirectoryTemplate::KEY_GROUP_SPACE,
        ];

        foreach (DirectoryTemplate::whereIn('key', $untouched)->get() as $template) {
            $template->assertValidResolutionSpec();
            $template->assertValidTreeSpec();

            foreach ($template->roles() as $role) {
                $this->assertSame(
                    RoleResolutionStrategy::Designated,
                    $template->resolutionOf($role)['strategy'],
                    "La recette {$template->key} doit rester en cible désignée.",
                );
            }

            // Non auto-résolvables, donc non accrochées : elles se matérialisent à
            // la main, avec leurs cibles saisies. C'est exactement l'état voulu.
            $this->assertFalse($template->isAutoResolvable());
            $this->assertNull($template->attachedGroupType());
        }
    }

    /** Story 60.5 — les deux recettes accrochées savent, elles, se résoudre seules. */
    #[Test]
    public function both_class_recipes_resolve_their_targets_on_their_own(): void
    {
        (new DirectoryTemplateSeeder())->run();

        foreach ([DirectoryTemplate::KEY_PROFS_TO_ELEVES, DirectoryTemplate::KEY_CLASSE_SE4] as $key) {
            $template = DirectoryTemplate::where('key', $key)->firstOrFail();

            $template->assertValidResolutionSpec();
            $template->assertValidTreeSpec();
            $this->assertTrue($template->isAutoResolvable(), "La recette {$key} doit se résoudre seule.");
            $this->assertSame('classe', $template->attachedGroupType());
        }
    }

    #[Test]
    public function a_user_maille_role_stays_resolvable_as_a_designated_target(): void
    {
        // NON-RÉGRESSION NOMMÉE. Le correctif « rejeter les sujets utilisateur »
        // proposé en revue de la story 60.1 casserait cette recette LIVRÉE : ses
        // deux rôles sont de maille utilisateur, cardinalité un. La garde de la
        // mesure porte sur l'ÉNUMÉRATION d'une audience, pas sur le type du sujet.
        (new DirectoryTemplateSeeder())->run();

        $template = DirectoryTemplate::where('key', DirectoryTemplate::KEY_USER_TO_USER)->firstOrFail();

        $template->assertValidResolutionSpec();

        foreach ($template->roles() as $role) {
            $this->assertSame(User::class, $role['maille']);
            $this->assertSame(RoleResolutionStrategy::Designated, $template->resolutionOf($role)['strategy']);
        }
    }

    // =========================================================================
    // Le vocabulaire FERMÉ des règles
    // =========================================================================

    #[Test]
    public function an_unknown_strategy_is_refused(): void
    {
        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/stratégie inconnue/u');

        $this->templateWithResolution(['strategy' => 'devine'])->assertValidResolutionSpec();
    }

    #[Test]
    public function a_missing_strategy_is_refused(): void
    {
        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/stratégie inconnue/u');

        $this->templateWithResolution(['edge_roles' => ['manager']])->assertValidResolutionSpec();
    }

    #[Test]
    public function a_resolution_that_is_not_a_structure_is_refused(): void
    {
        $role = [
            'key' => 'cible',
            'label' => 'Cible',
            'maille' => UserGroup::class,
            'access' => 'rw',
            'cardinality' => 'one',
            'resolution' => 'self',
        ];

        $template = new DirectoryTemplate(['key' => 'epreuve', 'label' => 'X', 'roles_spec' => [$role]]);

        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/doit être une structure/u');

        $template->assertValidResolutionSpec();
    }

    #[Test]
    public function an_unknown_key_inside_a_resolution_is_refused(): void
    {
        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/champ\(s\) inconnu\(s\)/u');

        $this->templateWithResolution([
            'strategy' => 'self',
            'edge_roles' => ['manager'],
        ])->assertValidResolutionSpec();
    }

    #[Test]
    public function the_group_strategies_require_a_group_maille(): void
    {
        foreach ([['strategy' => 'self'], ['strategy' => 'edge_role', 'edge_roles' => ['manager']], ['strategy' => 'pattern', 'pattern' => 'Equipe_{group.bare_name}']] as $resolution) {
            try {
                $this->templateWithResolution($resolution, User::class)->assertValidResolutionSpec();
                $this->fail('la maille utilisateur aurait dû être refusée pour « ' . $resolution['strategy'] . ' »');
            } catch (InvalidTreeSpecException $e) {
                $this->assertMatchesRegularExpression('/exige la maille/u', $e->getMessage());
            }
        }
    }

    #[Test]
    public function an_edge_role_strategy_needs_a_non_empty_list_of_known_roles(): void
    {
        foreach ([[], 'manager', ['prof'], ['manager', 'prof_principal'], [null]] as $edgeRoles) {
            try {
                $this->templateWithResolution([
                    'strategy' => 'edge_role',
                    'edge_roles' => $edgeRoles,
                ])->assertValidResolutionSpec();
                $this->fail('liste de rôles d\'arête acceptée à tort : ' . json_encode($edgeRoles));
            } catch (InvalidTreeSpecException $e) {
                $this->assertMatchesRegularExpression('/rôle d\'arête/u', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_duplicated_edge_role_is_refused(): void
    {
        // Un doublon émettrait DEUX FOIS le même sujet abstrait : le plan
        // porterait deux octrois identiques, et la comparaison d'état s'en
        // trouverait bruitée pour rien.
        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/deux fois/u');

        $this->templateWithResolution([
            'strategy' => 'edge_role',
            'edge_roles' => ['manager', 'manager'],
        ])->assertValidResolutionSpec();
    }

    #[Test]
    public function a_valid_edge_role_resolution_is_normalized(): void
    {
        $template = $this->templateWithResolution([
            'strategy' => 'edge_role',
            'edge_roles' => ['manager', 'owner'],
        ]);

        $resolution = $template->resolutionForRole('cible');

        $this->assertSame(RoleResolutionStrategy::EdgeRole, $resolution['strategy']);
        $this->assertSame(['manager', 'owner'], $resolution['edge_roles']);
        $this->assertNull($resolution['pattern']);
    }

    #[Test]
    public function a_pattern_strategy_needs_a_pattern_of_the_closed_vocabulary(): void
    {
        foreach ([null, '', '   ', 42] as $bad) {
            try {
                $this->templateWithResolution(array_filter(
                    ['strategy' => 'pattern', 'pattern' => $bad],
                    static fn ($v): bool => $v !== null,
                ))->assertValidResolutionSpec();
                $this->fail('motif accepté à tort : ' . var_export($bad, true));
            } catch (InvalidTreeSpecException $e) {
                $this->assertMatchesRegularExpression('/motif de nom/u', $e->getMessage());
            }
        }

        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/placeholder inconnu/u');

        $this->templateWithResolution([
            'strategy' => 'pattern',
            'pattern' => 'Equipe_{group.matiere}',
        ])->assertValidResolutionSpec();
    }

    #[Test]
    public function a_valid_pattern_resolution_is_normalized(): void
    {
        $resolution = $this->templateWithResolution([
            'strategy' => 'pattern',
            'pattern' => 'Equipe_{group.bare_name}',
        ])->resolutionForRole('cible');

        $this->assertSame(RoleResolutionStrategy::Pattern, $resolution['strategy']);
        $this->assertSame('Equipe_{group.bare_name}', $resolution['pattern']);
        $this->assertSame([], $resolution['edge_roles']);
    }

    #[Test]
    public function asking_for_an_absent_role_fails_explicitly(): void
    {
        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/absent de la recette/u');

        $this->templateWithResolution(null)->resolutionForRole('fantome');
    }

    #[Test]
    public function the_tree_validation_also_validates_the_resolution_rules(): void
    {
        // Les deux volets de la MÊME recette : celle qu'on s'apprête à résoudre
        // doit être valide des deux côtés, sans quoi la stratégie invalide ne se
        // découvrirait qu'à l'exécution.
        $template = $this->autoResolvableClassTreeTemplate();
        $roles = $template->roles_spec;
        $roles[0]['resolution']['strategy'] = 'devine';
        $template->roles_spec = $roles;

        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/stratégie inconnue/u');

        $template->assertValidTreeSpec();
    }

    // =========================================================================
    // L'accrochage à un type de groupe
    // =========================================================================

    #[Test]
    public function an_auto_resolvable_recipe_attaches_and_is_found_by_type(): void
    {
        $template = $this->autoResolvableClassTreeTemplate();
        $template->save();

        $this->assertTrue($template->isAutoResolvable());
        $this->assertSame('classe', $template->attachedGroupType());

        $found = DirectoryTemplate::attachedTo('classe');
        $this->assertNotNull($found);
        $this->assertSame($template->id, $found->id);
    }

    #[Test]
    public function a_type_without_any_attached_recipe_yields_null(): void
    {
        $this->autoResolvableClassTreeTemplate()->save();

        $this->assertNull(DirectoryTemplate::attachedTo('projet'));
        $this->assertNull(DirectoryTemplate::attachedTo(''));
        $this->assertNull(DirectoryTemplate::attachedTo('  '));
    }

    #[Test]
    public function a_recipe_with_a_designated_role_cannot_attach(): void
    {
        // Créer un groupe n'ouvre aucun formulaire : ce qu'une recette accrochée
        // ne sait pas déduire, personne ne le lui fournira.
        $template = $this->autoResolvableClassTreeTemplate();
        $roles = $template->roles_spec;
        unset($roles[0]['resolution']); // ⇒ défaut « cible désignée »
        $template->roles_spec = $roles;

        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/matérialisation/u');

        $template->assertAttachable();
    }

    /**
     * Story 60.5 — RENVERSEMENT ASSUMÉ de la règle de 60.2.
     *
     * Une recette SANS arbre peut désormais s'accrocher : l'accrochage dit « je
     * sais trouver mes cibles à partir d'un groupe de ce type », ce qui est vrai
     * d'un partage plat comme d'un arbre. C'est ce qui rend « profs → élèves »
     * réparable. Ce que l'accrochage ne lui donne PAS, c'est la matérialisation
     * automatique — sinon chaque classe créée naîtrait avec un partage plat que
     * personne n'a demandé.
     */
    #[Test]
    public function a_treeless_recipe_may_attach_but_never_materializes_by_itself(): void
    {
        $template = $this->templateWithResolution(['strategy' => 'self']);
        $template->attached_group_type = 'classe';

        $template->assertAttachable();
        $template->save();

        $this->assertSame('classe', $template->fresh()->attachedGroupType());
        $this->assertFalse(
            $template->materializesOnGroupCreation(),
            'une recette plate accrochée ne doit JAMAIS se matérialiser à la création d\'un groupe',
        );

        // Et elle n'est pas ce que la chaîne groupe → arbre va chercher.
        $this->assertNull(DirectoryTemplate::attachedTo('classe'));
    }

    #[Test]
    public function an_unattachable_recipe_cannot_even_be_persisted_with_an_attachment(): void
    {
        // La donnée d'accrochage n'a pas d'écran : la garde est à l'écriture, au
        // seul endroit par lequel toute écriture passe. Ce qui reste inattachable,
        // c'est une recette dont un rôle attend une SAISIE : à la création d'un
        // groupe comme au flux à un seul sélecteur, personne n'est là pour la
        // fournir.
        $template = $this->templateWithResolution(null);
        $template->attached_group_type = 'classe';

        $this->expectException(InvalidTreeSpecException::class);

        $template->save();
    }

    /**
     * Story 60.5 — l'unicité SURVIT, rétrécie à ce qu'elle visait vraiment.
     *
     * Deux recettes d'ARBRE sur le même type poseraient la question à laquelle rien
     * ne peut répondre : quel arbre ce groupe matérialise-t-il ? La garde est
     * désormais applicative — et elle NOMME la recette qui occupe déjà la place, ce
     * qu'un index n'aurait jamais su faire.
     */
    #[Test]
    public function two_tree_recipes_cannot_be_attached_to_the_same_type(): void
    {
        $this->autoResolvableClassTreeTemplate()->save();

        $second = $this->autoResolvableClassTreeTemplate();
        $second->key = 'classe_share_auto_bis';

        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/porte déjà la recette d\'arbre/u');

        $second->save();
    }

    /** Mais un arbre et un partage plat cohabitent parfaitement sur le même type. */
    #[Test]
    public function a_tree_recipe_and_a_flat_one_may_share_the_same_attached_type(): void
    {
        $this->autoResolvableClassTreeTemplate()->save();

        $flat = $this->templateWithResolution(['strategy' => 'self']);
        $flat->attached_group_type = 'classe';
        $flat->save();

        $this->assertSame(2, DirectoryTemplate::where('attached_group_type', 'classe')->count());
        $this->assertSame('classe_share_auto', DirectoryTemplate::attachedTo('classe')?->key);
    }

    #[Test]
    public function several_recipes_may_stay_unattached(): void
    {
        // L'accrochage reste l'exception : trois des cinq recettes seedées ne se
        // prononcent pas, et se matérialisent avec leurs cibles saisies.
        (new DirectoryTemplateSeeder())->run();

        $this->assertSame(3, DirectoryTemplate::whereNull('attached_group_type')->count());
    }
}
