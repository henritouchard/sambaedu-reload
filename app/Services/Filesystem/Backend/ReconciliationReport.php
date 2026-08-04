<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Enums\FileBackendOutcome;
use App\Exceptions\Filesystem\InvalidBackendReportException;
use App\Services\Filesystem\Plan\FilePlan;

/**
 * Story 60.3 — INSTANTANÉ de ce qu'un backend a fait, nœud par nœud.
 *
 * **Il n'y a pas de booléen global, et ce n'est pas un oubli.** Le sondage
 * d'ouverture d'epic a mesuré une réussite qui n'en était pas une : l'instruction
 * de retrait sur le dossier privé des enseignants est acceptée `200 OK`, n'a aucun
 * effet, et la relecture rend ensuite un accès là où on demandait zéro. Un rapport
 * qui agrège en « ça a marché » redit exactement ce mensonge. Les agrégats
 * existent ({@see converged()}, {@see failures()}, …) mais ce sont des VUES
 * DÉRIVÉES des entrées : le fait primaire est toujours l'entrée du nœud.
 *
 * **La complétude est un invariant de CONSTRUCTION, pas une recommandation.** Un
 * rapport ne se construit qu'en couvrant EXACTEMENT le périmètre attendu du plan :
 * une entrée par nœud, ni plus ni moins. Omettre un nœud n'est donc pas
 * « découragé », c'est impossible — et c'est bien l'omission qu'il fallait rendre
 * impossible, puisque la fuite mesurée est un nœud dont personne ne parle. Même
 * philosophie que la clôture inauthorable du plan : la propriété est structurelle
 * ou elle n'est pas.
 *
 * **Deux périmètres, un seul type.** {@see covering()} couvre TOUS les nœuds du
 * plan (`provision`, `deprovision`) ; {@see coveringCapped()} couvre les seuls
 * nœuds à plafond (`quota`). Un type dédié au plafond n'apporterait aucune
 * propriété nouvelle — mêmes invariants, même vocabulaire, seul le périmètre
 * change — et coûterait de la surface pour rien.
 */
final class ReconciliationReport
{
    /** Périmètre : tous les nœuds du plan. */
    public const SCOPE_PLAN = 'plan';

    /** Périmètre : les seuls nœuds portant un plafond. */
    public const SCOPE_CAPPED = 'capped';

    public readonly FileBackendName $backend;

    /** {@see SCOPE_PLAN} ou {@see SCOPE_CAPPED} — ce que ce rapport prétend couvrir. */
    public readonly string $scope;

    /** @var list<NodeReconciliation> dans l'ordre canonique des nœuds du plan */
    public readonly array $entries;

    /**
     * PRIVÉ, et c'est le cœur du design : hors des fabriques, personne ne peut
     * fabriquer un rapport dont le périmètre n'a pas été confronté au plan.
     *
     * @param  list<NodeReconciliation>  $entries
     */
    private function __construct(FileBackendName $backend, string $scope, array $entries)
    {
        $this->backend = $backend;
        $this->scope = $scope;
        $this->entries = array_values($entries);
    }

    /**
     * **Un rapport ne se sérialise pas nativement, et c'est délibéré.**
     *
     * Le constructeur privé rend un rapport incomplet inconstructible — mais
     * `unserialize()` ne passe par aucun constructeur : il restaure les propriétés
     * directement, `readonly` comprises. Un rapport « tout vert » qui omettrait le
     * nœud privé des enseignants redeviendrait donc fabricable par ce chemin, et
     * l'affirmation la plus forte de cette story serait fausse hors du chemin
     * heureux.
     *
     * On ferme la porte plutôt que de la documenter : une garantie qui ne vit que
     * dans un commentaire est la signature de défaut que cet epic rencontre à
     * chaque story. L'échec est bruyant et arrive au point exact du mésusage.
     *
     * **Le format de transport est {@see toArray()}.** Un rapport se reconstruit en
     * repassant par une fabrique, avec son plan — c'est-à-dire en refaisant
     * confronter le périmètre. C'est précisément ce qu'un transport ne doit pas
     * pouvoir contourner, et la réconciliation asynchrone annoncée pour la suite
     * (des files de traitement sérialisent leur charge utile) est exactement le cas
     * qui l'aurait contourné en silence.
     *
     * @throws InvalidBackendReportException toujours
     */
    public function __serialize(): array
    {
        throw InvalidBackendReportException::make(
            'un rapport de réconciliation ne se sérialise pas nativement : sa complétude ne survivrait pas '
            . 'au voyage (la désérialisation ne passe par aucune fabrique). Transporte-le en tableau '
            . '(toArray()) et reconstruis-le depuis son plan.'
        );
    }

    /**
     * Symétrique de {@see __serialize()} — refuse aussi la reconstruction directe,
     * pour le cas d'une charge utile forgée ailleurs.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidBackendReportException toujours
     */
    public function __unserialize(array $data): void
    {
        throw InvalidBackendReportException::make(
            'un rapport de réconciliation ne se désérialise pas nativement : son périmètre n\'aurait été '
            . 'confronté à aucun plan.'
        );
    }

