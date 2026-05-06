<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Listeners;

use App\Models\Workstation;
use App\Wpkg\Deployment\Events\WorkstationOptionsChanged;
use App\Wpkg\Deployment\Generators\WorkstationIniGenerator;
use Illuminate\Support\Facades\Log;

/**
 * Story 15.2 / AC4.3 — Listener regen `.ini` sur changement d'options.
 */
final class RegenerateWorkstationIniOnOptionsChanged
{
    public function __construct(
        private readonly WorkstationIniGenerator $generator,
    ) {
    }

    public function handle(WorkstationOptionsChanged $event): void
    {
        $workstation = Workstation::query()
            ->whereKey($event->workstationId)
            ->with('wpkgOptions')
            ->first();

        if ($workstation === null) {
            Log::channel('wpkg-deploy')->warning(
                '[RegenerateWorkstationIniOnOptionsChanged] poste introuvable',
                ['workstation_id' => $event->workstationId],
            );

            return;
        }

        $this->generator->generate($workstation);
    }
}
