<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Story 15.2 / AC4.1 — Composition d'un AppProfile modifiée (Application
 * ajoutée/retirée). Impacte tous les postes liés indirectement à ce profile
 * (postes directs + via parcs).
 * Émetteurs : Story 15.4 (HORS scope ici).
 */
final readonly class AppProfileApplicationChanged
{
    use Dispatchable;

    public function __construct(
        public int $appProfileId,
        public int $applicationId,
        public string $direction,
    ) {
    }
}
