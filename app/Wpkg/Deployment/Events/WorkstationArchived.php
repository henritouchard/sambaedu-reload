<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Story 15.2 / AC4.1 — Poste archivé (`status = 'archived'` ou désactivation
 * équivalente). L'event sert à invalider le cache.
 * Émetteurs : Story 15.4 (HORS scope ici).
 */
final readonly class WorkstationArchived
{
    use Dispatchable;

    public function __construct(
        public int $workstationId,
    ) {
    }
}
