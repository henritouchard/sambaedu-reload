<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RoamingProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 26.3 — Snapshot quotidien des tailles de profils itinérants.
 *
 * Scanne `/home/profiles` une fois par nuit (`du --max-depth=1 -b`) et persiste :
 *   - la taille par-login → colonne `users.profile_snapshot` (badge tableau /app/users) ;
 *   - la liste des profils orphelins (dossier sans compte user) → SystemSetting
 *     `profiles.orphans` (bandeau + purge dans l'onglet admin profils-itinérants).
 *
 * Calque le pattern `quota:snapshot` (story 5.1b) : un job nocturne calcule UNE
 * FOIS et écrit le cache ; l'UI lit le cache sans aucun shellout/scan par render.
 * CONTRAINTE PERF non négociable (Henri) : `du`/scan FS = ce job UNIQUEMENT.
 *
 * Fail-soft : si `/home/profiles` est absent ou que `du` échoue, on log
 * `Log::error` et on conserve le snapshot précédent (exit FAILURE non fatal pour
 * le scheduler — cohérent quota:snapshot).
 *
 * Logs préfixés `[RoamingProfileService]` (cohérent avec le service appelé).
 *
 * Planifiée dans `Console\Kernel::schedule()` à 04h30 (créneau nocturne libre).
 */
class ProfilesSnapshotCommand extends Command
{
    protected $signature = 'profiles:snapshot';

    protected $description = 'Snapshot quotidien des tailles de profils itinérants — alimente users.profile_snapshot + profiles.orphans';

    public function handle(RoamingProfileService $service): int
    {
        $start = microtime(true);

        $sizes = $service->scanProfileSizes();

        if ($sizes === null) {
            // Scan impossible (/home/profiles absent, du KO). Snapshot précédent
            // conservé. Exit FAILURE mais non fatal pour le scheduler.
            $this->error('ProfilesSnapshot: scan /home/profiles impossible, snapshot précédent conservé.');
            return self::FAILURE;
        }

        try {
            $result = $service->persistSnapshot($sizes);
        } catch (\Throwable $e) {
            Log::error('[RoamingProfileService] ProfilesSnapshot: persistance échouée', [
                'op' => 'profiles:snapshot',
                'error' => $e->getMessage(),
            ]);
            $this->error('ProfilesSnapshot: persistance du snapshot échouée.');
            return self::FAILURE;
        }

        $duration = round(microtime(true) - $start, 2);

        $this->info(sprintf(
            'ProfilesSnapshot terminé — dossiers scannés : %d | users mis à jour : %d | profils orphelins : %d | durée : %ss',
            count($sizes),
            $result['users_updated'],
            $result['orphans'],
            $duration
        ));

        return self::SUCCESS;
    }
}
