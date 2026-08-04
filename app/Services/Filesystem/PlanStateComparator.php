<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Enums\FileBackendObservation;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeObservation;
use App\Services\Filesystem\Backend\ObservedGrant;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;

/**
 * Story 60.4 — LA COMPARAISON désiré/observé, écrite UNE FOIS, AU-DESSUS de la
 * ligne de contrat.
 *
 * Le contrat dit ce qui EST ; il ne dit pas si c'est ce qu'on voulait. La mettre
 * dans le backend obligerait chaque backend à la réécrire, et deux backends
 * l'écriraient différemment — c'est le patron déjà en service ailleurs dans le
 * dépôt : la précédence s'implémente une fois, jamais dans le fournisseur d'état.
 *
 * Elle remplace l'audit de dérive de l'Epic 34, qui comparait des LIGNES DE
 * PERMISSION BRUTES et les affichait telles quelles à l'administrateur. Ici, tout
 * est en vocabulaire de plan : un nœud, un sujet par son identité interne, un
 * accès attendu et un accès constaté. Les quatre statuts agrégés de l'audit
 * historique survivent — un contrôleur d'environnement les consomme — mais ce sont
 * des VUES DÉRIVÉES des écarts, jamais le fait primaire.
 *
 * ---------------------------------------------------------------------------
 * **LA TABLE DE COMPARAISON, ÉCRITE — parce qu'elle est le cœur de la story.**
 *
 * Les trois états d'un octroi (ACTIF / SUSPENDU / rôle en CLÔTURE) doivent
 * traverser la comparaison sans jamais se confondre :
 *
 *  | désiré                    | observé              | verdict                                  |
 *  |---------------------------|----------------------|------------------------------------------|
 *  | ACTIF `ro`/`rw`           | même accès           | conforme                                 |
 *  | ACTIF `ro`/`rw`           | accès moindre        | ÉCART                                    |
 *  | ACTIF `ro`/`rw`           | « aucun »            | ÉCART                                    |
 *  | ACTIF `ro`/`rw`           | absent               | ÉCART                                    |
 *  | SUSPENDU                  | « aucun »            | CONFORME — la suspension est appliquée   |
 *  | SUSPENDU                  | `ro`/`rw`            | ÉCART — la suspension a FUI              |
 *  | SUSPENDU                  | absent               | ÉCART — matérialisation manquante        |
 *  | (aucun octroi au plan)    | quel que soit l'accès| ÉCART — en trop                          |
 *  | rôle en CLÔTURE           | —                    | RIEN : ni attendu, ni écart              |
 *
 * Les deux lignes qui comptent le plus sont les deux du milieu. « Suspendu observé
 * aucun = conforme » est ce qui empêche une désactivation d'être relue comme une
 * suppression à réparer. « Suspendu observé avec accès = écart » est la seule
 * façon de VOIR qu'une suspension n'a pas pris — c'est la fuite qu'il faut
 * montrer, et un vocabulaire d'observation à deux valeurs l'aurait rendue
 * invisible.
 *
 * **La clôture ne produit aucun écart, et ce n'est pas un oubli.** Il n'existe pas
 * de refus en POSIX : l'absence d'octroi EST la fermeture. Le backend n'écrit rien
 * pour elle, donc il n'y a rien à comparer. C'est un backend à PROPAGATION qui
 * devra la matérialiser — et c'est là seulement que la clôture deviendra
 * comparable.
 *
 * **Un nœud qu'on n'a pas lu n'est jamais déclaré conforme.** Une ignorance n'est
 * pas une observation : elle remonte en `error` agrégé, comme une relecture en
 * échec. Sous une politique d'écart stricte, l'inverse ferait passer un silence
 * pour une bonne nouvelle.
 *
 * **AUCUNE dépendance d'exécution ici** — le comparateur ne connaît ni processus,
 * ni permission, ni chemin absolu. Une règle d'architecture nommée le tient.
 */
final class PlanStateComparator
{
    /** Tout est dans l'état voulu. */
    public const STATUS_CONFORME = 'conforme';

    /** Au moins un écart. */
    public const STATUS_DRIFTED = 'drifted';

    /** Au moins un nœud absent côté backend, et aucun échec. */
    public const STATUS_ABSENT = 'absent';

    /** Au moins un nœud illisible : on ne conclut rien. */
    public const STATUS_ERROR = 'error';

    /** État d'un NŒUD. */
    public const NODE_CONFORME = 'conforme';

    public const NODE_ECART = 'ecart';

    public const NODE_ABSENT = 'absent';

    public const NODE_ECHEC = 'echec';

    public const NODE_NON_OBSERVE = 'non_observe';

