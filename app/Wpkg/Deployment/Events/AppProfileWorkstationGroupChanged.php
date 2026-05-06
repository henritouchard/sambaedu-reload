<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Story 15.2 / AC4.1 — AppProfile attaché/détaché d'un parc.
 * Émetteurs : Story 15.4 (HORS scope ici).
 *
 * @phpstan-type Direction 'attached'|'detached'
 */
final readonly class AppProfileWorkstationGroupChanged
{
    use Dispatchable;

    public function __construct(
        public int $appProfileId,
        public int $workstationGroupId,
        public string $direction,
    ) {
    }
}
