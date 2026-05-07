<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Story 15.4 / AC4.0 — Applications WPKG attachées/détachées directement à un parc
 * (pivot `application_workstation_group`). Strictement additif vs la palette 15.2
 * qui ne couvrait que la composition d'un AppProfile.
 *
 * Émetteur : `App\Services\AppProfile\AppProfileService::add/removeApplicationsToWorkstationGroup`.
 * Listener : `InvalidateWorkstationPackagesCache::handleWorkstationGroupApplicationsChanged`
 * — invalide le cache `wpkg:packages:{hostname}` pour tous les postes du parc.
 *
 * @phpstan-type Direction 'attached'|'detached'
 */
final readonly class WorkstationGroupApplicationsChanged
{
    use Dispatchable;

    /**
     * @param  list<int>  $applicationIds  IDs des applications attachées/détachées (>= 1).
     */
    public function __construct(
        public int $workstationGroupId,
        public array $applicationIds,
        public string $direction,
    ) {
    }
}
