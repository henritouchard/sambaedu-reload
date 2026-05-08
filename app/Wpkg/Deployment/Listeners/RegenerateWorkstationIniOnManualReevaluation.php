<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Listeners;

use App\Models\Workstation;
use App\Wpkg\Deployment\Events\WorkstationManualReevaluationRequested;
use App\Wpkg\Deployment\Generators\WorkstationIniGenerator;
use Illuminate\Support\Facades\Log;

/**
 * Story 15.5 / AC4.4 — Régénère le `.ini` d'un poste suite à une demande
 * manuelle de re-évaluation depuis le dashboard.
 *
 * Listener distinct de `RegenerateWorkstationIniOnOptionsChanged` (15.2)
 * pour préserver la sémantique : le manuel trace `triggered_by_user_id`
 * dans les logs et n'est pas déclenché par un changement d'options.
 *
 * Le poste introuvable est silencieux (log warning, pas d'exception)
 * pour préserver l'UX du bouton (toast success déjà émis côté UI).
 */
final class RegenerateWorkstationIniOnManualReevaluation
{
    public function __construct(
        private readonly WorkstationIniGenerator $generator,
    ) {
    }

    public function handle(WorkstationManualReevaluationRequested $event): void
    {
        $workstation = Workstation::query()
            ->whereKey($event->workstationId)
            ->with('wpkgOptions')
            ->first();

        if ($workstation === null) {
            Log::channel('wpkg-deploy')->warning(
                '[RegenerateWorkstationIniOnManualReevaluation] poste introuvable',
                [
                    'workstation_id' => $event->workstationId,
                    'triggered_by_user_id' => $event->triggeredByUserId,
                ],
            );

            return;
        }

        $this->generator->generate($workstation);

        Log::channel('wpkg-deploy')->info(
            '[RegenerateWorkstationIniOnManualReevaluation] .ini régénéré',
            [
                'event' => 'wpkg_manual_reevaluation_ini_regenerated',
                'workstation_id' => $workstation->id,
                'hostname' => $workstation->name,
                'triggered_by_user_id' => $event->triggeredByUserId,
            ],
        );
    }
}
