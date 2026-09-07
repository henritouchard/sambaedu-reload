<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Poste qui rejoint/quitte un parc.
 * Émetteurs : (HORS scope ici).
 *
 * @phpstan-type Direction 'joined'|'left'
 */
final readonly class WorkstationGroupMembershipChanged
{
    use Dispatchable;

    public function __construct(
        public int $workstationId,
        public int $workstationGroupId,
        public string $direction,
    ) {
    }
}
