<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend;

use App\Enums\FileBackendObservation;
use App\Enums\FileBackendOutcome;
use App\Services\Filesystem\Backend\NodeReconciliation;
use App\Services\Filesystem\Backend\ObservedGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Backend\Support\FakePropagatingBackend;
use Tests\Unit\Services\Filesystem\Backend\Support\RootedClassPlan;

/**
 * Story 60.3 — LES CINQ CONTRAINTES DU SONDAGE, une par une, contre un backend
 * qui se comporte comme le modèle MESURÉ.
 *
 * Le backend d'aperçu ne peut pas porter ces preuves : n'exécutant rien, il
 * satisfait aussi un contrat mauvais. Ce fichier est donc le vrai test du contrat.
 * Chaque cas cite la contrainte qu'il matérialise.
 */
class FakePropagatingBackendTest extends TestCase
{
    use RootedClassPlan;

    // =========================================================================
    // Contrainte 1 — un statut PAR NŒUD, incluant « non exprimable »
    // =========================================================================

    /**
     * Le dossier privé des enseignants n'est ni conforme, ni appliqué : l'octroi
     * de la classe posé sur la racine s'y propage et l'instruction de retrait est
     * acceptée sans effet. C'est LE mode de rupture mesuré, et il se dit.
     */
    #[Test]
    public function the_teachers_private_folder_is_never_green_on_a_propagating_backend(): void
    {
        $plan = $this->rootedClassPlan();
        $report = (new FakePropagatingBackend())->provision($plan);

        $profs = $report->for('_profs');

        $this->assertNotNull($profs);
        $this->assertSame(FileBackendOutcome::NonExprimable, $profs->outcome);
        $this->assertNotSame(FileBackendOutcome::Conforme, $profs->outcome);
        $this->assertNotSame(FileBackendOutcome::Applique, $profs->outcome);

        // Le detail NOMME l'octroi non refermable, il ne se contente pas de dire
        // « non supporté ».
        $this->assertStringContainsString('classe', (string) $profs->detail);
        $this->assertStringContainsString('acceptée sans effet', (string) $profs->detail);
    }

    #[Test]
    public function the_declined_node_is_a_permanent_model_limit_not_an_implementation_debt(): void
    {
        $report = (new FakePropagatingBackend())->provision($this->rootedClassPlan());
        $outcome = $report->for('_profs')->outcome;

        $this->assertTrue($outcome->isDecline());
        $this->assertTrue($outcome->isModelLimit());
        $this->assertFalse($outcome->isImplementationDebt());
        $this->assertFalse($outcome->isByDesign());
    }

    /**
     * Le contrôle qui donne son sens au précédent : un nœud dont la clôture ne
     * fuit PAS est bien appliqué. Sans lui, un double qui déclinerait TOUT
     * passerait le test ci-dessus.
     */
    #[Test]
    public function a_node_whose_closure_does_not_leak_is_applied_normally(): void
    {
        $report = (new FakePropagatingBackend())->provision($this->rootedClassPlan());

        $this->assertSame(FileBackendOutcome::Applique, $report->for('_travail')->outcome);
        $this->assertSame(FileBackendOutcome::Applique, $report->for(PlanNode::ROOT_PATH)->outcome);
    }

    // =========================================================================
    // Contrainte 2 — la relecture BALAIE, racine comprise
    // =========================================================================

    #[Test]
    public function inspect_sweeps_every_node_of_the_plan_including_the_root(): void
    {
        $plan = $this->rootedClassPlan();
        $report = (new FakePropagatingBackend())->inspect($plan);

        $this->assertSame($plan->nodePaths(), array_map(
            static fn ($o): string => $o->path,
            $report->observations,
        ));
        $this->assertNotNull($report->for(PlanNode::ROOT_PATH));
        $this->assertSame(FileBackendObservation::Observe, $report->for(PlanNode::ROOT_PATH)->status);
    }

    // =========================================================================
    // Contrainte 3 — l'idempotence est NORMALISÉE, les échecs ne le sont pas
    // =========================================================================

