<?php

namespace App\Jobs;

use App\Models\AppProfile;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Repositories\AppProfileRepository;
use App\Repositories\WorkstationGroupRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job de synchronisation des groupes de postes depuis Active Directory
 */
class SyncWorkstationGroupsFromAd implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function handle(
        WorkstationGroupRepository $workstationGroupRepository,
        AppProfileRepository $appProfileRepository
    ): void {
        Log::info('[SyncWorkstationGroups] Démarrage de la synchronisation AD -> PostgreSQL');

        try {
            $sallesAd = $workstationGroupRepository->getAllFromAd();

            if (empty($sallesAd)) {
                Log::warning('[SyncWorkstationGroups] Aucune salle trouvée dans AD');
                return;
            }

            Log::info('[SyncWorkstationGroups] ' . count($sallesAd) . ' salles trouvées dans AD');

            // Récupérer les noms des parcs pour déterminer les salles physiques
            $parcNames = $workstationGroupRepository->getParcNamesFromAd();
            Log::info('[SyncWorkstationGroups] ' . count($parcNames) . ' parcs trouvés dans AD');

            $appProfilesAd = $appProfileRepository->getAllFromAd();
            Log::info('[SyncWorkstationGroups] ' . count($appProfilesAd) . ' AppProfiles trouvés dans AD');

            WorkstationGroupObserver::disableSync();

            DB::beginTransaction();

            try {
                $stats = $this->syncSalles($sallesAd, $parcNames);
                $orphanStats = $this->syncOrphanAppProfiles($appProfilesAd, $sallesAd);
                $stats['orphan_profiles'] = $orphanStats;

                DB::commit();

                Log::info('[SyncWorkstationGroups] Synchronisation terminée', $stats);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            } finally {
                WorkstationGroupObserver::enableSync();
            }
        } catch (\Exception $e) {
            Log::error('[SyncWorkstationGroups] Erreur: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Synchronise les salles depuis l'AD vers PostgreSQL
     * 
     * Règle AD → SQL :
     * - Tout OU=Computers est un WorkstationGroup physique (is_physical=true)
     * 
     * @param array $sallesAd Salles récupérées depuis ou=Computers (toutes physiques)
     * @param array $parcNames Noms des parcs (ou=Parcs) - non utilisé ici, les groupes logiques sont importés séparément
     */
    private function syncSalles(array $sallesAd, array $parcNames = []): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'physical' => 0, 'logical' => 0];

        $existingGroups = WorkstationGroup::all()->keyBy('name');
        $processedNames = [];

        foreach ($sallesAd as $salleAd) {
            $name = $salleAd['name'] ?? null;
            if (empty($name)) {
                continue;
            }

            $processedNames[] = $name;

            // Tous les groupes de OU=Computers sont physiques
            $stats['physical']++;

            $data = [
                'name' => $name,
                'display_name' => $name,
                'description' => $salleAd['description'] ?? null,
                'is_physical' => true, // Toujours physique car vient de OU=Computers
                'is_active' => true,
                'ad_guid' => $salleAd['guid'] ?? null,
                'ad_dn' => $salleAd['dn'] ?? null,
            ];

            if (!empty($salleAd['parent'])) {
                $parent = WorkstationGroup::where('name', $salleAd['parent'])->first();
                $data['parent_id'] = $parent?->id;
            }

            $existing = WorkstationGroup::where('name', $name)->first();
            if ($existing) {
                $existing->update($data);
                $stats['updated']++;
            } else {
                WorkstationGroup::create($data);
                $stats['created']++;
            }
        }

        $toDeactivate = $existingGroups->keys()->diff($processedNames);
        if ($toDeactivate->isNotEmpty()) {
            WorkstationGroup::whereIn('name', $toDeactivate)->update(['is_active' => false]);
            $stats['deleted'] = $toDeactivate->count();
        }

        return $stats;
    }

    private function syncOrphanAppProfiles(array $appProfilesAd, array $sallesAd): array
    {
        $stats = ['created' => 0, 'skipped' => 0];

        $sallesIndex = array_column($sallesAd, null, 'name');

        foreach ($appProfilesAd as $profileAd) {
            $name = $profileAd['name'] ?? null;
            if (empty($name)) {
                continue;
            }

            if (WorkstationGroup::where('name', $name)->exists()) {
                $stats['skipped']++;
                continue;
            }

            if (isset($sallesIndex[$name])) {
                $stats['skipped']++;
                continue;
            }

            $appProfile = AppProfile::firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => $profileAd['description'] ?? null,
                    'is_active' => true,
                ]
            );

            if ($appProfile->wasRecentlyCreated) {
                $stats['created']++;
                Log::debug('[SyncWorkstationGroups] AppProfile orphelin créé', [
                    'name' => $name
                ]);
            }
        }

        return $stats;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[SyncWorkstationGroups] Job échoué définitivement: ' . $exception->getMessage());
    }
}
