<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Poste archivé (`status = 'archived'` ou désactivation
 * équivalente). L'event sert à invalider le cache.
 * Émetteurs : (HORS scope ici).
 */
final readonly class WorkstationArchived
{
    use Dispatchable;

    public function __construct(
        public int $workstationId,
    ) {
    }
}
