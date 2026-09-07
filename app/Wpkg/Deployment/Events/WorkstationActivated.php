<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Poste passé en `active` (par parité, pas de filtrage
 * actif/inactive côté résolution — l'event sert à invalider le cache si la
 * source de vérité applicative change).
 * Émetteurs : (HORS scope ici).
 */
final readonly class WorkstationActivated
{
    use Dispatchable;

    public function __construct(
        public int $workstationId,
    ) {
    }
}
