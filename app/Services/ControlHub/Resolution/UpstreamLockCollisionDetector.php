<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Resolution;

use App\Models\Workstation;
use App\Services\Agent\Contracts\KeyedExclusiveProvider;
use App\Services\Agent\Contracts\StateProvider;
use Illuminate\Support\Facades\DB;

/**
 * Détecteur de **collision verrou/verrou insoluble** à l'assignation
 * (PRÉVENTION prédictive). Service de PURE LECTURE : il PRÉDIT, à partir du
 * socle de résolution, qu'une assignation de label / un rattachement de
 * poste introduirait une contradiction irréconciliable — deux items amont
 * `locked` imposant des valeurs DIFFÉRENTES sur la MÊME propriété exclusive
 * (`exclusiveKey()`) d'un même poste. Il n'écrit RIEN, n'émet AUCUN candidat,
 * n'introduit AUCUNE précédence — celle-ci reste confinée au `StateCompiler`.
 *
 * **Frontière** : le compilateur OBSERVE la collision au runtime (warning
 * `agent.state.conflict` + tiebreak déterministe, pas d'état vide) ; ce détecteur
 * la PRÉVIENT en fermant la porte d'entrée à l'assignation. Les deux coexistent.
 *
 * **Réutilisation STRICTE** :
 *  - les candidats `locked` viennent de {@see UpstreamContractSource::lockedLabelCandidates()}
 *    (items `target_type = label`, maille `Upstream`, payload via les adaptateurs) ;
 *  - l'identité de propriété vient de {@see KeyedExclusiveProvider::exclusiveKey()}
 *    des providers EXISTANTS — aucune dérivation de clé n'est réinventée ici ;
 *  - les providers `aggregate` (non `KeyedExclusiveProvider`, ex. `shortcuts`) ont
 *    une sémantique d'UNION : pas d'exclusiveKey unique ⇒ jamais de collision
 *    insoluble ⇒ exclus du détecteur.
 *
 * **Court-circuit** : {@see self::hasLockedLabelItems()} permet à l'appelant
 * de NE PAS charger la population de postes quand il n'y a aucun item label
 * `locked` (ou aucun contrat actif) — hot-path d'assignation strictement intact.
 *
 * **Filtre « introduite, pas pré-existante »** : une collision n'est
 * signalée que si AU MOINS un de ses deux côtés provient d'un label GAGNÉ par
 * l'opération. Une collision pré-existante non aggravée (deux labels déjà cumulés)
 * reste du ressort du filet runtime — ne paralyse pas l'admin dessus.
 *
 * ⚠️ GARDE-FOU R3 : aucun « central ». Vocabulaire « amont » / `Upstream` /
 *    `label`.
 */
final class UpstreamLockCollisionDetector
{
    /**
     * Providers EXCLUSIFS indexés par `type()` — fournissent `exclusiveKey()`.
     *
     * @var array<string, KeyedExclusiveProvider>
     */
    private array $keyedProviders = [];

    /**
     * Index mémoïsé par-requête : `label → groupKey → exclusiveKey → entrées`.
     *
     * @var array<string, array<string, array<string, list<array{sourceId:int, value:mixed}>>>>|null
     */
    private ?array $index = null;

    /**
     * @param  iterable<StateProvider>  $exclusiveProviders  providers déjà câblés ;
     *                                  seuls les `KeyedExclusiveProvider` sont
     *                                  retenus (les aggregate sont ignorés)
     */
    public function __construct(
        private readonly UpstreamContractSource $source,
        iterable $exclusiveProviders,
    ) {
        foreach ($exclusiveProviders as $provider) {
            if ($provider instanceof KeyedExclusiveProvider && $provider instanceof StateProvider) {
                // Indexé par type() : `exclusiveKey()` ne dépend que du payload,
                // pas de la portée — un provider par type suffit pour keyer.
                $this->keyedProviders[$provider->type()] = $provider;
            }
        }
    }

