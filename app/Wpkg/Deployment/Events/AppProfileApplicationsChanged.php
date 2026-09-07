<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Composition d'un AppProfile modifiée pour N applications en une fois.
 * Variante plurielle de `AppProfileApplicationChanged` (payload
 * singulier conservé pour rétro-compat).
 *
 * Cas d'usage : ajout en masse d'une catégorie — N apps ajoutées en une
 * mutation. Évite N invalidations cache redondantes sur le même `appProfileId`.
 *
 * Émetteur : `App\Services\AppProfile\AppProfileService::add/removeApplications`.
 * Listener : `InvalidateWorkstationPackagesCache::handleAppProfileApplicationsChanged`
 * — délègue à la logique `hostnamesForAppProfile` (parité union postes
 * directs + postes via parcs liés).
 *
 * @phpstan-type Direction 'attached'|'detached'
 */
final readonly class AppProfileApplicationsChanged
{
    use Dispatchable;

    /**
     * @param  list<int>  $applicationIds
     */
    public function __construct(
        public int $appProfileId,
        public array $applicationIds,
        public string $direction,
    ) {
    }
}
