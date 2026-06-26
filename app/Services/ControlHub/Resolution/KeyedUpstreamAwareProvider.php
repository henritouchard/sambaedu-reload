<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Resolution;

use App\Services\Agent\Contracts\KeyedExclusiveProvider;

/**
 * Story 28.3 — Variante du {@see UpstreamAwareProvider} pour un provider interne
 * qui implémente {@see KeyedExclusiveProvider} (ex. `registry`).
 *
 * RELAIE `exclusiveKey()` au provider interne : sans ce relais,
 * `StateCompiler::selectExclusive()` ne verrait plus un provider « par identité
 * de clé » et retomberait sur « un seul gagnant pour tout le type », écrasant
 * les clés distinctes (registry/associations). La fabrique
 * {@see UpstreamAwareProvider::wrap()} instancie cette variante quand le provider
 * interne porte le marqueur.
 *
 * ⚠️ GARDE-FOU R3 : aucun « central ». Vocabulaire « amont » / `Upstream`.
 */
final class KeyedUpstreamAwareProvider extends UpstreamAwareProvider implements KeyedExclusiveProvider
{
    /**
     * Relaie au provider interne (qui est garanti `KeyedExclusiveProvider` par la
     * fabrique). La clé d'exclusivité d'un candidat amont est ainsi calculée
     * EXACTEMENT comme celle d'un candidat local ⇒ ils entrent en concurrence
     * sur la même clé (l'amont gagne par sa maille `Upstream`, au compilateur).
     *
     * @param  array<string,mixed>  $payload
     */
    public function exclusiveKey(array $payload): string
    {
        /** @var KeyedExclusiveProvider $inner */
        $inner = $this->inner;

        return $inner->exclusiveKey($payload);
    }
}