    /**
     * Y a-t-il au moins un item `label` VERROUILLÉ dans le contrat actif ? Sinon,
     * AUCUNE collision n'est possible ⇒ l'appelant court-circuite AVANT tout
     * eager-load de population. Délègue au court-circuit mémoïsé de la
     * source (≤ 1 résolution de contrat, jamais de requête parc).
     */
    public function hasLockedLabelItems(): bool
    {
        return $this->source->lockedLabelCandidates() !== [];
    }

    /**
     * Collisions introduites par l'assignation d'UN nouveau label à une population
     * de postes (surface « assigner un label »).
     *
     * @param  iterable<Workstation>  $workstations  population touchée
     * @param  callable(Workstation):list<string>  $existingLabelsOf  labels déjà
     *                                 portés par le poste HORS le label assigné
     * @return list<UpstreamLockCollision>
     */
    public function collisionsFromLabelGainedBy(iterable $workstations, string $newLabel, callable $existingLabelsOf): array
    {
        return $this->collisionsFromLabelsGainedBy($workstations, [$newLabel], $existingLabelsOf);
    }

    /**
     * Collisions introduites par le GAIN d'un ensemble de labels (surface « lier un
     * parc » : un poste rattaché à des parcs cibles gagne leurs labels).
     *
     * Modèle ADDITIF : `post = pre ∪ gainedLabels`, `pre = $existingLabelsOf`. Le
     * filtre « introduite, pas pré-existante » est appliqué sur `gained =
     * post \ pre` : un label déjà porté n'est donc JAMAIS compté comme gagné.
     * Délègue au cœur générique
     * {@see self::collisionsFromFinalState()}.
     *
     * @param  iterable<Workstation>  $workstations
     * @param  list<string>  $gainedLabels
     * @param  callable(Workstation):list<string>  $existingLabelsOf
     * @return list<UpstreamLockCollision>
     */
    public function collisionsFromLabelsGainedBy(iterable $workstations, array $gainedLabels, callable $existingLabelsOf): array
    {
        return $this->collisionsFromFinalState(
            $workstations,
            $existingLabelsOf,
            static fn (Workstation $workstation): array => array_values(array_unique(
                [...$existingLabelsOf($workstation), ...$gainedLabels],
            )),
        );
    }

