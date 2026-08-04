<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Exceptions\Filesystem\InvalidTreeSpecException;
use App\Models\DirectoryTemplate;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Plan\ClassTreeRecipe;

/**
 * Story 60.1 — la recette STOCKÉE est bien formée, ou elle est refusée.
 *
 * Deux validations distinctes vivent dans cette story, et elles ne se mélangent
 * pas : celle-ci porte sur la RECETTE (vocabulaire, cohérence interne), celle du
 * résolveur porte sur les DONNÉES DE RÉSOLUTION. Une recette impeccable peut
 * échouer à se résoudre sur un groupe au nom impossible ; l'inverse n'aurait
 * aucun sens.
 */
class DirectoryTemplateTreeSpecTest extends TestCase
{
    use ClassTreeRecipe;

    /**
     * Une recette d'arbre minimale, altérable nœud par nœud.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function template(array $nodes, ?string $pattern = 'Classes/Classe_{group.bare_name}'): DirectoryTemplate
    {
        return new DirectoryTemplate([
            'key' => 'probe',
            'label' => 'Sonde',
            'roles_spec' => [
                ['key' => 'equipe', 'label' => 'Équipe', 'maille' => UserGroup::class, 'access' => 'rw', 'cardinality' => 'one'],
                ['key' => 'classe', 'label' => 'Classe', 'maille' => UserGroup::class, 'access' => 'ro', 'cardinality' => 'one'],
            ],
            'path_pattern' => $pattern,
            'nodes_spec' => $nodes,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function node(array $overrides = []): array
    {
        return array_merge([
            'path' => '_travail',
            'label' => 'Travail',
            'nature' => 'partagee',
            'grants' => [['role' => 'equipe', 'access' => 'rw']],
        ], $overrides);
    }

    private function assertRejected(DirectoryTemplate $template, string $expectedFragment): void
    {
        $this->expectException(InvalidTreeSpecException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedFragment, '/') . '/u');

        $template->assertValidTreeSpec();
    }

    // =========================================================================
    // Le vocabulaire d'arbre est accepté
    // =========================================================================

    #[Test]
    public function the_class_share_recipe_is_a_valid_tree(): void
    {
        $template = $this->classTreeTemplate();

        $template->assertValidTreeSpec();

        $this->assertTrue($template->hasTreeSpec());
        $this->assertSame('Classes/Classe_{group.bare_name}', $template->pathPattern());
        $this->assertCount(5, $template->nodes());
    }

    #[Test]
    public function a_recipe_with_nodes_but_no_pattern_is_rejected(): void
    {
        $this->assertRejected(
            $this->template([$this->node()], pattern: null),
            'des nœuds sont déclarés sans motif de chemin',
        );
    }

    // =========================================================================
    // Enum fermée, drapeaux cohérents
    // =========================================================================

    #[Test]
    public function an_unknown_nature_is_rejected(): void
    {
        $this->assertRejected(
            $this->template([$this->node(['nature' => 'optionnelle'])]),
            'nature inconnue',
        );
    }

    #[Test]
    public function an_activable_flag_that_contradicts_the_nature_is_rejected(): void
    {
        $this->assertRejected(
            $this->template([$this->node(['activable' => true])]),
            'contredit sa nature',
        );
    }

    #[Test]
    public function a_node_without_label_is_rejected(): void
    {
        $this->assertRejected(
            $this->template([$this->node(['label' => '  '])]),
            'n\'a pas de libellé',
        );
    }

    #[Test]
    public function a_node_field_outside_the_closed_vocabulary_is_rejected(): void
    {
        // C'est ce qui rend la clôture INAUTHORABLE : elle n'est pas un champ, et
        // aucun champ ne peut prétendre la saisir.
        foreach (['closure', 'excluded_roles', 'deny', 'optionnel'] as $field) {
            $rejected = false;
            try {
                $this->template([$this->node([$field => ['equipe']])])->assertValidTreeSpec();
            } catch (InvalidTreeSpecException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'champ de nœud accepté à tort : ' . $field);
        }
    }

    #[Test]
    public function a_grant_field_outside_the_closed_vocabulary_is_rejected(): void
    {
        // Un octroi est POSITIF : aucun champ d'interdiction, aucune priorité.
        foreach (['deny', 'except', 'priority'] as $field) {
            $rejected = false;
            try {
                $this->template([$this->node(['grants' => [
                    ['role' => 'equipe', 'access' => 'rw', $field => true],
                ]])])->assertValidTreeSpec();
            } catch (InvalidTreeSpecException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'champ d\'octroi accepté à tort : ' . $field);
        }
    }

    // =========================================================================
    // Placeholders : vocabulaire FERMÉ
    // =========================================================================

    #[Test]
    public function an_unknown_placeholder_is_rejected_in_the_pattern(): void
    {
        $this->assertRejected(
            $this->template([$this->node()], pattern: 'Classes/{group.uid}'),
            'placeholder inconnu',
        );
    }

    #[Test]
    public function the_member_placeholder_is_forbidden_outside_a_per_member_node(): void
    {
        $this->assertRejected(
            $this->template([$this->node(['path' => 'depots/{member.login}'])]),
            'placeholder inconnu',
        );

        $this->assertRejected(
            $this->template([$this->node()], pattern: 'Classes/{member.login}'),
            'placeholder inconnu',
        );
    }

    #[Test]
    public function a_per_member_node_without_the_member_placeholder_is_rejected(): void
    {
        $this->assertRejected(
            $this->template([$this->node([
                'path' => 'eleves',
                'nature' => 'par_membre',
                'edge_role' => 'member',
            ])]),
            'doit porter',
        );
    }

    #[Test]
    public function an_absolute_or_traversing_path_is_rejected(): void
    {
        foreach (['/_travail', '../_travail', '_travail/../_profs', '.cache'] as $path) {
            $rejected = false;
            try {
                $this->template([$this->node(['path' => $path])])->assertValidTreeSpec();
            } catch (InvalidTreeSpecException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'chemin accepté à tort : ' . $path);
        }
    }

    #[Test]
    public function a_pattern_with_an_orphan_brace_is_rejected(): void
    {
        $this->assertRejected(
            $this->template([$this->node()], pattern: 'Classes/Classe_{group.bare_name'),
            'chemin relatif sûr',
        );
    }

    #[Test]
    public function two_nodes_at_the_same_path_are_rejected(): void
    {
        $this->assertRejected(
            $this->template([$this->node(), $this->node(['label' => 'Doublon'])]),
            'déclaré deux fois',
        );
    }

    // =========================================================================
    // Rôle d'arête
    // =========================================================================

    #[Test]
    public function a_per_member_node_needs_a_known_edge_role(): void
    {
        $this->assertRejected(
            $this->template([$this->node([
                'path' => '{member.login}',
                'nature' => 'par_membre',
            ])]),
            'rôle d\'arête connu',
        );

        $this->assertRejected(
            $this->template([$this->node([
                'path' => '{member.login}',
                'nature' => 'par_membre',
                'edge_role' => 'prof_principal',
            ])]),
            'rôle d\'arête connu',
        );
    }

    #[Test]
    public function an_edge_role_on_a_node_that_enumerates_nobody_is_rejected(): void
    {
        $this->assertRejected(
            $this->template([$this->node(['edge_role' => 'member'])]),
            'n\'énumère aucun membre',
        );
    }

    // =========================================================================
    // Octrois
    // =========================================================================

    #[Test]
    public function an_unknown_access_is_rejected(): void
    {
        $this->assertRejected(
            $this->template([$this->node(['grants' => [['role' => 'equipe', 'access' => 'rwx']]])]),
            'inconnu sur le nœud',
        );
    }

    #[Test]
    public function a_grant_to_a_role_absent_from_the_recipe_is_rejected(): void
    {
        $this->assertRejected(
            $this->template([$this->node(['grants' => [['role' => 'direction', 'access' => 'rw']]])]),
            'absent de la recette',
        );
    }

    #[Test]
    public function the_enumerated_member_token_is_rejected_outside_a_per_member_node(): void
    {
        $this->assertRejected(
            $this->template([$this->node(['grants' => [
                ['role' => DirectoryTemplate::TREE_ROLE_MEMBER, 'access' => 'rw'],
            ]])]),
            'n\'a de sens que sur un nœud par membre',
        );
    }

    #[Test]
    public function a_suspendable_grant_outside_an_activable_node_is_rejected(): void
    {
        $this->assertRejected(
            $this->template([$this->node(['grants' => [
                ['role' => 'equipe', 'access' => 'rw', 'suspendable' => true],
            ]])]),
            'rien ne pourrait jamais le suspendre',
        );
    }

    #[Test]
    public function two_grants_for_the_same_role_on_a_node_are_rejected(): void
    {
        $this->assertRejected(
            $this->template([$this->node(['grants' => [
                ['role' => 'equipe', 'access' => 'rw'],
                ['role' => 'equipe', 'access' => 'ro'],
            ]])]),
            'reçoit deux octrois',
        );
    }

    // =========================================================================
    // Plafond (posé maintenant, exécuté plus tard)
    // =========================================================================

    #[Test]
    public function a_non_positive_quota_is_rejected(): void
    {
        foreach ([0, -1, '2Go', 1.5] as $plafond) {
            $rejected = false;
            try {
                $this->template([$this->node(['plafond' => $plafond])])->assertValidTreeSpec();
            } catch (InvalidTreeSpecException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'plafond accepté à tort : ' . var_export($plafond, true));
        }
    }

    #[Test]
    public function a_positive_quota_is_accepted_and_nothing_executes_it(): void
    {
        $this->template([$this->node(['plafond' => 1024])])->assertValidTreeSpec();
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Invariant « aucun octroi d'arbre ne vise un parc »
    // =========================================================================

    #[Test]
    public function a_recipe_whose_role_targets_a_workstation_group_cannot_carry_a_tree(): void
    {
        $template = new DirectoryTemplate([
            'key' => 'probe',
            'label' => 'Sonde',
            'roles_spec' => [
                ['key' => 'parc', 'label' => 'Parc', 'maille' => WorkstationGroup::class, 'access' => 'rw', 'cardinality' => 'one'],
            ],
            'path_pattern' => 'Partages/{group.name}',
            'nodes_spec' => [$this->node(['grants' => [['role' => 'parc', 'access' => 'rw']]])],
        ]);

        $this->assertFalse($template->respectsMountOnlyInvariant());
        $this->assertRejected($template, 'maille non autorisée');
    }
}
