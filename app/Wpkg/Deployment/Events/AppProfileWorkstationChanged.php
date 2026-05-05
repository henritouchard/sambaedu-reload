<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Story 15.2 / AC4.1 — AppProfile attaché/détaché directement à un poste.
 * Émetteurs : Story 15.4 (HORS scope ici).
 */
final readonly class AppProfileWorkstationChanged
{
    use Dispatchable;

    public function __construct(
        public int $appProfileId,
        public int $workstationId,
        public string $direction,
    ) {
    }
}