    /**
     * Cœur générique de détection en MODÈLE pré-set / post-set par poste.
     * Raisonne sur l'ÉTAT FINAL d'appartenance plutôt que
     * sur une sémantique purement additive — indispensable pour les surfaces de
     * REMPLACEMENT (`groups()->sync()` de `setMachineGroups`) et de SWAP
     * (`assignMachineToPhysicalRoom`) où des appartenances disparaissent et leurs
     * labels ne doivent PAS peser dans la détection, sinon la détection produit
     * des collisions fantômes et refuse à tort l'opération.
     *
     * Pour chaque poste : `pre(ws)` = labels portés AVANT l'op, `post(ws)` = labels
     * portés APRÈS ; `gained(ws) = post \ pre`. On détecte, sur `post`, par
     * `groupKey`+`exclusiveKey`, ≥ 2 valeurs `locked` DISTINCTES dont AU MOINS une
     * provient de `gained` (une collision purement pré-existante,
     * non aggravée, reste du ressort du filet runtime). Les labels RETIRÉS
     * (`pre \ post`) n'apparaissent pas dans `post` et sont donc naturellement
     * ignorés.
     *
     * @param  iterable<Workstation>  $workstations
     * @param  callable(Workstation):list<string>  $preLabelsOf   labels portés AVANT l'op
     * @param  callable(Workstation):list<string>  $postLabelsOf  labels portés APRÈS l'op
     * @return list<UpstreamLockCollision>
     */
    public function collisionsFromFinalState(iterable $workstations, callable $preLabelsOf, callable $postLabelsOf): array
    {
        $index = $this->index();
        if ($index === []) {
            return []; // court-circuit : aucun item label locked.
        }

        /** @var array<string, array{exclusiveKey:string, providerType:string, scope:string, sideA:array{label:string,sourceId:int,value:mixed}, sideB:array{label:string,sourceId:int,value:mixed}, workstationIds:array<int,true>}> $collisions */
        $collisions = [];

        foreach ($workstations as $workstation) {
            $preLabels = array_values(array_unique($preLabelsOf($workstation)));
            $labels = array_values(array_unique($postLabelsOf($workstation)));
            // gained = post \ pre : un label déjà porté n'est jamais « introduit ».
            $gainedSet = array_fill_keys(array_values(array_diff($labels, $preLabels)), true);

            // groupKey → exclusiveKey → list<{label, sourceId, value}> cumulés sur
            // tous les labels que porterait le poste après l'opération.
            $buckets = [];
            foreach ($labels as $label) {
                foreach ($index[$label] ?? [] as $groupKey => $byExclusiveKey) {
                    foreach ($byExclusiveKey as $exclusiveKey => $entries) {
                        foreach ($entries as $entry) {
                            $buckets[$groupKey][$exclusiveKey][] = [
                                'label' => $label,
                                'sourceId' => $entry['sourceId'],
                                'value' => $entry['value'],
                            ];
                        }
                    }
                }
            }

            foreach ($buckets as $groupKey => $byExclusiveKey) {
                foreach ($byExclusiveKey as $exclusiveKey => $entries) {
                    // Valeurs DISTINCTES (normalisées) ? Valeurs concordantes ⇒
                    // rien à trancher, pas de collision.
                    $distinct = [];
                    foreach ($entries as $entry) {
                        $distinct[$this->valueKey($entry['value'])] = true;
                    }
                    if (count($distinct) < 2) {
                        continue;
                    }

                    // Au moins un côté provient d'un label GAGNÉ, sinon c'est une
                    // collision pré-existante, laissée au filet runtime.
                    $introduced = false;
                    foreach ($entries as $entry) {
                        if (isset($gainedSet[$entry['label']])) {
                            $introduced = true;
                            break;
                        }
                    }
                    if (! $introduced) {
                        continue;
                    }

                    [$sideA, $sideB] = $this->pickContradictingSides($entries);

                    $key = $exclusiveKey.'|'.$sideA['sourceId'].'|'.$sideB['sourceId'];
                    if (! isset($collisions[$key])) {
                        [$providerType, $scope] = array_pad(explode('|', (string) $groupKey, 2), 2, '');
                        $collisions[$key] = [
                            'exclusiveKey' => (string) $exclusiveKey,
                            'providerType' => $providerType,
                            'scope' => $scope,
                            'sideA' => $sideA,
                            'sideB' => $sideB,
                            'workstationIds' => [],
                        ];
                    }
                    $collisions[$key]['workstationIds'][(int) $workstation->getKey()] = true;
                }
            }
        }

        // Déterminisme : clés (donc collisions) ordonnées, postes triés.
        ksort($collisions);

        $result = [];
        foreach ($collisions as $collision) {
            $ids = array_keys($collision['workstationIds']);
            sort($ids);
            $result[] = new UpstreamLockCollision(
                exclusiveKey: $collision['exclusiveKey'],
                providerType: $collision['providerType'],
                scope: $collision['scope'],
                labelA: $collision['sideA']['label'],
                sourceIdA: $collision['sideA']['sourceId'],
                valueA: $collision['sideA']['value'],
                labelB: $collision['sideB']['label'],
                sourceIdB: $collision['sideB']['sourceId'],
                valueB: $collision['sideB']['value'],
                workstationIds: array_values($ids),
            );
        }

        return $result;
    }

