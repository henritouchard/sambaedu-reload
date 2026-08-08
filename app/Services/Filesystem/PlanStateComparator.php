<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Enums\FileBackendObservation;
use App\Services\Filesystem\Backend\InspectionReport;
use App\Services\Filesystem\Backend\NodeObservation;
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
 * est en vocabulaire de plan : un nœud, un sujet par son identité interne, les
 * verbes attendus et les verbes constatés. Les quatre statuts agrégés de l'audit
 * historique survivent — un contrôleur d'environnement les consomme — mais ce sont
 * des VUES DÉRIVÉES des écarts, jamais le fait primaire.
 *
 * ---------------------------------------------------------------------------
 * **LA TABLE DE COMPARAISON, ÉCRITE — parce qu'elle est le cœur de la story.**
 *
 * Les trois états d'un octroi (ACTIF / SUSPENDU / rôle en CLÔTURE) doivent
 * traverser la comparaison sans jamais se confondre :
 *
 *  | désiré                    | observé               | verdict                                 |
 *  |---------------------------|-----------------------|-----------------------------------------|
 *  | ACTIF (verbes V)          | EXACTEMENT V          | conforme                                |
 *  | ACTIF (verbes V)          | un sous-ensemble de V | ÉCART — il manque des verbes            |
 *  | ACTIF (verbes V)          | un surensemble de V   | ÉCART — il y a des verbes en trop       |
 *  | ACTIF (verbes V)          | aucun verbe           | ÉCART                                   |
 *  | ACTIF (verbes V)          | absent                | ÉCART                                   |
 *  | SUSPENDU                  | aucun verbe           | CONFORME — la suspension est appliquée  |
 *  | SUSPENDU                  | au moins un verbe     | ÉCART — la suspension a FUI             |
 *  | SUSPENDU                  | absent                | ÉCART — matérialisation manquante       |
 *  | (aucun octroi au plan)    | quels que soient les verbes | ÉCART — en trop                   |
 *  | rôle en CLÔTURE           | —                     | RIEN : ni attendu, ni écart             |
 *
 * **Story 62.4 — l'égalité est une ÉGALITÉ D'ENSEMBLES, pas une comparaison de
 * niveaux.** Avec deux niveaux ordonnés, « moindre » avait un sens. Avec quatre
 * verbes combinables, deux octrois peuvent être INCOMPARABLES, et la seule question
 * honnête est « est-ce exactement ce qu'on voulait ? ». Un observé qui en fait
 * MOINS et un observé qui en fait PLUS sont tous deux des écarts — le second l'est
 * même davantage, puisque c'est un droit que personne n'a écrit.
 *
 * **Un désir INEXPRIMABLE reste un écart, et on ne le maquille pas.** Quand un
 * backend déclare ne pas savoir rendre un verbe ({@see \App\Enums\FileBackendOutcome::NonExprimable}),
 * le disque ne porte pas ce verbe — et la comparaison le dit. Absoudre l'écart
 * « parce qu'on savait » reviendrait à afficher conforme un état qui ne l'est pas :
 * l'administrateur perdrait le seul endroit où la limite se voit en continu. Le
 * grisé de ce qui n'est pas exprimable appartient à l'écran de composition (62.6),
 * pas à la comparaison.
 *
 * Les deux lignes qui comptent le plus sont les deux du milieu. « Suspendu observé
 * aucun = conforme » est ce qui empêche une désactivation d'être relue comme une
 * suppression à réparer. « Suspendu observé avec accès = écart » est la seule
 * façon de VOIR qu'une suspension n'a pas pris — c'est la fuite qu'il faut
 * montrer, et un vocabulaire d'observation à deux valeurs l'aurait rendue
 * invisible.
 *
 * **La clôture ne produit aucun écart TANT QUE PERSONNE NE L'OBSERVE, et ce n'est
 * pas un oubli.** Il n'existe pas de refus en POSIX : l'absence d'octroi EST la
 * fermeture. Le backend n'écrit rien pour elle, donc il n'y a rien à comparer.
 * C'est un backend à PROPAGATION qui devra la matérialiser — et c'est là seulement
 * que la clôture deviendra comparable.
 *
 * ---------------------------------------------------------------------------
 * **STORY 61.3 — CE MOMENT EST ARRIVÉ, ET LA COMPARAISON EST GATÉE SUR LA DONNÉE.**
 *
 * Un backend à propagation matérialise la clôture en règles de masque, donc il sait
 * la RELIRE : {@see NodeObservation::$closure} la porte. La comparaison ne se
 * prononce QUE lorsque l'observation porte cette donnée :
 *
 *  | observation      | comportement                                            |
 *  |------------------|---------------------------------------------------------|
 *  | `closure = null` | aucune comparaison — POSIX et l'aperçu, inchangés        |
 *  | `closure = [...]`| égalité d'ensembles avec la clôture ATTENDUE du nœud     |
 *
 * L'attendu est DÉRIVÉ, comme la clôture elle-même : les sujets des rôles clos du
 * nœud, moins ceux qui y ont reçu un octroi (un sujet octroyé par un rôle et clos
 * par un autre reste octroyé — union au plus permissif, doctrine de l'epic).
 *
 * **Ce que cette comparaison attrape, et qu'aucune autre n'attrapait** : une règle
 * de masque retirée à la main sur le dossier privé des enseignants. L'octroi de la
 * classe, lui, reste parfaitement conforme — c'est la CLÔTURE qui a sauté, et sans
 * cette table, la fuite serait invisible sur un écran tout vert. C'est exactement
 * le mode de rupture que le sondage d'ouverture d'epic avait mesuré.
 *
 * Le résultat vit dans une clé `closure` ADDITIVE de chaque nœud : les
 * consommateurs existants lisent `differences` et `status`, et ne voient pas la
 * différence — sauf sur le statut, qui devient `ecart` quand la clôture diverge, ce
 * qui est précisément le but.
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
     *       expected: list<string>|null,
     *       observed: list<string>|null,
     *     }>,
     *     closure?: list<array{
     *       subject: array{type:string,id:int,edge_role:string|null},
     *       expected_closed: bool,
     *       observed_closed: bool,
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
                : $this->compareNode($plan, $node, $observation);
        }

        return ['status' => $this->aggregate($nodes), 'nodes' => $nodes];
    }

    /**
     * @return array{path:string,status:string,detail:string|null,differences:list<array{subject:array{type:string,id:int,edge_role:string|null},expected:list<string>|null,observed:list<string>|null}>}
     */
    private function compareNode(FilePlan $plan, PlanNode $node, NodeObservation $observation): array
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
            // une entrée présente et VIDE. Un octroi actif attend ses verbes.
            $expected[$key] = $grant->isActive() ? $grant->verbs : [];
            $subjects[$key] = $grant->subject;
        }

        $observed = [];
        foreach ($observation->grants as $grant) {
            $key = $grant->subject->sortKey();
            $observed[$key] = $grant->verbs;
            $subjects[$key] ??= $grant->subject;
        }

        $differences = [];

        foreach ($expected as $key => $verbs) {
            $seen = $observed[$key] ?? null;
            // Égalité d'ENSEMBLES : les deux listes sont en ordre canonique, donc
            // l'identité stricte de listes EST l'égalité d'ensembles.
            if ($seen === $verbs) {
                continue;
            }
            $differences[] = $this->difference($subjects[$key], $verbs, $seen);
        }

        foreach ($observed as $key => $verbs) {
            if (array_key_exists($key, $expected)) {
                continue;
            }
            $differences[] = $this->difference($subjects[$key], null, $verbs);
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

        // Story 61.3 — la CLÔTURE, quand et seulement quand le backend l'observe.
        $closure = $this->compareClosure($plan, $node, $observation, $expected);

        $result = [
            'path' => $node->path,
            'status' => ($differences === [] && $closure === [] && ! $hasUnnamed) ? self::NODE_CONFORME : self::NODE_ECART,
            'detail' => $observation->detail,
            'differences' => $differences,
        ];

        if ($observation->closure !== null) {
            $result['closure'] = $closure;
        }

        return $result;
    }

    /**
     * Les DIVERGENCES de clôture d'un nœud — rien d'autre.
     *
     * Liste vide quand le backend n'observe pas la clôture (`null`) : ne rien
     * pouvoir dire n'est pas la même chose que ne rien avoir à dire, mais dans les
     * deux cas la comparaison ne produit aucun écart. La distinction est portée par
     * la présence même de la clé dans le résultat.
     *
     * @param  array<string, list<string>>  $expectedGrants  clés de tri des sujets octroyés ici
     * @return list<array{subject:array{type:string,id:int,edge_role:string|null},expected_closed:bool,observed_closed:bool}>
     */
    private function compareClosure(FilePlan $plan, PlanNode $node, NodeObservation $observation, array $expectedGrants): array
    {
        if ($observation->closure === null) {
            return [];
        }

        /** @var array<string, PlanSubject> $subjects */
        $subjects = [];

        // ATTENDU : les sujets des rôles clos ici, MOINS ceux qui y ont reçu un
        // octroi. Un sujet octroyé par un rôle et clos par un autre reste octroyé —
        // la clôture n'a jamais été une interdiction.
        $wanted = [];
        foreach ($node->closure as $role) {
            foreach ($plan->roles[$role] ?? [] as $subject) {
                $key = $subject->sortKey();
                if (array_key_exists($key, $expectedGrants)) {
                    continue;
                }
                $wanted[$key] = true;
                $subjects[$key] ??= $subject;
            }
        }

        $seen = [];
        foreach ($observation->closure as $subject) {
            $key = $subject->sortKey();
            $seen[$key] = true;
            $subjects[$key] ??= $subject;
        }

        $divergences = [];
        foreach ($subjects as $key => $subject) {
            $expected = isset($wanted[$key]);
            $observed = isset($seen[$key]);
            if ($expected === $observed) {
                continue;
            }

            $divergences[] = [
                'subject' => $subject->toArray(),
                'expected_closed' => $expected,
                'observed_closed' => $observed,
            ];
        }

        usort(
            $divergences,
            static fn (array $a, array $b): int => strcmp(
                $a['subject']['type'] . $a['subject']['id'] . ($a['subject']['edge_role'] ?? ''),
                $b['subject']['type'] . $b['subject']['id'] . ($b['subject']['edge_role'] ?? ''),
            ),
        );

        return $divergences;
    }

    /**
     * @param  list<string>|null  $expected  `null` = le plan n'attend rien ici
     * @param  list<string>|null  $observed  `null` = aucune entrée relue
     * @return array{subject:array{type:string,id:int,edge_role:string|null},expected:list<string>|null,observed:list<string>|null}
     */
    private function difference(PlanSubject $subject, ?array $expected, ?array $observed): array
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

    /**
     * Libellé FR d'une liste de VERBES, pour l'affichage.
     *
     * Trois cas, et ils sont distincts : `null` = il n'y a rien à cet endroit (le
     * plan n'attend rien, ou rien n'a été relu) ; la liste VIDE = une entrée
     * présente qui ne donne rien, c'est-à-dire une suspension matérialisée ; sinon,
     * les verbes énumérés dans leur ordre canonique.
     *
     * L'affichage est libre de ses libellés — l'ordre canonique est un choix de
     * sérialisation, pas de présentation ; on le suit ici parce qu'il rend deux
     * lignes de tableau comparables à l'œil.
     *
     * @param  list<string>|null  $verbs
     */
    public static function accessLabel(?array $verbs): string
    {
        if ($verbs === null) {
            return '—';
        }
        if ($verbs === []) {
            return 'Aucun';
        }

        return implode(' + ', array_map(self::verbLabel(...), $verbs));
    }

    /** Libellé FR d'un verbe. Vocabulaire de PLAN : aucun mot de mécanisme. */
    public static function verbLabel(string $verb): string
    {
        return match ($verb) {
            PlanGrant::VERB_LIRE => 'Lire',
            PlanGrant::VERB_EDITER => 'Éditer',
            PlanGrant::VERB_CREER => 'Créer',
            PlanGrant::VERB_SUPPRIMER => 'Supprimer',
            default => $verb,
        };
    }
}
