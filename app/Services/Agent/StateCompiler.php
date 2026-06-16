<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Services\Agent\Contracts\StateProvider;
use Illuminate\Support\Facades\Log;

/**
 * Compilation de l'état cible d'un (poste, user) — Story 23.4, la fonction
 * que le legacy n'a jamais matérialisée (Vérité #1 du brainstorming).
 *
 * **SEUL porteur de D2** : la précédence par spécificité, l'union aggregate
 * et l'arbitrage des conflits intra-maille vivent ici et nulle part ailleurs.
 * Un provider qui trie/filtre par maille est une violation bloquante
 * (architecture, Enforcement Guidelines).
 *
 * Chaîne de spécificité complète (décision n° 1, extension iso-legacy de D2
 * qui ne figeait que la partie machine) :
 * `user > groupes user > poste > WG physique > WG logique > broadcast`.
 *
 * **Déterminisme** = exigence de contrat : deux compilations du même état à
 * des instants différents produisent le même `StateHasher::hashState()`
 * (c'est l'ETag de `GET /state`, story 23.5). D'où : ordre de sortie figé
 * (décision n° 9 — types asc par portée, `sourceId` asc intra-type aggregate),
 * tiebreak `id` desc sur les conflits, seul `generated_at` est volatil.
 *
 * Lecture + calcul pur : ce service n'écrit RIEN (pas même dans `agent_*`).
 */
final class StateCompiler
{
    /**
     * @param  list<StateProvider>  $providers  registry injecté par `AgentServiceProvider`
     */
    public function __construct(
        private readonly StateHasher $hasher,
        private readonly array $providers,
    ) {}

    /**
     * Enveloppe v1 complète : `schema`, `generated_at`, `ttl_seconds` et les
     * trois portées **toujours présentes**, même vides (contrat §3).
     *
     * @return array<string,mixed>
     */
    public function compile(TargetContext $ctx): array
    {
        $scopes = array_fill_keys(StateContract::scopes(), []);

        foreach ($this->sortedProviders() as $provider) {
            $items = $this->compileProvider($provider, $ctx);
            if ($items === []) {
                continue; // aucune règle = type ABSENT (contrat §8), jamais d'item vide.
            }
            $scopes[$provider->scope()->value] = array_merge(
                $scopes[$provider->scope()->value],
                $items,
            );
        }

        $state = [
            'schema' => StateContract::SCHEMA,
            'generated_at' => now()->utc()->toIso8601String(),
            // Le défaut de config() ne couvre que l'ABSENCE de clé : une clé
            // présente mais null (env vide) casterait en 0 sans le `??`
            // (defer review 23.4). Plancher iso config/agent.php.
            'ttl_seconds' => max(1, (int) (config('agent.ttl_seconds') ?? 3600)),
            // Mode debug du poste (drapeau `workstations.debug`) : pilote le
            // comportement console du compagnon agent. Champ d'enveloppe (pas
            // un item de portée) — opérationnel, non convergent. Inclus dans
            // le hash (donc l'ETag) : un toggle franchit le cache 304.
            'debug' => (bool) $ctx->workstation->debug,
            ...$scopes,
        ];

        Log::channel('agent')->debug('[StateCompiler] agent.state.compiled', [
            'action_type' => 'agent.state.compiled',
            'workstation_id' => $ctx->workstation->id,
            // Comptes par portée uniquement — jamais les payloads complets.
            'items' => array_map('count', $scopes),
        ]);

        return $state;
    }

    /**
     * Hash d'état (= ETag de 23.5) du compilé — délégué au hasher du contrat,
     * jamais de hash ad hoc.
     *
     * @param  array<string,mixed>  $state
     */
    public function hashState(array $state): string
    {
        return $this->hasher->hashState($state);
    }

    /**
     * Ordre de traitement déterministe : types asc (`SORT_STRING`, iso
     * canonicalisation du hasher). L'ordre d'enregistrement dans le registry
     * ne doit jamais influer sur le wire format.
     *
     * @return list<StateProvider>
     */
    private function sortedProviders(): array
    {
        $providers = $this->providers;
        usort(
            $providers,
            static fn (StateProvider $a, StateProvider $b): int => strcmp($a->type(), $b->type()),
        );

        return $providers;
    }

    /**
     * Items finals d'un provider : sélection D2 puis assemblage contrat
     * (`{type, semantics, payload, hash}` — exactement 4 clés). Story 27.8 : la
     * clé `mode` est retirée (STRICT inconditionnel — plus d'agrégation de mode).
     *
     * @return list<array<string,mixed>>
     */
    private function compileProvider(StateProvider $provider, TargetContext $ctx): array
    {
        /** @var list<StateCandidate> $candidates */
        $candidates = $provider->itemsFor($ctx)->values()->all();
        if ($candidates === []) {
            return [];
        }

        $selected = $provider->semantics() === ResourceSemantics::Exclusive
            ? $this->selectExclusive($provider, $ctx, $candidates)
            : $this->selectAggregate($candidates);

        return array_map(
            function (StateCandidate $candidate) use ($provider): array {
                $item = [
                    'type' => $provider->type(),
                    'semantics' => $provider->semantics()->value,
                    'payload' => $candidate->payload,
                ];
                $item['hash'] = $this->hasher->hashItem($item);

                return $item;
            },
            $selected,
        );
    }

