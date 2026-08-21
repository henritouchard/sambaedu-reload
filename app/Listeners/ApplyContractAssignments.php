<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ControlHubContractChanged;
use App\Services\ControlHub\ContractAssignmentReconciler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Traduit en assignations réelles ce que le contrat amont destine aux parcs.
 *
 * Placé APRÈS la matérialisation des raccourcis et l'approvisionnement des
 * applications : il assigne des objets locaux, qui doivent donc exister au moment
 * où il tourne.
 */
class ApplyContractAssignments
{
    public function __construct(
        private readonly ContractAssignmentReconciler $reconciler,
    ) {
    }

    public function handle(ControlHubContractChanged $event): void
    {
        // L'événement suit le commit de l'ingestion : un échec ici ne doit pas
        // remonter, sous peine de faire échouer une réception déjà validée.
        try {
            $result = $this->reconciler->reconcile();

            Log::info('[ApplyContractAssignments] Assignations du contrat appliquées', $result->toArray());
        } catch (Throwable $e) {
            Log::error('[ApplyContractAssignments] Échec de la pose déclenchée par ControlHubContractChanged', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