    /**
     * Labels (controlhub_label) portés par un ensemble de postes via leurs parcs,
     * EN EXCLUANT un ensemble de groupes (le slot réécrit, ou les parcs cibles).
     * Une seule requête (anti-N+1). Listes triées + dédupliquées (déterminisme).
     *
     * @param  list<int>  $workstationIds
     * @param  list<int>  $excludeGroupIds
     * @return array<int, list<string>>  `workstationId → labels triés`
     */
    public function carriedLabelsExcludingGroups(array $workstationIds, array $excludeGroupIds): array
    {
        if ($workstationIds === []) {
            return [];
        }

        $query = DB::table('workstation_group_workstation as pivot')
            ->join('workstation_groups as wg', 'wg.id', '=', 'pivot.workstation_group_id')
            ->whereIn('pivot.workstation_id', $workstationIds)
            ->whereNotNull('wg.controlhub_label')
            ->where('wg.controlhub_label', '!=', '');

        if ($excludeGroupIds !== []) {
            $query->whereNotIn('pivot.workstation_group_id', $excludeGroupIds);
        }

        $rows = $query
            ->select('pivot.workstation_id as workstation_id', 'wg.controlhub_label as label')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->workstation_id][] = (string) $row->label;
        }
        foreach ($map as $id => $labels) {
            $labels = array_values(array_unique($labels));
            sort($labels);
            $map[$id] = $labels;
        }

        return $map;
    }

    /**
     * Construit (mémoïsé) l'index `label → groupKey → exclusiveKey → entrées` à
     * partir des candidats `locked` du socle, keyés par les providers
     * exclusifs existants. Les groupKey sans provider exclusif (aggregate) sont
     * ignorés (jamais de collision de clé).
     *
     * @return array<string, array<string, array<string, list<array{sourceId:int, value:mixed}>>>>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];
        foreach ($this->source->lockedLabelCandidates() as $label => $byGroupKey) {
            foreach ($byGroupKey as $groupKey => $candidates) {
                $providerType = explode('|', (string) $groupKey, 2)[0];
                $provider = $this->keyedProviders[$providerType] ?? null;
                if ($provider === null) {
                    continue; // aggregate / non exclusif : pas de clé d'exclusivité.
                }
                foreach ($candidates as $candidate) {
                    $exclusiveKey = $provider->exclusiveKey($candidate->payload);
                    $index[$label][$groupKey][$exclusiveKey][] = [
                        'sourceId' => $candidate->sourceId,
                        // Valeur NORMALISÉE par l'adaptateur (toPayload()['value']) —
                        // jamais la chaîne brute (REG_DWORD 1 == "1", §4.1).
                        'value' => $candidate->payload['value'] ?? null,
                    ];
                }
            }
        }

        return $this->index = $index;
    }

    /**
     * Choisit deux côtés CONTRADICTOIRES (valeurs distinctes) de façon déterministe
     * (tri par valeur normalisée, label, sourceId) puis ré-ordonne par `sourceId`
     * croissant (côté A = plus petit `sourceId`).
     *
     * @param  list<array{label:string,sourceId:int,value:mixed}>  $entries
     * @return array{0:array{label:string,sourceId:int,value:mixed},1:array{label:string,sourceId:int,value:mixed}}
     */
    private function pickContradictingSides(array $entries): array
    {
        usort($entries, fn (array $a, array $b): int => [$this->valueKey($a['value']), $a['label'], $a['sourceId']]
            <=> [$this->valueKey($b['value']), $b['label'], $b['sourceId']]);

        $first = $entries[0];
        $second = $first;
        foreach ($entries as $entry) {
            if ($this->valueKey($entry['value']) !== $this->valueKey($first['value'])) {
                $second = $entry;
                break;
            }
        }

        return $first['sourceId'] <= $second['sourceId'] ? [$first, $second] : [$second, $first];
    }

    /**
     * Clé de comparaison d'une valeur NORMALISÉE. Les scalaires sont comparés par
     * leur représentation chaîne (REG_DWORD `1` == REG_SZ `"1"`) ;
     * les listes (MULTI_SZ) par leur encodage JSON déterministe.
     */
    private function valueKey(mixed $value): string
    {
        if (is_array($value)) {
            return 'a:'.json_encode($value);
        }

        return 'v:'.(string) $value;
    }
}
