<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\RoleResolutionStrategy;
use App\Exceptions\Filesystem\InvalidTreeSpecException;
use App\Models\DirectoryTemplate;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\DirectoryTemplateSeeder;
use Illuminate\Database\QueryException;
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

    #[Test]
    public function the_column_exists_and_the_seeded_recipes_stay_unattached(): void
    {
        $this->assertTrue(Schema::hasColumn('directory_templates', 'attached_group_type'));

        (new DirectoryTemplateSeeder())->run();

        $this->assertSame(4, DirectoryTemplate::count());

        foreach (DirectoryTemplate::all() as $template) {
            $this->assertNull($template->attached_group_type, "La recette {$template->key} ne doit être accrochée à rien.");
            $this->assertNull($template->attachedGroupType());
        }
    }

    #[Test]
    public function the_migration_is_reversible(): void
    {
        $migration = require base_path(self::MIGRATION);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('directory_templates', 'attached_group_type'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('directory_templates', 'attached_group_type'));

        // Les données de 34.3 ont survécu au va-et-vient.
        (new DirectoryTemplateSeeder())->run();
        $this->assertSame(4, DirectoryTemplate::count());
    }

    // =========================================================================
    // Le DÉFAUT : l'absence de règle vaut « cible désignée » (iso-34.3)
    // =========================================================================

    #[Test]
    public function the_four_seeded_recipes_stay_valid_and_designated_without_any_change(): void
    {
        (new DirectoryTemplateSeeder())->run();

        foreach (DirectoryTemplate::all() as $template) {
            $template->assertValidResolutionSpec();
            $template->assertValidTreeSpec();

            foreach ($template->roles() as $role) {
                $this->assertSame(
                    RoleResolutionStrategy::Designated,
                    $template->resolutionOf($role)['strategy'],
                    "La recette {$template->key} doit rester en cible désignée.",
                );
            }

            // Aucune n'est auto-résolvable, donc aucune n'est accrochable — ce qui
            // est exactement l'état voulu : elles se matérialisent à la main.
            $this->assertFalse($template->isAutoResolvable());
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

    #[Test]
    public function a_treeless_recipe_cannot_attach(): void
    {
        $template = $this->templateWithResolution(['strategy' => 'self']);
        $template->attached_group_type = 'classe';

        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/aucun arbre/u');

        $template->assertAttachable();
    }

    #[Test]
    public function an_unattachable_recipe_cannot_even_be_persisted_with_an_attachment(): void
    {
        // La donnée d'accrochage n'a pas d'écran : la garde est à l'écriture, au
        // seul endroit par lequel toute écriture passe.
        $template = $this->templateWithResolution(['strategy' => 'self']);
        $template->attached_group_type = 'classe';

        $this->expectException(InvalidTreeSpecException::class);

        $template->save();
    }

    #[Test]
    public function two_recipes_cannot_be_attached_to_the_same_type(): void
    {
        $this->autoResolvableClassTreeTemplate()->save();

        $second = $this->autoResolvableClassTreeTemplate();
        $second->key = 'classe_share_auto_bis';

        $this->expectException(QueryException::class);

        $second->save();
    }

    #[Test]
    public function several_recipes_may_stay_unattached(): void
    {
        // La contrainte d'unicité ne contraint que les valeurs présentes : c'est
        // ce qui permet aux 4 recettes de 34.3 de cohabiter, toutes non accrochées.
        (new DirectoryTemplateSeeder())->run();

        $this->assertSame(4, DirectoryTemplate::whereNull('attached_group_type')->count());
    }
}
