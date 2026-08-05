<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Support;

use App\Enums\FileBackendName;
use App\Enums\FileBackendOutcome;
use App\Services\Filesystem\Backend\FileBackend;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeObservation;
use App\Services\Filesystem\Backend\NodeReconciliation;
use App\Services\Filesystem\Backend\ReconciliationReport;
use App\Services\Filesystem\Plan\FilePlan;

/**
 * Story 60.4 — DOUBLE de backend pour les tests AU-DESSUS de la ligne.
 *
 * **Pourquoi il existe, et pourquoi il n'y a pas de simulation de processus dans
 * les tests d'orchestration.** La règle de la story est nette : sous la ligne, la
 * simulation d'exécution est l'outil normal (le backend exécute) ; au-dessus, elle
 * est INTERDITE — un test d'orchestrateur qui en réclamerait une signalerait que
 * la coupe a fui, puisque cela voudrait dire que l'orchestrateur déclenche
 * lui-même des commandes.
 *
 * Ce double se substitue à l'implémentation réelle DANS LE CONTENEUR, sur la
 * classe que le registre résout. L'orchestrateur passe donc par le vrai chemin —
 * projection, résolution par la colonne, délégation — et seule l'exécution est
 * remplacée.
 *
 * Il respecte les invariants du contrat : ses rapports passent par les fabriques,
 * donc leur périmètre est confronté au plan comme celui d'un vrai backend.
 */
final class RecordingBackend implements FileBackend
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<FilePlan> */
    public array $plans = [];

    public FileBackendOutcome $provisionOutcome = FileBackendOutcome::Applique;

    /** @var callable(FilePlan): InspectionReport|null */
    public $inspectUsing = null;

    public function name(): FileBackendName
    {
        return FileBackendName::Posix;
    }

    /**
     * Fait lever la mise en place — le seul moyen d'éprouver le chemin où le
     * geste enfilé échoue AVANT de produire le moindre rapport.
     */
    public bool $provisionThrows = false;

    public function provision(FilePlan $plan): ReconciliationReport
    {
        $this->calls[] = 'provision';
        $this->plans[] = $plan;

        if ($this->provisionThrows) {
            throw new \RuntimeException('la préparation a échoué (double de test)');
        }

        $outcome = $this->provisionOutcome;

        return ReconciliationReport::covering($this->name(), $plan, array_map(
            static fn (string $path): NodeReconciliation => new NodeReconciliation(
                $path,
                $outcome,
                $outcome->requiresDetail() ? 'cause de test' : null,
            ),
            $plan->nodePaths(),
        ));
    }

    public function deprovision(FilePlan $plan): ReconciliationReport
    {
        $this->calls[] = 'deprovision';
        $this->plans[] = $plan;

        return ReconciliationReport::covering($this->name(), $plan, array_map(
            static fn (string $path): NodeReconciliation => NodeReconciliation::applique($path),
            $plan->nodePaths(),
        ));
    }

    public function inspect(FilePlan $plan): InspectionReport
    {
        $this->calls[] = 'inspect';
        $this->plans[] = $plan;

        if ($this->inspectUsing !== null) {
            return ($this->inspectUsing)($plan);
        }

        return InspectionReport::covering($this->name(), $plan, array_map(
            static fn (string $path): NodeObservation => NodeObservation::observed($path),
            $plan->nodePaths(),
        ));
    }

    public function quota(FilePlan $plan): ReconciliationReport
    {
        $this->calls[] = 'quota';

        return ReconciliationReport::coveringCapped($this->name(), $plan, []);
    }

    /** Story 60.5 — emplacement d'affichage : ce double n'écrit sur aucun disque. */
    public function location(FilePlan $plan): ?string
    {
        return null;
    }
}
