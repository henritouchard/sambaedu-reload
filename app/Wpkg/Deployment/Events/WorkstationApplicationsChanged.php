<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Story 15.4 / AC4.0 — Applications WPKG attachées/détachées directement à un poste
 * (pivot `application_workstation`).
 *
 * Émetteur : `App\Services\AppProfile\AppProfileService::add/removeApplicationsToWorkstation`.
 * Listener : `InvalidateWorkstationPackagesCache::handleWorkstationApplicationsChanged`
 * — invalide le cache `wpkg:packages:{hostname}` du poste cible uniquement.
 *
 * @phpstan-type Direction 'attached'|'detached'
 */
final readonly class WorkstationApplicationsChanged
{
    use Dispatchable;

    /**
     * @param  list<int>  $applicationIds
     */
    public function __construct(
        public int $workstationId,
        public array $applicationIds,
        public string $direction,
    ) {
    }
}
