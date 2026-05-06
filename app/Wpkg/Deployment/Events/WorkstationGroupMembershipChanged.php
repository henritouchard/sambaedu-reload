<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Story 15.2 / AC4.1 — Poste qui rejoint/quitte un parc.
 * Émetteurs : Story 15.4 (HORS scope ici).
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
