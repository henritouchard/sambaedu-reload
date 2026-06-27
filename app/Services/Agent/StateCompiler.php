<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Services\Agent\Contracts\KeyedExclusiveProvider;
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
 * qui ne figeait que la partie machine) — Story 27.3 (D-Q3) INVERSE
 * `WG logique`/`WG physique` GLOBALEMENT (le parc LOGIQUE est une sélection
 * délibérée de postes transverse aux salles → plus spécifique que la salle) :
 * `user > groupes user > poste > WG logique > WG physique > broadcast`.
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
     * Types de ressource rapportés PAR SESSION (compagnon, via un drop per-SID) —
     * portée `Session` ou `MachineUser`, par opposition aux types MACHINE
     * rapportés in-process par le service SYSTEM (toujours présents chaque cycle,
     * jamais fantômes).
     *
     * Consommé par {@see \App\Services\Agent\Reporting\ReportIngestService} pour le
     * nettoyage level-triggered : une ligne d'état d'un type session ABSENTE du
     * rapport courant = plus aucune session active ne la porte (utilisateur
     * délogué) → purgée (sinon le dernier état d'une session partie traînerait
     * indéfiniment — fantôme « il y a 6 min »). Dérivé du `scope()` des providers
     * (source de vérité), jamais figé : un `registry`/`overlay` à double portée
     * apparaît ici dès qu'un provider session le déclare (sans risque — un type
     * machine toujours rapporté n'est jamais absent, donc jamais purgé).
     *
     * @return list<string>
     */
    public function perSessionReportedTypes(): array
    {
        $types = [];
        foreach ($this->providers as $provider) {
            if ($provider->scope() !== StateScope::Machine) {
                $types[] = $provider->type();
            }
        }

        return array_values(array_unique($types));
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
     * Type exclusif. Deux régimes (Story 27.3) :
     *
     *  - **Défaut (wallpaper)** : UN SEUL item gagnant pour tout le type — la
     *    maille la plus spécifique gagne, conflit intra-maille → la plus récente.
     *  - **Par identité de clé** ({@see KeyedExclusiveProvider}, ex. registry) :
     *    les candidats sont GROUPÉS par `exclusiveKey(payload)` ; la sélection
     *    ci-dessus s'applique INDÉPENDAMMENT à chaque groupe → la maille la plus
     *    spécifique gagne POUR CETTE CLÉ, et les clés distinctes s'accumulent
     *    toutes. Ordre de sortie stable (clés triées) pour le déterminisme de
     *    l'ETag (23.5).
     *
     * D2 reste au compilateur : le provider ne fait QUE déclarer son
     * `exclusiveKey()` ; la précédence/récence est arbitrée ici.
     *
     * @param  list<StateCandidate>  $candidates  non vide
     * @return list<StateCandidate>
     */
    private function selectExclusive(StateProvider $provider, TargetContext $ctx, array $candidates): array
    {
        if (! $provider instanceof KeyedExclusiveProvider) {
            return [$this->resolveExclusiveWinner($provider, $ctx, $candidates)];
        }

        // Groupement par identité de clé (ordre d'apparition mémorisé pour un
        // tri final déterministe). Chaque groupe est arbitré séparément.
        /** @var array<string, list<StateCandidate>> $groups */
        $groups = [];
        foreach ($candidates as $candidate) {
            $key = $provider->exclusiveKey($candidate->payload);
            $groups[$key][] = $candidate;
        }

        // Tri des clés (SORT_STRING, iso canonicalisation) : l'ordre de sortie
        // ne doit jamais dépendre du plan SQL ni de l'ordre des candidats.
        $keys = array_keys($groups);
        sort($keys, SORT_STRING);

        $selected = [];
        foreach ($keys as $key) {
            $selected[] = $this->resolveExclusiveWinner($provider, $ctx, $groups[$key]);
        }

        return $selected;
    }

    /**
     * Élit le vainqueur d'un ENSEMBLE de candidats exclusifs (tout le type, ou
     * un groupe de clé) : la maille la plus spécifique gagne ; arbitrage au sein
     * de cette maille (décision n° 2, AC3) :
     *
     *   - **`physical_group` (hérédité physique, Story 27.x)** : le candidat le
     *     plus PROCHE du poste gagne — profondeur la plus FAIBLE d'abord (l'enfant
     *     bat le parent dans la chaîne `parent_id`). Ce n'est PAS un conflit :
     *     c'est la résolution attendue de l'héritage → aucun warning. La récence
     *     ne départage qu'à profondeur ÉGALE (cas dégénéré, impossible avec
     *     l'invariant 1-salle-max → warning de sécurité comme un vrai conflit).
     *   - **autres mailles (logique incluse)** : groupes PLATS → la règle la plus
     *     récente gagne (`updated_at` desc puis `id` desc) + warning
     *     `agent.state.conflict` (deux groupes incomparables se disputent la clé).
     *
     * Le warning n'est émis que pour la maille gagnante : un conflit dans une
     * maille battue n'arbitre rien (aucune incidence sur l'état servi).
     *
     * @param  list<StateCandidate>  $candidates  non vide
     */
    private function resolveExclusiveWinner(StateProvider $provider, TargetContext $ctx, array $candidates): StateCandidate
    {
        $bestRank = min(array_map(
            fn (StateCandidate $c): int => $this->specificity($c->maille),
            $candidates,
        ));

        $inMaille = array_values(array_filter(
            $candidates,
            fn (StateCandidate $c): bool => $this->specificity($c->maille) === $bestRank,
        ));

        if (count($inMaille) === 1) {
            return $inMaille[0];
        }

        // Récence en précision microseconde (TZ-safe via getTimestamp) :
        // getTimestamp() seul tronque à la seconde et ferait gagner le
        // tiebreak `id` à tort entre deux règles modifiées dans la même
        // seconde (review 23.4 — théorique avec timestamps(0), réel pour
        // tout futur provider dont le Carbon porte des microsecondes).
        $recency = static fn (StateCandidate $c): int => $c->updatedAt === null
            ? PHP_INT_MIN
            : $c->updatedAt->getTimestamp() * 1_000_000 + (int) $c->updatedAt->format('u');

        // Hérédité physique : dans la chaîne PHYSIQUE, le plus PROCHE (profondeur
        // faible) prime. Hors physique, seule la récence départage (groupes plats).
        $isPhysical = $inMaille[0]->maille === StateMaille::PhysicalGroup;

        usort($inMaille, static function (StateCandidate $a, StateCandidate $b) use ($recency, $isPhysical): int {
            if ($isPhysical) {
                $byDepth = ($a->depth ?? PHP_INT_MAX) <=> ($b->depth ?? PHP_INT_MAX);
                if ($byDepth !== 0) {
                    return $byDepth; // profondeur croissante : l'enfant gagne.
                }
            }
            $byRecency = $recency($b) <=> $recency($a);

            return $byRecency !== 0 ? $byRecency : ($b->sourceId <=> $a->sourceId);
        });

        // Conflit RÉEL à signaler : des candidats RESTENT indistinguables après le
        // critère principal de la maille. En physique, l'enfant qui bat le parent
        // par profondeur n'est PAS un conflit (héritage propre) — on ne signale
        // qu'une égalité de profondeur au sommet. Hors physique, tout multi-candidat
        // l'est (la récence a tranché entre groupes incomparables).
        $tiedAtTop = $isPhysical
            ? array_values(array_filter(
                $inMaille,
                static fn (StateCandidate $c): bool => $c->depth === $inMaille[0]->depth,
            ))
            : $inMaille;

        if (count($tiedAtTop) > 1) {
            Log::channel('agent')->warning('[StateCompiler] agent.state.conflict', [
                'action_type' => 'agent.state.conflict',
                'workstation_id' => $ctx->workstation->id,
                'type' => $provider->type(),
                'maille' => $inMaille[0]->maille->value,
                'rule_ids' => array_map(static fn (StateCandidate $c): int => $c->sourceId, $tiedAtTop),
            ]);
        }

        return $inMaille[0];
    }

    /**
     * Rang de spécificité des mailles (décision n° 1) — 0 = la plus
     * spécifique. Vit ICI et nulle part ailleurs : ni dans l'enum, ni dans
     * les providers (sinon D2 fuit).
     *
     * Story 27.3 (D-Q3) — INVERSION GLOBALE `logique > physique` : le parc
     * LOGIQUE (sélection délibérée de postes, transverse aux salles) bat la
     * salle PHYSIQUE. `LogicalGroup` passe au rang 3, `PhysicalGroup` au rang 4.
     * S'applique à TOUS les types exclusifs (registry, wallpaper, et la
     * résolution du défaut `printers` côté provider est alignée séparément).
     *
     * Story 28.3 — TIER AMONT : `Upstream` au rang -1 (STRICTEMENT < `User`,
     * donc plus spécifique que TOUTE maille locale). Conséquence : pour une même
     * clé exclusive, un item imposé par le contrat amont (controlHub) bat le
     * réglage local (FR2) — sans aucune autre logique de sélection (D2 reste
     * ICI seul, exigence epic « réutiliser specificity(), ne pas réinventer »).
     * Choix -1 (et non décalage +1 des autres) : diff minimal, invariant tenu
     * `specificity(Upstream) < specificity(User)`. C'est l'UNIQUE match()
     * exhaustif sur `StateMaille` : tout nouveau case DOIT être ajouté ici
     * (sinon `UnhandledMatchError` en prod — garde-fou testé Story 28.3).
     *
     * Story 29.3 — TIER AMONT PERMISSIF : `UpstreamPermissive` au rang 6
     * (STRICTEMENT > `Broadcast`, donc le MOINS spécifique de toute la chaîne).
     * Conséquence : un item `permissive` est un **plancher** que TOUTE maille
     * locale surcharge (défaut diffusé inclus) ; il ne gagne qu'en l'ABSENCE
     * TOTALE de candidat local (FR4). `locked` reste INBATTABLE (`Upstream` rang
     * -1, inchangé) : seul `permissive` est relaxé. La relaxation vit ICI SEUL
     * (le rang de la maille), JAMAIS via une branche ad hoc dans
     * `resolveExclusiveWinner` (D2 ne fuit pas — exigence Story 29.3).
     */
    private function specificity(StateMaille $maille): int
    {
        return match ($maille) {
            StateMaille::Upstream => -1,
            StateMaille::User => 0,
            StateMaille::UserGroup => 1,
            StateMaille::Workstation => 2,
            StateMaille::LogicalGroup => 3,
            StateMaille::PhysicalGroup => 4,
            StateMaille::Broadcast => 5,
            StateMaille::UpstreamPermissive => 6,
        };
    }
}