    /**
     * Rapport couvrant TOUS les nœuds du plan — `provision` / `deprovision`.
     *
     * @param  list<NodeReconciliation>  $entries
     *
     * @throws InvalidBackendReportException si le périmètre ne correspond pas
     */
    public static function covering(FileBackendName $backend, FilePlan $plan, array $entries): self
    {
        return new self(
            $backend,
            self::SCOPE_PLAN,
            self::assertCovers($backend, $plan->nodePaths(), $entries, 'les nœuds du plan'),
        );
    }

    /**
     * Rapport couvrant les seuls nœuds À PLAFOND — `quota`.
     *
     * Un plan sans plafond donne un rapport VIDE et parfaitement valide : il n'y
     * avait rien à plafonner. Ce n'est ni un échec, ni un déclin, ni un oubli.
     *
     * @param  list<NodeReconciliation>  $entries
     *
     * @throws InvalidBackendReportException si le périmètre ne correspond pas
     */
    public static function coveringCapped(FileBackendName $backend, FilePlan $plan, array $entries): self
    {
        return new self(
            $backend,
            self::SCOPE_CAPPED,
            self::assertCovers($backend, $plan->cappedNodePaths(), $entries, 'les nœuds à plafond du plan'),
        );
    }

    /**
     * Le contrôle de complétude : mêmes chemins, une seule fois chacun.
     *
     * Les entrées sont RÉORDONNÉES sur l'ordre canonique du plan (la racine
     * d'abord) : deux backends qui rapportent dans un ordre différent produisent
     * la même sérialisation, condition sans laquelle la comparaison d'un rapport à
     * l'autre serait bruitée par un détail d'implémentation.
     *
     * @param  list<string>  $expectedPaths
     * @param  list<NodeReconciliation>  $entries
     * @return list<NodeReconciliation>
     *
     * @throws InvalidBackendReportException
     */
    private static function assertCovers(
        FileBackendName $backend,
        array $expectedPaths,
        array $entries,
        string $scopeLabel,
    ): array {
        $byPath = [];
        foreach ($entries as $entry) {
            if (! $entry instanceof NodeReconciliation) {
                throw InvalidBackendReportException::make(sprintf(
                    'le backend « %s » a rapporté une entrée qui n\'est pas une réconciliation de nœud.',
                    $backend->value,
                ));
            }
            if (array_key_exists($entry->path, $byPath)) {
                throw InvalidBackendReportException::make(sprintf(
                    'le backend « %s » rapporte DEUX FOIS le nœud « %s » : un nœud a un seul état, '
                    . 'sinon le rapport laisse choisir lequel croire.',
                    $backend->value,
                    $entry->path,
                ));
            }
            $byPath[$entry->path] = $entry;
        }

        $ordered = [];
        $missing = [];
        foreach ($expectedPaths as $path) {
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
                'le rapport du backend « %s » ne couvre pas %s : %s%s%s. '
                . 'Un rapport incomplet se lit « tout va bien » sur le nœud dont personne n\'a parlé — '
                . 'c\'est exactement la fuite silencieuse mesurée en ouverture d\'epic.',
                $backend->value,
                $scopeLabel,
                $missing !== [] ? 'nœuds non couverts [' . implode(', ', $missing) . ']' : '',
                ($missing !== [] && $unexpected !== []) ? ' ; ' : '',
                $unexpected !== [] ? 'nœuds hors plan [' . implode(', ', $unexpected) . ']' : '',
            ));
        }

        return $ordered;
    }

    // =========================================================================
    // Vues DÉRIVÉES — jamais des faits primaires
    // =========================================================================

    /** @return list<NodeReconciliation> */
    public function withOutcome(FileBackendOutcome $outcome): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (NodeReconciliation $e): bool => $e->outcome === $outcome,
        ));
    }

    /** Nœuds dans l'état voulu à l'issue de ce passage (`conforme` ou `applique`). */
    public function converged(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (NodeReconciliation $e): bool => $e->outcome->isConverged(),
        ));
    }

    /** @return list<NodeReconciliation> */
    public function pending(): array
    {
        return $this->withOutcome(FileBackendOutcome::EnAttente);
    }

    /** @return list<NodeReconciliation> */
    public function failures(): array
    {
        return $this->withOutcome(FileBackendOutcome::Echec);
    }

    /** Déclins PERMANENTS : le modèle du backend n'a pas le concept. */
    public function inexpressible(): array
    {
        return $this->withOutcome(FileBackendOutcome::NonExprimable);
    }

    /** Déclins TEMPORAIRES : le mécanisme existe, SE5 ne le pilote pas. */
    public function unimplemented(): array
    {
        return $this->withOutcome(FileBackendOutcome::NonImplemente);
    }

    /** Les trois façons de ne rien faire, réunies — l'UI les rend séparément. */
    public function declines(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (NodeReconciliation $e): bool => $e->outcome->isDecline(),
        ));
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function for(string $path): ?NodeReconciliation
    {
        foreach ($this->entries as $entry) {
            if ($entry->path === $path) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array{backend:string,scope:string,nodes:list<array{path:string,outcome:string,detail:string|null}>}
     */
    public function toArray(): array
    {
        return [
            'backend' => $this->backend->value,
            'scope' => $this->scope,
            'nodes' => array_map(static fn (NodeReconciliation $e): array => $e->toArray(), $this->entries),
        ];
    }
}
