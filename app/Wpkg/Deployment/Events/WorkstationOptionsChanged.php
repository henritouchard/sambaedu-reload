<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Story 15.2 / AC4.1 — Options `.ini` WPKG modifiées sur un poste.
 * Déclenche la régénération atomique du fichier `.ini` per-poste
 * (cf. `RegenerateWorkstationIniOnOptionsChanged` listener).
 * Émetteurs : Story 15.4 (HORS scope ici).
 */
final readonly class WorkstationOptionsChanged
{
    use Dispatchable;

    /**
     * @param  list<string>  $changedKeys  Liste des `option_key` modifiées.
     */
    public function __construct(
        public int $workstationId,
        public array $changedKeys = [],
    ) {
    }
}