    /**
     * Trois sémantiques natives différentes pour « c'était déjà fait », trois
     * entrées de rapport, UN SEUL état — et aucun code de transport nulle part.
     */
    #[Test]
    public function the_three_native_idempotence_semantics_normalise_to_a_single_state(): void
    {
        $plan = $this->rootedClassPlan();

        $backend = new FakePropagatingBackend([
            PlanNode::ROOT_PATH => FakePropagatingBackend::NATIVE_DIRECTORY_EXISTS,
            '_travail' => FakePropagatingBackend::NATIVE_GROUP_EXISTS,
            '_travail/devoirs' => FakePropagatingBackend::NATIVE_SHARE_DEDUPLICATED,
        ]);

        $backend->provision($plan);          // premier passage : écriture
        $report = $backend->provision($plan); // rejeu : « c'était déjà fait »

        $paths = [PlanNode::ROOT_PATH, '_travail', '_travail/devoirs'];

        // Les trois sémantiques natives ont bien été TRAVERSÉES — sans quoi ce
        // test prouverait seulement qu'un backend rend toujours la même chose.
        $this->assertSame(
            [
                FakePropagatingBackend::NATIVE_DIRECTORY_EXISTS,
                FakePropagatingBackend::NATIVE_GROUP_EXISTS,
                FakePropagatingBackend::NATIVE_SHARE_DEDUPLICATED,
            ],
            array_map(static fn (string $p): string => $backend->nativeSemanticsSeen[$p], $paths),
        );

        // … et elles rendent UN SEUL état au-dessus de la ligne de contrat.
        $outcomes = array_map(static fn (string $p) => $report->for($p)->outcome, $paths);
        $this->assertSame(
            [FileBackendOutcome::Conforme, FileBackendOutcome::Conforme, FileBackendOutcome::Conforme],
            $outcomes,
        );

        $serialised = json_encode($report->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['405', '102', 'http', 'status'] as $transport) {
            $this->assertStringNotContainsStringIgnoringCase($transport, (string) $serialised);
        }
    }

    /**
     * La normalisation n'AVALE aucun échec net : les erreurs distinguables
     * mesurées restent des échecs, avec leur cause nommée.
     */
    #[Test]
    public function normalising_idempotence_never_swallows_a_clean_failure(): void
    {
        $plan = $this->rootedClassPlan();

        $backend = new FakePropagatingBackend([], [
            '_travail' => FakePropagatingBackend::CAUSE_MISSING_PATH,
            '_travail/devoirs' => FakePropagatingBackend::CAUSE_UNKNOWN_GROUP,
        ]);

        $backend->provision($plan);
        $report = $backend->provision($plan);

        $this->assertSame(FileBackendOutcome::Echec, $report->for('_travail')->outcome);
        $this->assertStringContainsString('chemin inexistant', (string) $report->for('_travail')->detail);
        $this->assertStringContainsString('groupe cible introuvable', (string) $report->for('_travail/devoirs')->detail);
        $this->assertCount(2, $report->failures());
    }

    // =========================================================================
    // Contrainte 4 — le plafond se DÉCLINE, et il dit laquelle de ses deux raisons
    // =========================================================================

    #[Test]
    public function the_ceiling_is_declined_as_a_model_limit_never_as_a_failure(): void
    {
        $plan = $this->rootedClassPlan();
        $report = (new FakePropagatingBackend())->quota($plan);

        $this->assertGreaterThan(0, $report->count());
        $this->assertCount(0, $report->failures());

        foreach ($report->entries as $entry) {
            $this->assertSame(FileBackendOutcome::NonExprimable, $entry->outcome);
            $this->assertTrue($entry->outcome->isModelLimit());
            $this->assertStringContainsString('par utilisateur', (string) $entry->detail);
        }
    }

    // =========================================================================
    // Contrainte 5 — la CLÔTURE traverse la ligne, et la fuite devient détectable
    // =========================================================================

