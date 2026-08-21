<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ControlHubContractChanged;
use App\Services\ControlHub\ShortcutContractReconciler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Porte les raccourcis du contrat amont jusqu'à la bibliothèque locale, à chaque
 * mutation du contrat.
 *
 * Synchrone, et volontairement placé AVANT le dispatch des binaires imposés : le
 * raccourci doit exister quand le téléchargement de son icône aboutit, sinon le
 * pull n'aurait rien sur quoi recoller `icon_asset`.
 */
class MaterializeContractShortcuts
{
    public function __construct(
        private readonly ShortcutContractReconciler $reconciler,
    ) {
    }

    public function handle(ControlHubContractChanged $event): void
    {
        // L'événement est émis après le commit de l'ingestion : un échec ici ne doit
        // jamais remonter, sous peine de faire échouer une réception déjà validée.
        try {
            $result = $this->reconciler->reconcile();

            Log::info('[MaterializeContractShortcuts] Raccourcis du contrat matérialisés', $result->toArray());
        } catch (Throwable $e) {
            Log::error('[MaterializeContractShortcuts] Échec de la matérialisation déclenchée par ControlHubContractChanged', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
