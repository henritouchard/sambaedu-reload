<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Enums\FileBackendName;
use App\Enums\PlanNodeNature;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeObservation;
use App\Services\Filesystem\Backend\ObservedGrant;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\Filesystem\PlanStateComparator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.4 — LA TABLE DE COMPARAISON, ligne à ligne.
 *
 * Aucune base, aucun processus : le comparateur est PUR. Si un jour ce fichier
 * réclame une simulation d'exécution, c'est que la comparaison est redescendue
 * sous la ligne.
 */
class PlanStateComparatorTest extends TestCase
{
    private const SUBJECT_ID = 42;

    private function comparator(): PlanStateComparator
    {
        return new PlanStateComparator();
    }

    private function subject(): PlanSubject
    {
        return PlanSubject::user(self::SUBJECT_ID);
    }

    /** @param list<PlanGrant> $grants */
    private function plan(array $grants, array $closure = []): FilePlan
    {
        return new FilePlan('@partage', 'proj', ['prof' => [$this->subject()]], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::Activable, $grants, true, null, $closure),
        ]);
    }

    /** @param list<ObservedGrant> $grants */
    private function inspection(FilePlan $plan, array $grants): InspectionReport
    {
        return InspectionReport::covering(FileBackendName::Posix, $plan, [
            NodeObservation::observed(PlanNode::ROOT_PATH, $grants),
        ]);
    }

    private function active(array $verbs = PlanGrant::VERBS): PlanGrant
    {
        return new PlanGrant('prof', $this->subject(), $verbs, true, false);
    }

    private function suspended(array $verbs = PlanGrant::VERBS): PlanGrant
    {
        return new PlanGrant('prof', $this->subject(), $verbs, true, true);
    }

    /** @param list<ObservedGrant> $observed */
    private function differencesFor(FilePlan $plan, array $observed): array
    {
        return $this->comparator()->compare($plan, $this->inspection($plan, $observed))['nodes'][0]['differences'];
    }

    // =========================================================================
    // Octroi ACTIF
    // =========================================================================

    #[Test]
    public function an_active_grant_observed_at_the_same_access_is_conforme(): void
    {
        $plan = $this->plan([$this->active()]);

        self::assertSame([], $this->differencesFor($plan, [new ObservedGrant($this->subject(), PlanGrant::VERBS)]));
    }

    #[Test]
    public function an_active_grant_observed_lower_is_a_difference(): void
    {
        $plan = $this->plan([$this->active()]);

        self::assertSame(
            [['subject' => $this->subject()->toArray(), 'expected' => PlanGrant::VERBS, 'observed' => [PlanGrant::VERB_LIRE]]],
            $this->differencesFor($plan, [new ObservedGrant($this->subject(), [PlanGrant::VERB_LIRE])]),
        );
    }

    #[Test]
    public function an_active_grant_observed_at_none_is_a_difference(): void
    {
        $plan = $this->plan([$this->active()]);

        self::assertSame(
            [['subject' => $this->subject()->toArray(), 'expected' => PlanGrant::VERBS, 'observed' => []]],
            $this->differencesFor($plan, [new ObservedGrant($this->subject(), [])]),
        );
    }

    #[Test]
    public function an_active_grant_observed_nowhere_is_a_difference(): void
    {
        $plan = $this->plan([$this->active()]);

        self::assertSame(
            [['subject' => $this->subject()->toArray(), 'expected' => PlanGrant::VERBS, 'observed' => null]],
            $this->differencesFor($plan, []),
        );
    }

    // =========================================================================
    // Octroi SUSPENDU — les trois lignes qui comptent
    // =========================================================================

    /**
     * LA FORME MATÉRIALISÉE DE LA SUSPENSION : entrée présente, accès nul. C'est
     * CONFORME — sans cette ligne, une désactivation se relirait comme une
     * matérialisation manquante et l'administrateur « réparerait » un état correct.
     */
    #[Test]
    public function a_suspended_grant_observed_at_none_is_conforme(): void
    {
        $plan = $this->plan([$this->suspended()]);

        self::assertSame([], $this->differencesFor($plan, [
            new ObservedGrant($this->subject(), []),
        ]));
    }

    /**
     * LA FUITE QU'IL FAUT VOIR : la suspension n'a pas pris, l'accès est toujours
     * là. Un vocabulaire d'observation à deux valeurs l'aurait rendue invisible.
     */
    #[Test]
    public function a_suspended_grant_still_observed_with_an_access_is_a_difference(): void
    {
        $plan = $this->plan([$this->suspended()]);

        self::assertSame(
            [['subject' => $this->subject()->toArray(), 'expected' => [], 'observed' => PlanGrant::VERBS]],
            $this->differencesFor($plan, [new ObservedGrant($this->subject(), PlanGrant::VERBS)]),
        );
    }

    #[Test]
    public function a_suspended_grant_observed_nowhere_is_a_difference_that_can_reconverge(): void
    {
        $plan = $this->plan([$this->suspended()]);

        self::assertSame(
            [['subject' => $this->subject()->toArray(), 'expected' => [], 'observed' => null]],
            $this->differencesFor($plan, []),
        );
    }

    // =========================================================================
    // En trop, et clôture
    // =========================================================================

    #[Test]
    public function an_observed_entry_without_a_grant_in_the_plan_is_a_difference(): void
    {
        $plan = $this->plan([]);
        $intruder = PlanSubject::user(999);

        self::assertSame(
            [['subject' => $intruder->toArray(), 'expected' => null, 'observed' => PlanGrant::VERBS]],
            $this->differencesFor($plan, [new ObservedGrant($intruder, PlanGrant::VERBS)]),
        );
    }

    /**
     * LA CLÔTURE NE PRODUIT RIEN. Il n'y a pas de refus en POSIX : l'absence
     * d'octroi EST la fermeture. Le backend n'écrit rien pour elle, la comparaison
     * ne lui réclame rien. C'est un backend à propagation qui devra la
     * matérialiser.
     */
    #[Test]
    public function a_closed_role_is_neither_expected_nor_reported_as_a_difference(): void
    {
        $plan = $this->plan([], ['prof', 'eleve']);

        $result = $this->comparator()->compare($plan, $this->inspection($plan, []));

        self::assertSame([], $result['nodes'][0]['differences']);
        self::assertSame(PlanStateComparator::STATUS_CONFORME, $result['status']);
    }

    /**
     * LES TROIS ÉTATS CÔTE À CÔTE sur un même plan : actif conforme, suspendu
     * appliqué, rôle clos silencieux. Aucun des trois ne se confond avec un autre.
     */
    #[Test]
    public function the_three_states_traverse_the_comparison_without_ever_merging(): void
    {
        $actif = PlanSubject::user(1);
        $suspendu = PlanSubject::user(2);

        $plan = new FilePlan('@partage', 'proj', ['prof' => [$actif], 'eleve' => [$suspendu]], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::Activable, [
                new PlanGrant('prof', $actif, PlanGrant::VERBS, true, false),
                new PlanGrant('eleve', $suspendu, [PlanGrant::VERB_LIRE], true, true),
            ], true, null, ['invite']),
        ]);

        $result = $this->comparator()->compare($plan, $this->inspection($plan, [
            new ObservedGrant($actif, PlanGrant::VERBS),
            new ObservedGrant($suspendu, []),
        ]));

        self::assertSame([], $result['nodes'][0]['differences']);
        self::assertSame(PlanStateComparator::STATUS_CONFORME, $result['status']);
    }

    // =========================================================================
    // Agrégats pour le contrôleur d'environnement
    // =========================================================================

    #[Test]
    public function the_aggregate_gives_precedence_to_failure_then_absence_then_drift(): void
    {
        $plan = new FilePlan('@arbre', 'proj', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre),
            new PlanNode('a', 'A', PlanNodeNature::ContenuLibre),
            new PlanNode('b', 'B', PlanNodeNature::ContenuLibre, [new PlanGrant('r', $this->subject(), PlanGrant::VERBS)]),
        ]);

        $withFailure = InspectionReport::covering(FileBackendName::Posix, $plan, [
            NodeObservation::echec(PlanNode::ROOT_PATH, 'illisible'),
            NodeObservation::absent('a'),
            NodeObservation::observed('b'),
        ]);
        self::assertSame(PlanStateComparator::STATUS_ERROR, $this->comparator()->compare($plan, $withFailure)['status']);

        $withAbsence = InspectionReport::covering(FileBackendName::Posix, $plan, [
            NodeObservation::observed(PlanNode::ROOT_PATH),
            NodeObservation::absent('a'),
            NodeObservation::observed('b'),
        ]);
        self::assertSame(PlanStateComparator::STATUS_ABSENT, $this->comparator()->compare($plan, $withAbsence)['status']);

        $withDrift = InspectionReport::covering(FileBackendName::Posix, $plan, [
            NodeObservation::observed(PlanNode::ROOT_PATH),
            NodeObservation::observed('a'),
            NodeObservation::observed('b'),
        ]);
        self::assertSame(PlanStateComparator::STATUS_DRIFTED, $this->comparator()->compare($plan, $withDrift)['status']);
    }

    /**
     * Un nœud NON OBSERVÉ n'est jamais déclaré conforme : une ignorance n'est pas
     * une observation. C'est le backend d'aperçu qui répond ainsi partout.
     */
    #[Test]
    public function an_unread_node_is_never_declared_conforme(): void
    {
        $plan = $this->plan([]);

        $result = $this->comparator()->compare($plan, InspectionReport::covering(FileBackendName::Posix, $plan, [
            NodeObservation::nonObservable(PlanNode::ROOT_PATH, 'ce backend n\'observe rien'),
        ]));

        self::assertSame(PlanStateComparator::NODE_NON_OBSERVE, $result['nodes'][0]['status']);
        self::assertSame(PlanStateComparator::STATUS_ERROR, $result['status']);
    }

    /**
     * Une entrée relue sans identité connue empêche de déclarer le nœud conforme —
     * elle ne peut pas être NOMMÉE, mais elle est bien un écart.
     */
    #[Test]
    public function unnamed_observed_entries_prevent_a_conformity_verdict(): void
    {
        $plan = $this->plan([]);

        $result = $this->comparator()->compare($plan, InspectionReport::covering(FileBackendName::Posix, $plan, [
            NodeObservation::observed(PlanNode::ROOT_PATH, [], null, false, '2 entrée(s) sans correspondance.'),
        ]));

        self::assertSame(PlanStateComparator::NODE_ECART, $result['nodes'][0]['status']);
        self::assertSame(PlanStateComparator::STATUS_DRIFTED, $result['status']);
    }

    /** Les libellés d'affichage, y compris celui du vide et celui du rien. */
    #[Test]
    public function the_display_labels_distinguish_none_from_nothing(): void
    {
        // Story 62.4 — le libellé est celui d'une LISTE : un verbe, plusieurs verbes,
        // la liste vide (l'entrée présente qui ne donne rien) et l'absence pure.
        self::assertSame('Lire', PlanStateComparator::accessLabel([PlanGrant::VERB_LIRE]));
        self::assertSame('Lire + Éditer + Créer + Supprimer', PlanStateComparator::accessLabel(PlanGrant::VERBS));
        self::assertSame('Lire + Créer', PlanStateComparator::accessLabel([PlanGrant::VERB_LIRE, PlanGrant::VERB_CREER]));
        self::assertSame('Aucun', PlanStateComparator::accessLabel([]));
        self::assertSame('—', PlanStateComparator::accessLabel(null));
    }
}
