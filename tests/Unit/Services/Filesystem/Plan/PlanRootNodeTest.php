<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Plan;

use App\Enums\PlanNodeNature;
use App\Exceptions\Filesystem\PlanResolutionException;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\GroupNameNormalizer;
use App\Services\Filesystem\Plan\PlanNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.3 — LA RACINE EST UN NŒUD COMME LES AUTRES.
 *
 * Motif : le sondage d'ouverture d'epic a mesuré qu'une relecture d'état « avec
 * les sous-chemins » rend les enfants MAIS PAS la racine. Un backend qui traite la
 * racine à part finit par l'oublier — c'est un mode de rupture mesuré, pas une
 * hypothèse. La faire entrer dans le vocabulaire de nœud est la parade.
 *
 * Ce fichier vérifie les deux moitiés de la propriété : la racine est acceptée
 * COMME NŒUD, et elle reste refusée PARTOUT AILLEURS.
 */
class PlanRootNodeTest extends TestCase
{
    #[Test]
    public function the_root_constant_is_a_single_source_of_truth(): void
    {
        $this->assertSame(GroupNameNormalizer::ROOT_NODE_PATH, PlanNode::ROOT_PATH);
        $this->assertSame('.', PlanNode::ROOT_PATH);
    }

    #[Test]
    public function a_node_can_be_the_root(): void
    {
        $node = new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre);

        $this->assertSame('.', $node->path);
        $this->assertSame('.', $node->toArray()['path']);
    }

    #[Test]
    public function a_node_path_that_merely_contains_the_root_token_is_refused(): void
    {
        foreach (['./x', 'a/./b', 'a/.', '..', '.cache'] as $path) {
            try {
                new PlanNode($path, 'X', PlanNodeNature::Partagee);
                $this->fail('chemin de nœud accepté à tort : ' . $path);
            } catch (PlanResolutionException $e) {
                $this->assertStringContainsString('chemin de nœud non sûr', $e->getMessage());
            }
        }
    }

    #[Test]
    public function the_plan_root_path_still_refuses_the_root_token(): void
    {
        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessage('racine de plan non sûre');

        new FilePlan('t', PlanNode::ROOT_PATH);
    }

    /**
     * La racine trie EN TÊTE — et c'est le comparateur qui le garantit, pas
     * l'ordre des octets. Le nœud de contrôle commence par un tiret, premier
     * caractère de segment parfaitement légitime qui précède le point : sans
     * comparateur explicite, il passerait AVANT la racine.
     */
    #[Test]
    public function the_root_sorts_first_even_before_a_dash_prefixed_node(): void
    {
        $plan = new FilePlan('t', 'Racine', [], [
            new PlanNode('_travail', 'A', PlanNodeNature::Partagee),
            new PlanNode('-archives', 'B', PlanNodeNature::Partagee),
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre),
        ]);

        $this->assertSame(['.', '-archives', '_travail'], $plan->nodePaths());
    }

    #[Test]
    public function a_plan_with_a_root_node_survives_a_serialisation_round_trip(): void
    {
        $plan = new FilePlan('t', 'Racine', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre),
            new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee),
        ]);

        $relu = FilePlan::fromJson($plan->toJson());

        $this->assertSame($plan->toJson(), $relu->toJson());
        $this->assertNotNull($relu->node(PlanNode::ROOT_PATH));
        $this->assertSame(PlanNodeNature::ContenuLibre, $relu->node(PlanNode::ROOT_PATH)->nature);
    }

    #[Test]
    public function the_capped_perimeter_is_the_nodes_that_carry_a_ceiling(): void
    {
        $plan = new FilePlan('t', 'Racine', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre),
            new PlanNode('a', 'A', PlanNodeNature::Partagee, [], true, 1024),
            new PlanNode('b', 'B', PlanNodeNature::Partagee),
        ]);

        $this->assertSame(['a'], $plan->cappedNodePaths());
        $this->assertSame(['.', 'a', 'b'], $plan->nodePaths());
    }
}