    /**
     * Type aggregate = **union** des candidats de toutes les mailles
     * applicables, ordre stable par `sourceId` asc (décision n° 9 — pour
     * `overlay` : `id` asc des signaux), **dédoublonnée par contenu** (Story
     * 27.1, décision n° 4).
     *
     * Dédup : deux règles produisant le MÊME item (même payload) sur deux
     * mailles différentes — typiquement un raccourci assigné à la fois au parc
     * ET au poste — ne donnent qu'UN item dans la sortie. Sans cela, le poste
     * recevrait des `.lnk` en double et le hash d'agrégat dépendrait du nombre
     * de mailles recouvrantes (déterminisme cassé). On garde le PREMIER par
     * `sourceId` asc (ordre déjà trié) → la sortie reste stable. La clé de
     * contenu est le payload canonicalisé.
     *
     * Overlay (1 item/signal, payloads naturellement distincts dont l'`id` du
     * signal via `kind`/`text`) n'est pas affecté : aucun doublon de contenu à
     * fusionner (non-régression vérifiée en test).
     *
     * @param  list<StateCandidate>  $candidates
     * @return list<StateCandidate>
     */
    private function selectAggregate(array $candidates): array
    {
        usort(
            $candidates,
            static fn (StateCandidate $a, StateCandidate $b): int => $a->sourceId <=> $b->sourceId,
        );

        $unique = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $key = $this->contentKey($candidate->payload);
            if (isset($seen[$key])) {
                continue; // doublon de contenu (autre maille) — déjà retenu (sourceId plus petit).
            }
            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * Clé de contenu stable d'un payload (dédup aggregate, décision n° 4) :
     * la forme canonique JSON du hasher (tri récursif des clés) — deux payloads
     * identiques au tri des clés près produisent la même clé. Réutilise la
     * canonicalisation du contrat : aucune autre forme de hash ad hoc.
     *
     * @param  array<string,mixed>  $payload
     */
    private function contentKey(array $payload): string
    {
        return $this->hasher->hashItem(['payload' => $payload]);
    }

    /**
     * Type exclusif = la maille la plus spécifique gagne ; conflit au sein de
     * cette maille → la règle la plus récente gagne (`updated_at` desc puis
     * `id` desc) + warning `agent.state.conflict` (décision n° 2, AC3).
     *
     * Le warning n'est émis que pour la maille gagnante : un conflit dans une
     * maille battue n'arbitre rien (aucune incidence sur l'état servi).
     *
     * @param  list<StateCandidate>  $candidates  non vide
     * @return list<StateCandidate>
     */
    private function selectExclusive(StateProvider $provider, TargetContext $ctx, array $candidates): array
    {
        $bestRank = min(array_map(
            fn (StateCandidate $c): int => $this->specificity($c->maille),
            $candidates,
        ));

        $inMaille = array_values(array_filter(
            $candidates,
            fn (StateCandidate $c): bool => $this->specificity($c->maille) === $bestRank,
        ));

        if (count($inMaille) > 1) {
            // Récence en précision microseconde (TZ-safe via getTimestamp) :
            // getTimestamp() seul tronque à la seconde et ferait gagner le
            // tiebreak `id` à tort entre deux règles modifiées dans la même
            // seconde (review 23.4 — théorique avec timestamps(0), réel pour
            // tout futur provider dont le Carbon porte des microsecondes).
            $recency = static fn (StateCandidate $c): int => $c->updatedAt === null
                ? PHP_INT_MIN
                : $c->updatedAt->getTimestamp() * 1_000_000 + (int) $c->updatedAt->format('u');

            usort($inMaille, static function (StateCandidate $a, StateCandidate $b) use ($recency): int {
                $byRecency = $recency($b) <=> $recency($a);

                return $byRecency !== 0 ? $byRecency : ($b->sourceId <=> $a->sourceId);
            });

            Log::channel('agent')->warning('[StateCompiler] agent.state.conflict', [
                'action_type' => 'agent.state.conflict',
                'workstation_id' => $ctx->workstation->id,
                'type' => $provider->type(),
                'maille' => $inMaille[0]->maille->value,
                'rule_ids' => array_map(static fn (StateCandidate $c): int => $c->sourceId, $inMaille),
            ]);
        }

        return [$inMaille[0]];
    }

    /**
     * Rang de spécificité des mailles (décision n° 1) — 0 = la plus
     * spécifique. Vit ICI et nulle part ailleurs : ni dans l'enum, ni dans
     * les providers (sinon D2 fuit).
     */
    private function specificity(StateMaille $maille): int
    {
        return match ($maille) {
            StateMaille::User => 0,
            StateMaille::UserGroup => 1,
            StateMaille::Workstation => 2,
            StateMaille::PhysicalGroup => 3,
            StateMaille::LogicalGroup => 4,
            StateMaille::Broadcast => 5,
        };
    }
}
