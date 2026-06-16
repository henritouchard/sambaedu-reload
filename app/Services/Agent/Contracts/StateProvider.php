<?php

declare(strict_types=1);

namespace App\Services\Agent\Contracts;

use App\Enums\ResourceSemantics;
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
 * Story 27.8 : le mécanisme `mode` strict/default est SUPPRIMÉ (STRICT
 * inconditionnel) — l'interface ne déclare plus `mode()`, l'item du contrat
 * n'a plus de clé `mode` (4 clés : `type`, `semantics`, `payload`, `hash`).
 */
interface StateProvider
{
    /** Identifiant figé du type (contrat §7 : snake_case, jamais renommé — NFR12). */
    public function type(): string;

    /** Sémantique de combinaison : le compilateur l'applique, jamais le provider. */
    public function semantics(): ResourceSemantics;

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
