<?php

declare(strict_types=1);

namespace App\Services\Agent;

/**
 * Algorithme de hash **unique et déterministe** du contrat agent (FR7).
 *
 * SHA-256 sur une forme JSON **canonicalisée** : clés des tableaux associatifs
 * triées alphabétiquement et **récursivement**, UTF-8, sans espaces. Deux
 * compilations du même état à des instants différents produisent le même hash
 * (le champ volatil `generated_at` est exclu avant le hash).
 *
 * Source unique (AC2) : ce hash sert l'ETag de `GET /api/v1/agent/state`
 * (story 23.5) ET la comparaison des rapports (`POST /report`, story 24.1).
 * **Jamais** de `md5`/`hash('sha256', …)` ad hoc ailleurs dans le canal agent.
 *
 * Le hash est **opaque** : l'agent compare des chaînes, il ne le recalcule
 * jamais. Classe pure — aucune dépendance base / HTTP / AD (critère Keycloak).
 */
final class StateHasher
{
    /**
     * Champs volatils exclus du hash d'état (variables d'une compilation à
     * l'autre sans changer le sens de la cible). Single point of truth : tout
     * nouveau champ volatil s'ajoute ici.
     *
     * `ttl_seconds` ajouté par la Story 43.3 (AC3, D6) : le TTL dépend
     * désormais du CONTEXTE (bascule sensible ou non — {@see AgentTtlResolver}),
     * mais reste une cadence de poll CONSEILLÉE, pas une donnée sémantique de
     * la cible — un changement de TTL seul (sans changement d'items) ne doit
     * pas invalider l'ETag. Miroir Go OBLIGATOIRE :
     * `agent/shared/hasher.go::volatileStateKeys` doit porter EXACTEMENT la
     * même liste (piège n°2, contrat gelé des deux côtés).
     *
     * @var list<string>
     */
    private const VOLATILE_STATE_KEYS = ['generated_at', 'ttl_seconds'];

    /**
     * Hash d'un état cible complet (enveloppe). `generated_at` et
     * `ttl_seconds` sont exclus (Story 43.3), de sorte que seuls des
     * changements sémantiques modifient le hash.
     *
     * @param  array<string,mixed>  $state
     */
    public function hashState(array $state): string
    {
        foreach (self::VOLATILE_STATE_KEYS as $key) {
            unset($state[$key]);
        }

        return $this->sha256($this->canonicalize($state));
    }

    /**
     * Hash du contenu *définissant* d'un item. Sa propre clé `hash` est exclue
     * (sinon dépendance circulaire) : l'item hashé ne contient que ce qui le
     * définit (`type`, `semantics`, `payload`).
     *
     * @param  array<string,mixed>  $item
     */
    public function hashItem(array $item): string
    {
        unset($item['hash']);

        return $this->sha256($this->canonicalize($item));
    }

    /**
     * Forme canonique JSON : tri récursif des clés des tableaux associatifs,
     * encodage compact UTF-8 sans échappement superflu et sans espaces.
     *
     * ⚠️ `json_encode` PHP **ne trie pas** les clés → le tri est fait à la main
     * en amont ({@see sortRecursive}).
     */
    private function canonicalize(mixed $value): string
    {
        $sorted = $this->sortRecursive($value);

        return json_encode(
            $sorted,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Trie récursivement les clés des tableaux associatifs. Les **listes**
     * (tableaux à clés séquentielles) ne sont PAS triées : l'ordre des items
     * est significatif et fixé par le serveur — on se contente de descendre
     * dans leurs éléments.
     *
     * Tri **lexicographique octet-par-octet** (`SORT_STRING`) : le défaut
     * `SORT_REGULAR` comparerait les clés numériques (`"9"` < `"10"`)
     * différemment d'un tri par chaîne, divergeant de toute implémentation
     * tierce du canonical form. Figé par le contrat (docs/agent/contract-v1.md §4).
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => $this->sortRecursive($v), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $v): mixed => $this->sortRecursive($v), $value);
    }

    private function sha256(string $canonical): string
    {
        return hash('sha256', $canonical);
    }
}
