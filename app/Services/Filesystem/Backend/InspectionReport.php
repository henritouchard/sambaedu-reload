<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Enums\FileBackendObservation;
use App\Exceptions\Filesystem\InvalidBackendReportException;
use App\Services\Filesystem\Plan\FilePlan;

/**
 * Story 60.3 — état RELU d'un plan : une observation PAR NŒUD.
 *
 * **Le balayage est dans la SIGNATURE, pas dans la bonne volonté du backend.** Le
 * sondage d'ouverture d'epic a mesuré qu'une lecture unique de sous-arbre, sur une
 * instance réelle, rend les sous-chemins **mais pas la racine** : une relecture
 * « en un appel » est structurellement incomplète, et sous une politique d'écart
 * STRICTE l'incomplétude se lit comme une conformité. La signature reçoit donc un
 * plan et rend une observation par nœud, racine comprise ; la complétude est
 * validée à la construction, exactement comme celle d'un rapport de réconciliation.
 *
 * **La comparaison désiré/observé n'est PAS ici.** Ce rapport dit ce qui EST ; il
 * ne dit pas si c'est ce qu'on voulait. La comparaison s'implémentera UNE fois
 * au-dessus de la ligne, en 60.4 — patron déjà en service ailleurs dans le dépôt :
 * la précédence s'implémente une fois, jamais dans le fournisseur d'état. La
 * mettre ici obligerait chaque backend à la réécrire, et deux backends
 * l'écriraient différemment.
 */
final class InspectionReport
{
    public readonly FileBackendName $backend;

    /** @var list<NodeObservation> dans l'ordre canonique des nœuds du plan */
    public readonly array $observations;

    /**
     * PRIVÉ : hors de {@see covering()}, personne ne fabrique une relecture dont
     * le périmètre n'a pas été confronté au plan.
     *
     * @param  list<NodeObservation>  $observations
     */
    private function __construct(FileBackendName $backend, array $observations)
    {
        $this->backend = $backend;
        $this->observations = array_values($observations);
    }

    /**
     * **Une relecture ne se sérialise pas nativement** — même raison que pour le
     * rapport de réconciliation : `unserialize()` ne passe par aucune fabrique et
     * restaure les propriétés directement, `readonly` comprises. Une relecture
     * amputée d'un nœud se comparerait « conforme » à un état incomplet, ce qui est
     * exactement le mode de rupture que la complétude par construction empêche.
     *
     * Format de transport : {@see toArray()}.
     *
     * @throws InvalidBackendReportException toujours
     */
    public function __serialize(): array
    {
        throw InvalidBackendReportException::make(
            'une relecture d\'état ne se sérialise pas nativement : son périmètre ne survivrait pas au '
            . 'voyage. Transporte-la en tableau (toArray()).'
        );
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidBackendReportException toujours
     */
    public function __unserialize(array $data): void
    {
        throw InvalidBackendReportException::make(
            'une relecture d\'état ne se désérialise pas nativement : son périmètre n\'aurait été confronté '
            . 'à aucun plan.'
        );
    }

    /**
     * Relecture couvrant TOUS les nœuds du plan, racine comprise.
     *
     * @param  list<NodeObservation>  $observations
     *
     * @throws InvalidBackendReportException si le périmètre ne correspond pas
     */
    public static function covering(FileBackendName $backend, FilePlan $plan, array $observations): self
    {
        $byPath = [];
        foreach ($observations as $observation) {
            if (! $observation instanceof NodeObservation) {
                throw InvalidBackendReportException::make(sprintf(
                    'le backend « %s » a rapporté une entrée qui n\'est pas une observation de nœud.',
                    $backend->value,
                ));
            }
            if (array_key_exists($observation->path, $byPath)) {
                throw InvalidBackendReportException::make(sprintf(
                    'le backend « %s » observe DEUX FOIS le nœud « %s ».',
                    $backend->value,
                    $observation->path,
                ));
            }
            $byPath[$observation->path] = $observation;
        }

        $ordered = [];
        $missing = [];
        foreach ($plan->nodePaths() as $path) {
            if (! array_key_exists($path, $byPath)) {
                $missing[] = $path;

                continue;
            }
            $ordered[] = $byPath[$path];
            unset($byPath[$path]);
        }

        $unexpected = array_keys($byPath);

        if ($missing !== [] || $unexpected !== []) {
            throw InvalidBackendReportException::make(sprintf(
                'la relecture du backend « %s » ne balaie pas les nœuds du plan : %s%s%s. '
                . 'Une relecture qui saute un nœud (la racine, typiquement — le piège MESURÉ) le rend '
                . 'indéfiniment conforme aux yeux de la détection d\'écart.',
                $backend->value,
                $missing !== [] ? 'nœuds non observés [' . implode(', ', $missing) . ']' : '',
                ($missing !== [] && $unexpected !== []) ? ' ; ' : '',
                $unexpected !== [] ? 'nœuds hors plan [' . implode(', ', $unexpected) . ']' : '',
            ));
        }

        return new self($backend, $ordered);
    }

    // =========================================================================
    // Vues DÉRIVÉES
    // =========================================================================

    /** @return list<NodeObservation> */
    public function withStatus(FileBackendObservation $status): array
    {
        return array_values(array_filter(
            $this->observations,
            static fn (NodeObservation $o): bool => $o->status === $status,
        ));
    }

    /** @return list<NodeObservation> */
    public function observed(): array
    {
        return $this->withStatus(FileBackendObservation::Observe);
    }

    /** @return list<NodeObservation> */
    public function absent(): array
    {
        return $this->withStatus(FileBackendObservation::Absent);
    }

    /** @return list<NodeObservation> */
    public function unobservable(): array
    {
        return $this->withStatus(FileBackendObservation::NonObservable);
    }

    /** @return list<NodeObservation> */
    public function failures(): array
    {
        return $this->withStatus(FileBackendObservation::Echec);
    }

    public function count(): int
    {
        return count($this->observations);
    }

    public function for(string $path): ?NodeObservation
    {
        foreach ($this->observations as $observation) {
            if ($observation->path === $path) {
                return $observation;
            }
        }

        return null;
    }

    /** @return array{backend:string,nodes:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'backend' => $this->backend->value,
            'nodes' => array_map(static fn (NodeObservation $o): array => $o->toArray(), $this->observations),
        ];
    }
}
