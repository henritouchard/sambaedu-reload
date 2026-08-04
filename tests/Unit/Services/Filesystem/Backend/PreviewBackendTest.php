<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Enums\FileBackendObservation;
use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Services\Filesystem\Backend\NodeObservation;
use App\Services\Filesystem\Backend\NodeReconciliation;
use App\Services\Filesystem\Backend\PreviewBackend;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Backend\Support\RootedClassPlan;

/**
 * Story 60.3 — le backend qui n'exécute rien, et qui ne ment sur rien.
 *
 * Le plan éprouvé est le PLAN CLASSE RÉEL (recette des stories 60.1/60.2, résolue
 * par le résolveur réel), augmenté de son nœud racine : quatre natures, un nœud
 * activable suspendu, des nœuds par membre, un plafond, des clôtures.
 */
class PreviewBackendTest extends TestCase
{
    use RootedClassPlan;

    private function backend(): PreviewBackend
    {
        return new PreviewBackend();
    }

    #[Test]
    public function it_answers_to_its_own_name(): void
    {
        $this->assertSame(FileBackendName::Preview, $this->backend()->name());
    }

    #[Test]
    public function provision_covers_every_node_of_the_class_plan_and_executes_nothing(): void
    {
        $plan = $this->rootedClassPlan(['_echange' => false]);
        $report = $this->backend()->provision($plan);

        $this->assertSame($plan->nodePaths(), array_map(
            static fn (NodeReconciliation $e): string => $e->path,
            $report->entries,
        ));
        $this->assertGreaterThanOrEqual(6, $report->count(), 'le décor doit contenir racine, nœuds fixes et nœuds par membre');

        foreach ($report->entries as $entry) {
            $this->assertSame(FileBackendOutcome::NonExecute, $entry->outcome, $entry->path);
        }

        // Ni « conforme » (il n'a rien vérifié), ni « appliqué » (il n'a rien écrit).
        $this->assertCount(0, $report->converged());
        $this->assertCount(0, $report->failures());
    }

    #[Test]
    public function the_root_node_is_part_of_every_report(): void
    {
        $plan = $this->rootedClassPlan();
        $backend = $this->backend();

        $this->assertNotNull($backend->provision($plan)->for(PlanNode::ROOT_PATH));
        $this->assertNotNull($backend->deprovision($plan)->for(PlanNode::ROOT_PATH));
        $this->assertNotNull($backend->inspect($plan)->for(PlanNode::ROOT_PATH));
    }

    /**
     * AC7 — la clôture TRAVERSE la ligne de contrat, et l'aperçu la rend visible.
     * Si elle était filtrée ou résumée au passage, ce texte serait impossible à
     * produire.
     */
    #[Test]
    public function the_preview_renders_the_closure_it_received(): void
    {
        $plan = $this->rootedClassPlan();
        $report = $this->backend()->provision($plan);

        $profs = $report->for('_profs');
        $this->assertNotNull($profs);
        $this->assertSame(['classe'], $plan->node('_profs')->closure);
        $this->assertStringContainsString('Rôles sans octroi ici', (string) $profs->detail);
        $this->assertStringContainsString('classe', (string) $profs->detail);
    }

    #[Test]
    public function a_node_without_closure_gets_no_closure_sentence(): void
    {
        $plan = new FilePlan('t', 'Racine', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre),
        ]);

        $entry = $this->backend()->provision($plan)->for(PlanNode::ROOT_PATH);

        $this->assertStringNotContainsString('Rôles sans octroi', (string) $entry->detail);
    }

    #[Test]
    public function inspect_says_non_observable_everywhere_rather_than_lying_conforme(): void
    {
        $plan = $this->rootedClassPlan();
        $report = $this->backend()->inspect($plan);

        $this->assertSame($plan->nodePaths(), array_map(
            static fn (NodeObservation $o): string => $o->path,
            $report->observations,
        ));

        foreach ($report->observations as $observation) {
            $this->assertSame(FileBackendObservation::NonObservable, $observation->status, $observation->path);
            $this->assertSame([], $observation->grants);
            $this->assertFalse($observation->plafondObserve);
            $this->assertNull($observation->plafond);
        }
    }

    /**
     * AC5 — le backend d'aperçu décline SANS que ce soit ni une limite de modèle
     * (`non_exprimable`) ni une dette de code (`non_implemente`).
     */
    #[Test]
    public function quota_declines_by_design_never_as_a_model_limit_nor_as_a_debt(): void
    {
        $plan = $this->rootedClassPlan();
        $report = $this->backend()->quota($plan);

        $this->assertSame($plan->cappedNodePaths(), array_map(
            static fn (NodeReconciliation $e): string => $e->path,
            $report->entries,
        ));
        $this->assertGreaterThan(0, $report->count(), 'le décor doit porter au moins un plafond');

        foreach ($report->entries as $entry) {
            $this->assertSame(FileBackendOutcome::NonExecute, $entry->outcome);
            $this->assertTrue($entry->outcome->isByDesign());
            $this->assertFalse($entry->outcome->isModelLimit());
            $this->assertFalse($entry->outcome->isImplementationDebt());
        }
    }

    #[Test]
    public function a_plan_without_any_ceiling_gives_a_valid_empty_quota_report(): void
    {
        $plan = new FilePlan('t', 'Racine', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre),
            new PlanNode('a', 'A', PlanNodeNature::Partagee),
        ]);

        $this->assertSame(0, $this->backend()->quota($plan)->count());
    }

    #[Test]
    public function a_suspended_grant_is_covered_like_any_other_node(): void
    {
        $plan = $this->rootedClassPlan(['_echange' => false]);
        $echange = $plan->node('_echange');

        $this->assertNotNull($echange);
        $this->assertFalse($echange->active);
        $this->assertNotEmpty($echange->suspendedGrants());
        $this->assertNotNull($this->backend()->provision($plan)->for('_echange'));
    }
}