    /**
     * Tout ce qu'un backend à propagation doit savoir pour refermer est dans ce
     * qu'on lui transmet : le nœud nomme le rôle sans octroi, et le plan donne les
     * SUJETS de ce rôle.
     */
    #[Test]
    public function the_backend_receives_the_closure_and_can_resolve_its_subjects(): void
    {
        $plan = $this->rootedClassPlan();

        $profs = $plan->node('_profs');
        $this->assertNotNull($profs);
        $this->assertSame(['classe'], $profs->closure);

        $subjects = $plan->roles['classe'] ?? [];
        $this->assertNotEmpty($subjects, 'le plan doit dire SUR QUI refermer, pas seulement quel rôle');
        $this->assertSame(PlanSubject::TYPE_USER_GROUP, $subjects[0]->type);
        $this->assertSame(self::GROUP_CLASSE_ID, $subjects[0]->id);
    }

    /**
     * LA PRÉFIGURATION de la comparaison de la story 60.4, écrite ici EN LIGNE :
     * on confronte l'observation d'un nœud à la clôture du plan et on retrouve la
     * fuite mesurée — un accès en lecture là où le plan n'a rien octroyé.
     *
     * Écrite en ligne à dessein : la comparaison partagée s'implémentera UNE fois,
     * au-dessus de la ligne, en 60.4. L'écrire maintenant en service la ferait
     * naître au mauvais endroit et sans son cas d'usage complet.
     */
    #[Test]
    public function comparing_the_observation_to_the_closure_reveals_the_measured_leak(): void
    {
        $plan = $this->rootedClassPlan();
        $backend = new FakePropagatingBackend();
        $backend->provision($plan);

        $inspection = $backend->inspect($plan);

        /** Rôles clos ICI dont un sujet apparaît pourtant dans la relecture. */
        $leaksOn = function (string $path) use ($plan, $inspection): array {
            $closedSubjects = [];
            foreach ($plan->node($path)->closure as $role) {
                foreach ($plan->roles[$role] ?? [] as $subject) {
                    $closedSubjects[$subject->sortKey()] = $role;
                }
            }

            $leaks = [];
            foreach ($inspection->for($path)->grants as $grant) {
                $role = $closedSubjects[$grant->subject->sortKey()] ?? null;
                if ($role !== null) {
                    $leaks[$role] = true;
                }
            }

            return array_keys($leaks);
        };

        $this->assertSame(
            ['classe'],
            $leaksOn('_profs'),
            'la relecture doit rendre un accès pour un rôle que le plan a clos ici : c\'est la fuite mesurée',
        );

        // CONTRÔLE INVERSE — sans lui, un détecteur qui crie toujours passerait.
        // Le dossier de travail a lui aussi une clôture (les enseignants
        // référents), mais aucun ancêtre ne l'octroie : rien ne fuit.
        $this->assertSame(['referents'], $plan->node('_travail')->closure);
        $this->assertNotEmpty($inspection->for('_travail')->grants);
        $this->assertSame([], $leaksOn('_travail'));
    }

    #[Test]
    public function an_observed_grant_stays_in_plan_vocabulary(): void
    {
        $plan = $this->rootedClassPlan();
        $backend = new FakePropagatingBackend();
        $backend->provision($plan);

        foreach ($backend->inspect($plan)->observed() as $observation) {
            foreach ($observation->grants as $grant) {
                $this->assertInstanceOf(ObservedGrant::class, $grant);
                $this->assertContains($grant->subject->type, PlanSubject::TYPES);
                $this->assertSame([], array_diff($grant->verbs, \App\Services\Filesystem\Plan\PlanGrant::VERBS));
            }
        }
    }

    // =========================================================================
    // La révocation ne détruit rien
    // =========================================================================

    #[Test]
    public function deprovision_covers_every_node_and_says_the_data_stays(): void
    {
        $plan = $this->rootedClassPlan();
        $backend = new FakePropagatingBackend();
        $backend->provision($plan);

        $report = $backend->deprovision($plan);

        $this->assertSame($plan->nodePaths(), array_map(
            static fn (NodeReconciliation $e): string => $e->path,
            $report->entries,
        ));
        $this->assertStringContainsString('les données restent', (string) $report->for('_travail')->detail);
    }
}
