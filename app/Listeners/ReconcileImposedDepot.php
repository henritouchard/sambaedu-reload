<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ControlHubContractChanged;
use App\Services\ControlHub\ImposedDepotReconciler;
use Illuminate\Support\Facades\Log;

/**
 * Listener déclenchant la réconciliation du dépôt IMPOSÉ (bascule
 * exclusive du canal dépôts) à chaque mutation du contrat amont (controlHub).
 *
 * **3ᵉ consommateur** de {@see ControlHubContractChanged}, enregistré APRÈS
 * {@see ReconcileImposedWorkstationGroups} et {@see ProvisionOrderedApplications}
 * . L'ORDRE est un INVARIANT : le provisionnement ordonné matérialise ses
 * `Application` (`depot_id = null`) AVANT que ce réconciliateur ne calcule transferts et
 * purges — sinon des apps ordonnées non encore matérialisées échapperaient au dépôt imposé.
 *
 * Listener SYNCHRONE (pas de `ShouldQueue`) : l'événement est dispatché APRÈS le commit
 * de l'ingestion ; le réconciliateur ne peut donc pas faire rollback de l'ingestion validée.
 *
 * Sans contrat amont actif, l'événement n'est jamais émis ; le réconciliateur
 * lui-même est un no-op total s'il est invoqué sans contrat actif.
 */
class ReconcileImposedDepot
{
    public function __construct(
        private readonly ImposedDepotReconciler $reconciler,
    ) {
    }

    public function handle(ControlHubContractChanged $event): void
    {
        // La réconciliation du dépôt imposé ne doit JAMAIS faire échouer une ingestion
        // déjà committée : l'événement est dispatché APRÈS le commit. On isole donc tout
        // échec ici (log, sans propager) — en complément du try/catch par app/dépôt.
        try {
            $result = $this->reconciler->reconcile();

            Log::info('[ReconcileImposedDepot] Réconciliation du dépôt imposé (chemin événementiel)', $result->toArray());
        } catch (\Throwable $e) {
            Log::error('[ReconcileImposedDepot] Échec de la réconciliation déclenchée par ControlHubContractChanged', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
