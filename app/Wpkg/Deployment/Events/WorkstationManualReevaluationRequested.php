<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Story 15.5 / AC4.4 — Re-évaluation manuelle d'un poste WPKG demandée
 * depuis le dashboard.
 *
 * Émetteur : page `pages/wpkg/deployments/index.blade.php` ou page détail
 * poste — bouton « Forcer une re-évaluation » (visible si `wpkg.assign`).
 *
 * Listeners :
 *   - `InvalidateWorkstationPackagesCache::handleManualReevaluation()` →
 *     purge `wpkg:packages:{hostname}`.
 *   - `RegenerateWorkstationIniOnManualReevaluation::handle()` → régénère
 *     le fichier `<hostname>.ini` via `WorkstationIniGenerator`.
 *
 * Sémantique distincte des events 15.2/15.4 (origine manuelle traçable
 * via `triggeredByUserId` dans les logs `wpkg-deploy`).
 */
final readonly class WorkstationManualReevaluationRequested
{
    use Dispatchable;

    public function __construct(
        public int $workstationId,
        public int $triggeredByUserId,
    ) {
    }
}
