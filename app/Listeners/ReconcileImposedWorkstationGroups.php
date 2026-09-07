<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ControlHubContractChanged;
use App\Services\ControlHub\ImposedWorkstationGroupReconciler;
use Illuminate\Support\Facades\Log;

/**
 * Listener déclenchant la réconciliation des groupes imposés à chaque
 * mutation du contrat amont (controlHub).
 *
 * **1er consommateur** de {@see ControlHubContractChanged} (l'événement était inerte
 * depuis). L'ingestion n'est PAS modifiée : elle émet déjà l'événement
 * **après commit**, uniquement sur mutation (jamais sur no-op).
 *
 * Listener **synchrone** (pas de `ShouldQueue`) : l'événement est déjà dispatché après
 * le commit de l'ingestion, et les écritures AD sont elles-mêmes déférées en jobs queue
 * par {@see \App\Observers\WorkstationGroupObserver}. Un listener synchrone garde le
 * comportement standalone et les tests simples (aucune file à drainer pour observer
 * l'effet sur les WorkstationGroup).
 *
 * Sans contrat amont actif, l'événement n'est jamais émis ; le reconciler
 * lui-même est un no-op total s'il est invoqué sans contrat actif.
 */
class ReconcileImposedWorkstationGroups
{
    public function __construct(
        private readonly ImposedWorkstationGroupReconciler $reconciler,
    ) {
    }

    public function handle(ControlHubContractChanged $event): void
    {
        // La réconciliation des groupes imposés ne doit JAMAIS faire échouer une
        // ingestion déjà committée : l'événement est dispatché APRÈS le commit de
        // l'ingestion. On isole donc tout échec ici (log, sans propager).
        try {
            $this->reconciler->reconcile();
        } catch (\Throwable $e) {
            Log::error('[ReconcileImposedWorkstationGroups] Échec de la réconciliation déclenchée par ControlHubContractChanged', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
