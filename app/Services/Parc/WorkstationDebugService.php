<?php

declare(strict_types=1);

namespace App\Services\Parc;

use App\Models\Workstation;
use App\Wpkg\Deployment\Services\WorkstationOptionsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mode debug d'un poste — point d'entrée UNIQUE du toggle.
 *
 * Un seul drapeau `workstations.debug` pilote DEUX effets, atomiquement :
 *
 *   1. **Canal agent** — `debug` est exposé dans l'enveloppe desired-state
 *      (`StateCompiler::compile`). En debug, le compagnon de session garde sa
 *      console ouverte (toutes sessions) et y recopie ses logs. Le champ entre
 *      dans le hash d'état → le toggle change l'ETag et franchit le cache 304.
 *
 *   2. **Canal WPKG legacy** — les options `.ini` `debug` ET `logdebug` du
 *      poste suivent le drapeau (logs WPKG détaillés + temps réel serveur),
 *      via {@see WorkstationOptionsService} (qui régénère le `<hostname>.ini`
 *      par event).
 *
 * Tout passe par CE service : aucun écrivain direct de `workstations.debug`
 * ailleurs, sinon les deux canaux divergent.
 */
final class WorkstationDebugService
{
    /** Options `.ini` WPKG asservies au mode debug. */
    private const WPKG_DEBUG_OPTIONS = ['debug', 'logdebug'];

    public function __construct(
        private readonly WorkstationOptionsService $wpkgOptions,
    ) {}

    /**
     * Active/désactive le mode debug du poste et propage aux options WPKG.
     *
     * Idempotent : appeler avec la valeur courante ne fait que réaffirmer
     * l'état (le service d'options supprime/écrit selon le défaut legacy).
     */
    public function setDebug(Workstation $workstation, bool $enabled): void
    {
        DB::transaction(function () use ($workstation, $enabled): void {
            $workstation->debug = $enabled;
            $workstation->save();

            $this->wpkgOptions->update(
                $workstation->id,
                array_fill_keys(self::WPKG_DEBUG_OPTIONS, $enabled),
            );
        });

        Log::channel('agent')->info('[WorkstationDebugService] mode debug basculé', [
            'action_type' => 'workstation.debug.toggled',
            'workstation_id' => $workstation->id,
            'debug' => $enabled,
        ]);
    }
}
