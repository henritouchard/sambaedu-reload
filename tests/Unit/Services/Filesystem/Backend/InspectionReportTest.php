<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Enums\FileBackendObservation;
use App\Enums\PlanNodeNature;
use App\Exceptions\Filesystem\InvalidBackendReportException;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeObservation;
use App\Services\Filesystem\Backend\ObservedGrant;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.3 — la RELECTURE balaie, et elle ne prétend jamais avoir vu ce qu'elle
 * n'a pas regardé.
 *
 * Le piège MESURÉ est ici : une lecture unique de sous-arbre rend les enfants mais
 * pas la racine. La complétude étant un invariant de construction, un backend qui
 * la sauterait ne pourrait pas rendre son rapport.
 */
class InspectionReportTest extends TestCase
{
    private function plan(): FilePlan
    {
        return new FilePlan('t', 'Racine', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre),
            new PlanNode('_profs', 'Espace des enseignants', PlanNodeNature::Partagee),
        ]);
    }

    #[Test]
    public function an_inspection_that_forgets_the_root_cannot_be_built(): void
    {
        $this->expectException(InvalidBackendReportException::class);
        $this->expectExceptionMessage('nœuds non observés [.]');

        InspectionReport::covering(FileBackendName::Preview, $this->plan(), [
            NodeObservation::absent('_profs'),
        ]);
    }

    #[Test]
    public function an_inspection_covering_every_node_is_valid_and_canonically_ordered(): void
    {
        $report = InspectionReport::covering(FileBackendName::Preview, $this->plan(), [
            NodeObservation::absent('_profs'),
            NodeObservation::nonObservable(PlanNode::ROOT_PATH),
        ]);

        $this->assertSame(
            ['.', '_profs'],
            array_map(static fn (NodeObservation $o): string => $o->path, $report->observations),
        );
        $this->assertCount(1, $report->absent());
        $this->assertCount(1, $report->unobservable());
        $this->assertCount(0, $report->observed());
        $this->assertCount(0, $report->failures());
    }

    // =========================================================================
    // Le plafond : deux champs, un invariant
    // =========================================================================

    #[Test]
    public function claiming_a_ceiling_without_having_looked_is_refused(): void
    {
        $this->expectException(InvalidBackendReportException::class);
        $this->expectExceptionMessage('sans déclarer l\'avoir regardé');

        NodeObservation::observed('_profs', [], 4096, false);
    }

    #[Test]
    public function a_looked_at_ceiling_may_legitimately_be_absent(): void
    {
        $observation = NodeObservation::observed('_profs', [], null, true);

        $this->assertNull($observation->plafond);
        $this->assertTrue($observation->plafondObserve);
    }

    /**
     * Les DEUX cas de la correction : un backend au plafond non implémenté et un
     * backend au plafond non supporté se disent PAREIL ici — `plafondObserve` à
     * faux. Ce qui les sépare se lit sur la réponse au plafond, pas sur
     * l'observation. Ce test épingle cette égalité pour qu'on ne cherche pas la
     * nuance au mauvais endroit.
     */
    #[Test]
    public function both_declining_backends_report_the_ceiling_the_same_way(): void
    {
        $nonImplemente = NodeObservation::observed('_profs', [], null, false);
        $nonExprimable = NodeObservation::observed('_profs', [], null, false);

        $this->assertSame($nonImplemente->toArray(), $nonExprimable->toArray());
        $this->assertFalse($nonImplemente->plafondObserve);
    }

    #[Test]
    public function a_zero_ceiling_is_refused_absence_is_said_with_null(): void
    {
        $this->expectException(InvalidBackendReportException::class);
        $this->expectExceptionMessage('plafond observé non positif');

        NodeObservation::observed('_profs', [], 0, true);
    }

    // =========================================================================
    // Vocabulaire de plan, et rien d'autre
    // =========================================================================

    #[Test]
    public function an_observed_grant_speaks_internal_identities_and_plan_access(): void
    {
        $grant = new ObservedGrant(PlanSubject::group(7), [PlanGrant::VERB_LIRE]);

        $this->assertSame(
            ['subject' => ['type' => 'user_group', 'id' => 7, 'edge_role' => null], 'verbs' => [PlanGrant::VERB_LIRE]],
            $grant->toArray(),
        );
    }

    #[Test]
    public function an_observed_grant_refuses_a_verb_outside_the_plan_vocabulary(): void
    {
        $this->expectException(InvalidBackendReportException::class);
        $this->expectExceptionMessage('verbe observé inconnu');

        // Un mode système relu tel quel : la relecture doit le REFUSER, pas le
        // laisser entrer dans le vocabulaire de plan.
        new ObservedGrant(PlanSubject::user(1), ['rwx']);
    }

    /**
     * Story 62.4 — la liste VIDE est licite pour une OBSERVATION, et elle seule :
     * c'est la forme matérialisée d'une suspension. Le plan, lui, la refuse.
     */
    #[Test]
    public function an_observed_grant_may_carry_no_verb_at_all(): void
    {
        $grant = new ObservedGrant(PlanSubject::user(1), []);

        self::assertTrue($grant->isEmpty());
        self::assertSame([], $grant->toArray()['verbs']);
    }

    #[Test]
    public function a_node_that_was_not_read_cannot_carry_grants(): void
    {
        $this->expectException(InvalidBackendReportException::class);
        $this->expectExceptionMessage('ne peut pas dire ce qu\'il contient');

        new NodeObservation(
            '_profs',
            FileBackendObservation::NonObservable,
            [new ObservedGrant(PlanSubject::group(7), [PlanGrant::VERB_LIRE])],
        );
    }

    #[Test]
    public function a_failed_observation_requires_its_cause(): void
    {
        $this->expectException(InvalidBackendReportException::class);
        $this->expectExceptionMessage('exige un detail non vide');

        new NodeObservation('_profs', FileBackendObservation::Echec);
    }

    #[Test]
    public function observed_grants_are_sorted_deterministically(): void
    {
        $a = NodeObservation::observed('_profs', [
            new ObservedGrant(PlanSubject::group(11), PlanGrant::VERBS),
            new ObservedGrant(PlanSubject::user(3), [PlanGrant::VERB_LIRE]),
        ]);
        $b = NodeObservation::observed('_profs', [
            new ObservedGrant(PlanSubject::user(3), [PlanGrant::VERB_LIRE]),
            new ObservedGrant(PlanSubject::group(11), PlanGrant::VERBS),
        ]);

        $this->assertSame($a->toArray(), $b->toArray());
    }

    /**
     * MÉTA-TEST, jumeau de celui du rapport de réconciliation. Il ne vivait que sur
     * une des deux classes de rapport — donc la garde ne couvrait que la moitié du
     * contrat, et un `isFullyObserved()` ajouté demain serait passé inaperçu. Une
     * relecture ne se résume pas plus qu'une réconciliation : c'est la lecture
     * globale qui a laissé fuiter le dossier privé des enseignants au sondage.
     */
    #[Test]
    public function the_inspection_report_exposes_no_global_boolean(): void
    {
        $reflection = new \ReflectionClass(InspectionReport::class);

        $booleans = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'bool') {
                $booleans[] = $method->getName();
            }
        }

        $this->assertSame([], $booleans, 'une relecture d\'état ne se résume pas en un booléen.');
    }

    /**
     * La complétude est un invariant de CONSTRUCTION — et `unserialize()` ne passe
     * par aucun constructeur. Sans cette garde, une relecture amputée d'un nœud
     * serait reconstructible et se comparerait « conforme » à un état incomplet.
     */
    #[Test]
    public function an_inspection_report_refuses_native_serialization(): void
    {
        $report = InspectionReport::covering(FileBackendName::Preview, $this->plan(), [
            NodeObservation::nonObservable(PlanNode::ROOT_PATH),
            NodeObservation::nonObservable('_profs'),
        ]);

        $this->expectException(InvalidBackendReportException::class);
        serialize($report);
    }
}
