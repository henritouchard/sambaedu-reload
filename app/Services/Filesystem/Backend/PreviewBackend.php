<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanNode;

/**
 * Story 60.3 — le backend qui N'EXÉCUTE RIEN, et qui le dit.
 *
 * Il sert l'aperçu avant application : montrer à l'administrateur ce qu'un plan
 * dit, AVANT que quoi que ce soit ne soit écrit. C'est le premier livrable visible
 * de l'epic, et c'est aussi la seconde implémentation du contrat — celle qui
 * empêche le contrat d'être le premier backend déguisé.
 *
 * **Ce qu'il prouve, et ce qu'il ne prouve PAS.** Il prouve que le contrat est
 * satisfaisable par autre chose que le serveur de fichiers historique, et que rien
 * dans les signatures n'oblige à exécuter. Il ne prouve RIEN sur la justesse du
 * contrat : n'exécutant rien, il satisferait aussi un contrat mauvais. Cette
 * preuve-là est portée ailleurs — par le double propagateur des tests (qui
 * transcrit les mesures du sondage) et par le squelette jetable écrit contre une
 * instance réelle.
 *
 * **PUR.** Aucune entrée/sortie d'aucune sorte : pas de fichier, pas de réseau,
 * pas de base, pas de processus. Une règle d'architecture le tient.
 *
 * **Il ne ment sur rien** : il ne répond jamais `conforme` (il n'a rien vérifié) et
 * jamais `absent` (il n'a rien cherché). Il répond `non_execute` à ce qui écrit et
 * `non_observable` à ce qui lit — trois mots qui disent la vérité plutôt qu'un mot
 * rassurant qui la contredirait.
 *
 * **Il rend visible ce qu'il a REÇU.** Quand un nœud porte une clôture (des rôles
 * qui n'ont rien reçu ici), il la nomme dans son `detail`. C'est la preuve, à
 * l'écran, que la clôture traverse la ligne de contrat intacte : si elle était
 * filtrée ou résumée au passage, l'aperçu ne pourrait pas la montrer.
 */
final class PreviewBackend implements FileBackend
{
    public function name(): FileBackendName
    {
        return FileBackendName::Preview;
    }

    public function provision(FilePlan $plan): ReconciliationReport
    {
        return ReconciliationReport::covering(
            $this->name(),
            $plan,
            array_map(
                fn (PlanNode $node): NodeReconciliation => NodeReconciliation::nonExecute(
                    $node->path,
                    $this->describe($node, 'Aucune écriture : aperçu du plan.'),
                ),
                $plan->nodes,
            ),
        );
    }

    public function deprovision(FilePlan $plan): ReconciliationReport
    {
        return ReconciliationReport::covering(
            $this->name(),
            $plan,
            array_map(
                fn (PlanNode $node): NodeReconciliation => NodeReconciliation::nonExecute(
                    $node->path,
                    $this->describe($node, 'Aucune révocation : aperçu du plan.'),
                ),
                $plan->nodes,
            ),
        );
    }

    /**
     * Balaie TOUS les nœuds — racine comprise — et rend `non_observable` partout.
     *
     * Il balaie quand même, alors qu'il sait d'avance sa réponse : le balayage est
     * la propriété du contrat, et un backend qui « optimiserait » en rendant une
     * réponse unique serait précisément le backend qui oublie la racine.
     */
    public function inspect(FilePlan $plan): InspectionReport
    {
        return InspectionReport::covering(
            $this->name(),
            $plan,
            array_map(
                static fn (PlanNode $node): NodeObservation => NodeObservation::nonObservable(
                    $node->path,
                    'Aucune relecture : ce backend n\'observe rien et ne prétend pas le contraire.',
                ),
                $plan->nodes,
            ),
        );
    }

    /**
     * Répond `non_execute` sur les seuls nœuds à plafond.
     *
     * Ni `non_exprimable` (ce n'est pas une limite de modèle : il n'a pas de
     * modèle), ni `non_implemente` (ce n'est pas une dette : rien n'était à
     * brancher). Ne rien faire EST sa conception.
     */
    public function quota(FilePlan $plan): ReconciliationReport
    {
        $capped = $plan->cappedNodePaths();

        return ReconciliationReport::coveringCapped(
            $this->name(),
            $plan,
            array_map(
                static fn (string $path): NodeReconciliation => NodeReconciliation::nonExecute(
                    $path,
                    'Aucun plafond posé : aperçu du plan.',
                ),
                $capped,
            ),
        );
    }

    /**
     * Le `detail` d'un nœud : la phrase de base, augmentée de la CLÔTURE quand le
     * nœud en porte une.
     *
     * Les rôles clos sont nommés par leur clé de recette — une donnée du plan,
     * jamais un nom système. La garde de neutralité s'exerce sur ce texte
     * précisément parce qu'il est le seul champ libre d'un rapport.
     */
    private function describe(PlanNode $node, string $base): string
    {
        if ($node->closure === []) {
            return $base;
        }

        return $base . ' Rôles sans octroi ici (clôture reçue du plan) : '
            . implode(', ', $node->closure) . '.';
    }

    /**
     * Story 60.5 — l'aperçu n'écrit NULLE PART, donc il n'a pas d'emplacement.
     * Rendre un chemin plausible serait la pire réponse : elle laisserait croire
     * qu'un aperçu vise un endroit réel.
     */
    public function location(FilePlan $plan): ?string
    {
        return null;
    }
}
