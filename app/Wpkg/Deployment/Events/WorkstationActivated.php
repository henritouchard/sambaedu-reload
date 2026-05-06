<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Story 15.2 / AC4.1 — Poste passé en `active` (par parité, pas de filtrage
 * actif/inactive côté résolution — l'event sert à invalider le cache si la
 * source de vérité applicative change).
 * Émetteurs : Story 15.4 (HORS scope ici).
 */
final readonly class WorkstationActivated
{
    use Dispatchable;

    public function __construct(
        public int $workstationId,
    ) {
    }
}
