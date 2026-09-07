<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ControlHubContract;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Événement de changement du contrat amont (controlHub).
 *
 * Émis **exactement une fois** par {@see \App\Services\ControlHub\ControlHubContractIngestionService}
 * lorsqu'une réception a produit une **mutation fonctionnelle** (création du contrat, upsert
 * d'un item/label/groupe/app, ou prune d'un enfant disparu). Une réception strictement
 * identique (no-op) **n'émet pas** cet événement : la réception est idempotente.
 *
 * Les consommateurs sont déclarés dans {@see \App\Providers\EventServiceProvider},
 * où leur ORDRE d'exécution est un invariant vérifié par test.
 */
final readonly class ControlHubContractChanged
{
    use Dispatchable;

    public function __construct(
        public ControlHubContract $contract,
    ) {
    }
}
