<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Exceptions\Filesystem\InvalidBackendReportException;
use App\Services\Filesystem\Backend\NodeReconciliation;
use App\Services\Filesystem\Backend\ReconciliationReport;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.3 — LES INVARIANTS DE CONSTRUCTION D'UN RAPPORT, éprouvés par l'échec.
 *
 * La fuite mesurée en ouverture d'epic est un SILENCE : une instruction acceptée,
 * sans effet, sur un nœud dont le rapport ne parlait pas. Une convention « pense à
 * couvrir tous les nœuds » se viole sans bruit ; une fabrique qui refuse de
 * construire, non. Ce fichier vérifie que le refus a bien lieu.
 */
class ReconciliationReportTest extends TestCase
{
    private function plan(): FilePlan
    {
        return new FilePlan('t', 'Racine', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre),
            new PlanNode('_profs', 'Espace des enseignants', PlanNodeNature::Partagee),
            new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee, [], true, 4096),
        ]);
    }

    // =========================================================================
    // Complétude
    // =========================================================================

    #[Test]
    public function a_report_covering_exactly_the_plan_nodes_is_valid(): void
    {
        $plan = $this->plan();

        $report = ReconciliationReport::covering(FileBackendName::Preview, $plan, [
            NodeReconciliation::applique('_travail'),
            NodeReconciliation::conforme(PlanNode::ROOT_PATH),
            NodeReconciliation::applique('_profs'),
        ]);

        $this->assertSame(3, $report->count());
        // Réordonné sur l'ordre canonique du plan : la racine d'abord.
        $this->assertSame(
            ['.', '_profs', '_travail'],
            array_map(static fn (NodeReconciliation $e): string => $e->path, $report->entries),
        );
    }

    /**
     * LE test de la fuite : un rapport « tout vert » dont le dossier privé des
     * enseignants serait absent est INCONSTRUCTIBLE. C'est exactement la forme du
     * silence mesuré.
     */
    #[Test]
    public function an_all_green_report_that_forgets_a_node_cannot_be_built(): void
    {
        $plan = $this->plan();

        $this->expectException(InvalidBackendReportException::class);
        $this->expectExceptionMessage('nœuds non couverts [_profs]');

        ReconciliationReport::covering(FileBackendName::Preview, $plan, [
            NodeReconciliation::conforme(PlanNode::ROOT_PATH),
            NodeReconciliation::conforme('_travail'),
        ]);
    }

    #[Test]
    public function a_report_that_speaks_of_a_node_outside_the_plan_cannot_be_built(): void
    {
        $plan = $this->plan();

        $this->expectException(InvalidBackendReportException::class);
        $this->expectExceptionMessage('nœuds hors plan [_secret]');

        ReconciliationReport::covering(FileBackendName::Preview, $plan, [
            NodeReconciliation::conforme(PlanNode::ROOT_PATH),
            NodeReconciliation::conforme('_profs'),
            NodeReconciliation::conforme('_travail'),
            NodeReconciliation::conforme('_secret'),
        ]);
    }

    #[Test]
    public function a_node_reported_twice_cannot_be_built(): void
    {
        $plan = $this->plan();

        $this->expectException(InvalidBackendReportException::class);
        $this->expectExceptionMessage('DEUX FOIS le nœud « _profs »');

        ReconciliationReport::covering(FileBackendName::Preview, $plan, [
            NodeReconciliation::conforme(PlanNode::ROOT_PATH),
            NodeReconciliation::conforme('_profs'),
            NodeReconciliation::applique('_profs'),
            NodeReconciliation::conforme('_travail'),
        ]);
    }

    // =========================================================================
    // Périmètre du plafond
    // =========================================================================

    #[Test]
    public function the_capped_scope_covers_only_the_nodes_that_carry_a_ceiling(): void
    {
        $plan = $this->plan();

        $report = ReconciliationReport::coveringCapped(FileBackendName::Preview, $plan, [
            NodeReconciliation::nonImplemente('_travail', 'le mécanisme existe, SE5 ne le pilote pas.'),
        ]);

        $this->assertSame(1, $report->count());
        $this->assertSame(ReconciliationReport::SCOPE_CAPPED, $report->scope);
    }

    #[Test]
    public function a_plan_without_any_ceiling_gives_a_valid_empty_capped_report(): void
    {
        $plan = new FilePlan('t', 'Racine', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre),
        ]);

        $report = ReconciliationReport::coveringCapped(FileBackendName::Preview, $plan, []);

        $this->assertSame(0, $report->count());
        $this->assertSame([], $report->toArray()['nodes']);
    }

    #[Test]
    public function the_capped_scope_refuses_a_node_without_a_ceiling(): void
    {
        $plan = $this->plan();

        $this->expectException(InvalidBackendReportException::class);
        $this->expectExceptionMessage('nœuds hors plan [_profs]');

        ReconciliationReport::coveringCapped(FileBackendName::Preview, $plan, [
            NodeReconciliation::nonImplemente('_travail', 'x'),
            NodeReconciliation::nonImplemente('_profs', 'x'),
        ]);
    }

    // =========================================================================
    // `detail` obligatoire — AU CONSTRUCTEUR
    // =========================================================================

    /**
     * @return list<array{0:FileBackendOutcome}>
     */
    public static function outcomesThatRequireDetail(): array
    {
        return [
            [FileBackendOutcome::Echec],
            [FileBackendOutcome::NonExprimable],
            [FileBackendOutcome::NonImplemente],
        ];
    }

    #[Test]
    public function the_three_outcomes_that_require_a_detail_refuse_to_be_built_without_one(): void
    {
        foreach (self::outcomesThatRequireDetail() as [$outcome]) {
            $this->assertTrue($outcome->requiresDetail(), $outcome->value);

            foreach ([null, '', '   '] as $empty) {
                try {
                    new NodeReconciliation('_profs', $outcome, $empty);
                    $this->fail(sprintf('« %s » construit sans detail', $outcome->value));
                } catch (InvalidBackendReportException $e) {
                    $this->assertStringContainsString('exige un detail non vide', $e->getMessage());
                }
            }
        }
    }

    #[Test]
    public function the_other_four_outcomes_do_not_require_a_detail(): void
    {
        foreach ([
            FileBackendOutcome::Conforme,
            FileBackendOutcome::Applique,
            FileBackendOutcome::EnAttente,
            FileBackendOutcome::NonExecute,
        ] as $outcome) {
            $entry = new NodeReconciliation('_profs', $outcome);
            $this->assertNull($entry->detail);
        }
    }

    #[Test]
    public function a_reconciliation_refuses_an_unsafe_node_path(): void
    {
        $this->expectException(InvalidBackendReportException::class);
        $this->expectExceptionMessage('chemin de nœud non sûr');

        NodeReconciliation::conforme('/var/quelque-chose');
    }

    // =========================================================================
    // Agrégats DÉRIVÉS — et aucun booléen global
    // =========================================================================

    #[Test]
    public function the_aggregates_are_views_derived_from_the_entries(): void
    {
        $plan = new FilePlan('t', 'Racine', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre),
            new PlanNode('a', 'A', PlanNodeNature::Partagee),
            new PlanNode('b', 'B', PlanNodeNature::Partagee),
            new PlanNode('c', 'C', PlanNodeNature::Partagee),
            new PlanNode('d', 'D', PlanNodeNature::Partagee),
        ]);

        $report = ReconciliationReport::covering(FileBackendName::Preview, $plan, [
            NodeReconciliation::conforme(PlanNode::ROOT_PATH),
            NodeReconciliation::applique('a'),
            new NodeReconciliation('b', FileBackendOutcome::EnAttente),
            NodeReconciliation::echec('c', 'chemin inexistant côté backend'),
            NodeReconciliation::nonExprimable('d', 'octroi hérité non refermable'),
        ]);

        $this->assertCount(2, $report->converged());
        $this->assertCount(1, $report->pending());
        $this->assertCount(1, $report->failures());
        $this->assertCount(1, $report->inexpressible());
        $this->assertCount(0, $report->unimplemented());
        $this->assertCount(1, $report->declines());
        $this->assertSame(FileBackendOutcome::Echec, $report->for('c')->outcome);
        $this->assertNull($report->for('inexistant'));
    }

    /**
     * MÉTA-TEST de la doctrine : le rapport n'expose AUCUNE méthode publique
     * rendant un booléen. Un `isSuccessful()` réintroduirait, à lui seul, la
     * lecture globale que le sondage a mesurée comme mensongère.
     */
    /**
     * La complétude est un invariant de CONSTRUCTION — et `unserialize()` ne passe
     * par aucun constructeur : il restaure les propriétés directement, `readonly`
     * comprises. Sans cette garde, un rapport « tout vert » omettant le dossier
     * privé des enseignants redevenait fabricable par ce chemin, et l'affirmation
     * la plus forte de cette story était fausse hors du chemin heureux.
     *
     * La réconciliation asynchrone annoncée pour la suite est exactement le cas qui
     * l'aurait emprunté sans le dire : une file de traitement sérialise sa charge.
     */
    #[Test]
    public function a_report_refuses_native_serialization(): void
    {
        $report = ReconciliationReport::covering(FileBackendName::Preview, $this->plan(), [
            NodeReconciliation::conforme(PlanNode::ROOT_PATH),
            NodeReconciliation::conforme('_profs'),
            NodeReconciliation::conforme('_travail'),
        ]);

        $this->expectException(InvalidBackendReportException::class);
        serialize($report);
    }

    #[Test]
    public function the_report_exposes_no_global_boolean(): void
    {
        $reflection = new \ReflectionClass(ReconciliationReport::class);

        $booleans = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'bool') {
                $booleans[] = $method->getName();
            }
        }

        $this->assertSame(
            [],
            $booleans,
            'un rapport ne se résume pas en un booléen : c\'est exactement la lecture qui a laissé fuiter '
            . 'le dossier privé des enseignants au sondage.',
        );
    }

    #[Test]
    public function the_serialisation_is_stable_and_carries_no_transport_code(): void
    {
        $plan = $this->plan();

        $report = ReconciliationReport::covering(FileBackendName::Preview, $plan, [
            NodeReconciliation::conforme('_travail'),
            NodeReconciliation::conforme('_profs'),
            NodeReconciliation::conforme(PlanNode::ROOT_PATH),
        ]);

        $this->assertSame([
            'backend' => 'preview',
            'scope' => 'plan',
            'nodes' => [
                ['path' => '.', 'outcome' => 'conforme', 'detail' => null],
                ['path' => '_profs', 'outcome' => 'conforme', 'detail' => null],
                ['path' => '_travail', 'outcome' => 'conforme', 'detail' => null],
            ],
        ], $report->toArray());

        foreach (['http', 'status', 'code', '405', '102', '200'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                json_encode($report->toArray(), JSON_UNESCAPED_UNICODE),
                'un code de transport a fui au-dessus de la ligne de contrat : ' . $forbidden,
            );
        }
    }
}
