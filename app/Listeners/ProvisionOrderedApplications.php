<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ControlHubContractChanged;
use App\Services\ControlHub\OrderedApplicationProvisioner;
use Illuminate\Support\Facades\Log;

/**
 * Story 31.3 — Listener déclenchant l'approvisionnement des applications ordonnées par
 * le contrat amont (controlHub) à chaque mutation du contrat.
 *
 * 2ᵉ consommateur de {@see ControlHubContractChanged} (à côté de
 * {@see ReconcileImposedWorkstationGroups}, 30.3). L'ingestion 28.2 n'est PAS modifiée :
 * elle émet l'événement APRÈS commit, uniquement sur mutation (jamais sur no-op — NFR4).
 *
 * Listener SYNCHRONE (pas de `ShouldQueue`) : la matérialisation est DIRECTE (« Option B »,
 * aucun fetch réseau — pas d'install serveur), donc rien à différer. L'événement étant
 * dispatché après le commit de l'ingestion, le provisionneur ne peut pas faire rollback
 * de l'ingestion validée.
 *
 * NFR3 — sans contrat amont actif, l'événement n'est jamais émis ; le provisionneur
 * lui-même est un no-op total s'il est invoqué sans contrat actif.
 *
 * ⚠️ GARDE-FOU R3 : vocabulaire « amont » exclusivement, terme prohibé proscrit.
 * [Source: prd-contrat-manage-se5.md#R3]
 */
class ProvisionOrderedApplications
{
    public function __construct(
        private readonly OrderedApplicationProvisioner $provisioner,
    ) {
    }

    public function handle(ControlHubContractChanged $event): void
    {
        // L'approvisionnement ne doit JAMAIS faire échouer une ingestion déjà committée :
        // l'événement est dispatché APRÈS le commit. On isole donc tout échec ici (log,
        // sans propager) — en complément du try/catch par app du provisionneur.
        try {
            $result = $this->provisioner->provision();

            // Récapitulatif du chemin ÉVÉNEMENTIEL (chemin nominal en prod) : sans cela,
            // seul subsistent les logs par app du provisionneur. Donne une vue d'ensemble
            // provisioned/skipped/failed à chaque mutation de contrat.
            Log::info('agent.applications.provision_summary', $result->toArray());
        } catch (\Throwable $e) {
            Log::error('[ProvisionOrderedApplications] Échec de l\'approvisionnement déclenché par ControlHubContractChanged', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
