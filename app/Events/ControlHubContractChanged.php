<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ControlHubContract;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Story 28.2 — Événement de changement du contrat amont (controlHub).
 *
 * Émis **exactement une fois** par {@see \App\Services\ControlHub\ControlHubContractIngestionService}
 * lorsqu'une réception a produit une **mutation fonctionnelle** (création du contrat, upsert
 * d'un item/label/groupe/app, ou prune d'un enfant disparu). Une réception strictement
 * identique (no-op) **n'émet pas** cet événement (NFR4 — idempotence).
 *
 * ⚠️ Aucun listener n'est branché en Story 28.2 : l'événement est **inerte** (NFR3 —
 * comportement standalone strictement inchangé tant qu'aucun consommateur ne s'y abonne).
 * Story 28.3+ pourra s'y abonner (réveil du `StateCompiler`, invalidation de cache…).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans cet événement.
 * Vocabulaire imposé : « amont » / `ControlHub*` / `authority`. [Source: prd-contrat-manage-se5.md#R3]
 */
final readonly class ControlHubContractChanged
{
    use Dispatchable;

    public function __construct(
        public ControlHubContract $contract,
    ) {
    }
}
