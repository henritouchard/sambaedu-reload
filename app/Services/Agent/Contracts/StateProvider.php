<?php

declare(strict_types=1);

namespace App\Services\Agent\Contracts;

use App\Enums\ResourceSemantics;
use App\Enums\StateMode;
use App\Enums\StateScope;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Support\Collection;

/**
 * Un type de ressource du contrat `se5.desired-state/v1` (Story 23.4 — D1).
 *
 * Chaque provider est une **projection en lecture seule** des tables métier
 * existantes vers des candidats d'état bruts. Ajouter un type de ressource =
 * écrire un provider + l'enregistrer dans `AgentServiceProvider` — **zéro
 * modification** du `StateCompiler` ni du contrat (AC1, checklist Epic 27
 * dans `docs/agent/state-providers.md`).
 *
 * Règles NON négociables (architecture, Enforcement Guidelines) :
 *  - lecture seule sur les tables métier — aucun write, aucun appel AD/APCu ;
 *  - le provider étiquette ses candidats par maille et C'EST TOUT : trier,
 *    filtrer par maille ou appliquer la précédence est une violation de D2
 *    (la précédence vit dans le StateCompiler SEUL) — bloquant en review.
 *
 * `mode()` : extension 23.4 de l'interface architecture (décision n° 6) —
 * l'item du contrat porte `mode` et AC1 interdit toute table type→mode dans
 * le compilateur, donc le provider déclare sa constante, comme `semantics()`
 * et `scope()`. Depuis 27.1 (décision n° 2), ce mode est le **défaut du type**
 * : le toggle strict/default vit désormais PAR RÈGLE (`StateCandidate::$mode`),
 * et `mode()` ne s'applique qu'aux candidats qui ne déclarent pas le leur
 * (`null`). Le compilateur agrège ensuite le mode par type (tous default →
 * default, sinon strict).
 */
interface StateProvider
{
    /** Identifiant figé du type (contrat §7 : snake_case, jamais renommé — NFR12). */
    public function type(): string;

    /** Sémantique de combinaison : le compilateur l'applique, jamais le provider. */
    public function semantics(): ResourceSemantics;

    /** Mode d'application PAR DÉFAUT du type (27.1 : le toggle par règle vit sur StateCandidate::$mode). */
    public function mode(): StateMode;

    /** Portée d'enveloppe vers laquelle le compilateur route les items. */
    public function scope(): StateScope;

    /**
     * Candidats bruts applicables au contexte, étiquetés par maille — sans
     * tri, sans précédence, sans déduplication (D2 = compilateur).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection;
}