    /**
     * Rend la dérive d'un plan en vocabulaire de plan.
     *
     * @return array{
     *   status: string,
     *   nodes: list<array{
     *     path: string,
     *     status: string,
     *     detail: string|null,
     *     differences: list<array{
     *       subject: array{type:string,id:int,edge_role:string|null},
     *       expected: string|null,
     *       observed: string|null,
     *     }>,
     *   }>,
     * }
     */
    public function compare(FilePlan $plan, InspectionReport $inspection): array
    {
        $nodes = [];

        foreach ($plan->nodes as $node) {
            $observation = $inspection->for($node->path);

            $nodes[] = $observation === null
                // Ne peut pas arriver par le contrat (la complétude est validée à
                // la construction d'une relecture) — mais une relecture peut aussi
                // arriver par un tableau reconstruit ailleurs, et un nœud sans
                // observation qui se lirait « conforme » est exactement la fuite
                // que tout l'epic combat. On le dit.
                ? [
                    'path' => $node->path,
                    'status' => self::NODE_NON_OBSERVE,
                    'detail' => 'aucune observation ne couvre ce nœud : rien ne peut en être conclu.',
                    'differences' => [],
                ]
                : $this->compareNode($node, $observation);
        }

        return ['status' => $this->aggregate($nodes), 'nodes' => $nodes];
    }

    /**
     * @return array{path:string,status:string,detail:string|null,differences:list<array{subject:array{type:string,id:int,edge_role:string|null},expected:string|null,observed:string|null}>}
     */
    private function compareNode(PlanNode $node, NodeObservation $observation): array
    {
        if ($observation->status !== FileBackendObservation::Observe) {
            return [
                'path' => $node->path,
                'status' => match ($observation->status) {
                    FileBackendObservation::Absent => self::NODE_ABSENT,
                    FileBackendObservation::Echec => self::NODE_ECHEC,
                    default => self::NODE_NON_OBSERVE,
                },
                'detail' => $observation->detail,
                'differences' => [],
            ];
        }

        $expected = [];
        $subjects = [];
        foreach ($node->grants as $grant) {
            $key = $grant->subject->sortKey();
            // Un octroi SUSPENDU attend la forme matérialisée de la suspension :
            // une entrée présente et vide. Un octroi actif attend son accès.
            $expected[$key] = $grant->isActive() ? $grant->access : ObservedGrant::ACCESS_NONE;
            $subjects[$key] = $grant->subject;
        }

        $observed = [];
        foreach ($observation->grants as $grant) {
            $key = $grant->subject->sortKey();
            $observed[$key] = $grant->access;
            $subjects[$key] ??= $grant->subject;
        }

        $differences = [];

        foreach ($expected as $key => $access) {
            $seen = $observed[$key] ?? null;
            if ($seen === $access) {
                continue;
            }
            $differences[] = $this->difference($subjects[$key], $access, $seen);
        }

        foreach ($observed as $key => $access) {
            if (array_key_exists($key, $expected)) {
                continue;
            }
            $differences[] = $this->difference($subjects[$key], null, $access);
        }

        usort(
            $differences,
            static fn (array $a, array $b): int => strcmp(
                $a['subject']['type'] . $a['subject']['id'] . ($a['subject']['edge_role'] ?? ''),
                $b['subject']['type'] . $b['subject']['id'] . ($b['subject']['edge_role'] ?? ''),
            ),
        );

        // Une entrée relue sans identité connue est comptée dans le détail de
        // l'observation : elle ne peut pas être nommée, mais elle empêche de
        // déclarer le nœud conforme.
        $hasUnnamed = $observation->detail !== null && $observation->detail !== '';

        return [
            'path' => $node->path,
            'status' => ($differences === [] && ! $hasUnnamed) ? self::NODE_CONFORME : self::NODE_ECART,
            'detail' => $observation->detail,
            'differences' => $differences,
        ];
    }

    /**
     * @return array{subject:array{type:string,id:int,edge_role:string|null},expected:string|null,observed:string|null}
     */
    private function difference(PlanSubject $subject, ?string $expected, ?string $observed): array
    {
        return [
            'subject' => $subject->toArray(),
            'expected' => $expected,
            'observed' => $observed,
        ];
    }

    /**
     * Agrégats DÉRIVÉS, dans l'ordre où ils comptent : un échec ne se laisse pas
     * masquer par une absence, une absence pas par un écart.
     *
     * @param  list<array{path:string,status:string,detail:string|null,differences:list<mixed>}>  $nodes
     */
    private function aggregate(array $nodes): string
    {
        $statuses = array_column($nodes, 'status');

        if (in_array(self::NODE_ECHEC, $statuses, true) || in_array(self::NODE_NON_OBSERVE, $statuses, true)) {
            return self::STATUS_ERROR;
        }
        if (in_array(self::NODE_ABSENT, $statuses, true)) {
            return self::STATUS_ABSENT;
        }
        if (in_array(self::NODE_ECART, $statuses, true)) {
            return self::STATUS_DRIFTED;
        }

        return self::STATUS_CONFORME;
    }

    /** Libellé FR d'un accès, pour l'affichage. `null` = rien à cet endroit. */
    public static function accessLabel(?string $access): string
    {
        return match ($access) {
            PlanGrant::ACCESS_RO => 'Lire',
            PlanGrant::ACCESS_RW => 'Modifier',
            ObservedGrant::ACCESS_NONE => 'Aucun',
            default => '—',
        };
    }
}
