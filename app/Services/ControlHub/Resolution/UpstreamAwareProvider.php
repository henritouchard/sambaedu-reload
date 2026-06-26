<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Resolution;

use App\Enums\ResourceSemantics;
use App\Enums\StateScope;
use App\Services\Agent\Contracts\KeyedExclusiveProvider;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\TargetContext;
use Illuminate\Support\Collection;

/**
 * Story 28.3 — Décorateur AMONT d'un {@see StateProvider}.
 *
 * Enrobe un provider local et fait de son `itemsFor()` la réunion
 * **`candidats_internes ∪ candidats_amont`**, où les candidats amont
 * (étiquetés `StateMaille::Upstream`) viennent de la {@see UpstreamContractSource}
 * pour le couple (type, portée) du provider interne. C'est EXACTEMENT le patron
 * « double source de candidats bruts » déjà éprouvé dans
 * {@see \App\Services\Agent\Providers\AbstractCapabilityStateProvider} (Broadcast
 * ∪ overrides par maille), généralisé à l'amont.
 *
 * **Discipline D2 (CRITIQUE)** : le décorateur n'arbitre RIEN — ni tri, ni
 * filtre, ni dédup, ni précédence par maille. Il n'est qu'une **source
 * supplémentaire de candidats bruts**. La précédence amont > local vit dans
 * `StateCompiler::specificity()` SEUL (maille `Upstream`). Un décorateur qui
 * trierait/élirait par maille = violation bloquante (Enforcement Guidelines).
 *
 * **NFR3 — pass-through strict** : sans contrat actif, la source renvoie `[]` ⇒
 * `concat([])` rend exactement les candidats internes, dans le même ordre ⇒ le
 * compilé est byte-identique au provider non décoré (test révélateur 28.3).
 *
 * **Préservation `KeyedExclusiveProvider`** : si le provider interne implémente
 * ce marqueur (ex. `registry`), le décorateur DOIT l'exposer aussi — sinon
 * `StateCompiler::selectExclusive()` retomberait sur « un seul gagnant pour tout
 * le type » et écraserait les clés distinctes. La fabrique {@see self::wrap()}
 * choisit la variante {@see KeyedUpstreamAwareProvider} dans ce cas.
 *
 * ⚠️ GARDE-FOU R3 : aucun « central ». Vocabulaire « amont » / `Upstream`.
 */
class UpstreamAwareProvider implements StateProvider
{
    public function __construct(
        protected readonly StateProvider $inner,
        protected readonly UpstreamContractSource $source,
    ) {}

    /**
     * Enrobe un provider en préservant le marqueur `KeyedExclusiveProvider` du
     * provider interne (relais de `exclusiveKey()`). Conserve l'ordre et la
     * liste des providers (zéro provider retiré/ajouté côté registry).
     */
    public static function wrap(StateProvider $inner, UpstreamContractSource $source): self
    {
        return $inner instanceof KeyedExclusiveProvider
            ? new KeyedUpstreamAwareProvider($inner, $source)
            : new self($inner, $source);
    }

    public function type(): string
    {
        return $this->inner->type();
    }

    public function semantics(): ResourceSemantics
    {
        return $this->inner->semantics();
    }

    public function scope(): StateScope
    {
        return $this->inner->scope();
    }

    /**
     * `candidats_internes ∪ candidats_amont` (bruts, sans arbitrage). Quand la
     * source est vide (aucun contrat actif), `concat([])` rend les candidats
     * internes inchangés (pass-through strict — NFR3).
     *
     * @return Collection<int, \App\Services\Agent\StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        $upstream = $this->source->candidatesFor($this->inner->type(), $this->inner->scope());

        return $this->inner->itemsFor($ctx)->concat($upstream);
    }
}
