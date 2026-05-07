<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Story 15.4 / AC3.3 + AC4.0 — Composition d'un AppProfile modifiée pour N applications
 * en une fois. Variante pluriel de `AppProfileApplicationChanged` (15.2 — payload
 * singulier conservé pour rétro-compat).
 *
 * Cas d'usage : bulk catégorie (Décision C 2026-05-07) — N apps ajoutées en une
 * mutation. Évite N invalidations cache redondantes sur le même `appProfileId`.
 *
 * Émetteur : `App\Services\AppProfile\AppProfileService::add/removeApplications`.
 * Listener : `InvalidateWorkstationPackagesCache::handleAppProfileApplicationsChanged`
 * — délègue à la logique `hostnamesForAppProfile` (parité 15.2 union postes
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
