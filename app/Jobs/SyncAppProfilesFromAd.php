<?php

namespace App\Jobs;

use App\Models\AppProfile;
use App\Models\WorkstationGroup;
use App\LdapModels\DeviceGroupTagModel;
use App\LdapModels\DeviceGroupModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job de synchronisation des profils applicatifs depuis Active Directory
 * 
 * Ce job récupère les OU=Parc depuis AD et crée des AppProfiles correspondants.
 * Il utilise LdapRecord (pas de fonctions legacy).
 * 
 * Logique de filtrage :
 * - Récupère toutes les OU sous OU=Computers (salles/parcs)
 * - Exclut les OU qui ont un ordinateur du même nom (ce sont des machines, pas des parcs)
 * - Exclut les OU qui ont déjà un WorkstationGroup du même nom (déjà synchronisées)
 * - Exclut les OU qui ont déjà un AppProfile du même nom
 * 
 * Peut être exécuté :
 * - Manuellement via l'interface web
 * - Via artisan: php artisan sync:app-profiles
 * - Programmatiquement: SyncAppProfilesFromAd::dispatch()
 */
class SyncAppProfilesFromAd implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('[SyncAppProfiles] Démarrage de la synchronisation AD -> MySQL (LdapRecord)');

        try {
            // 1. Récupérer tous les parcs (groupes dans OU=Parcs)
            $parcsAd = $this->fetchParcsFromAd();
            Log::info('[SyncAppProfiles] ' . count($parcsAd) . ' parcs trouvés dans OU=Parcs');

            if (empty($parcsAd)) {
                Log::warning('[SyncAppProfiles] Aucun parc trouvé dans AD');
                return;
            }

            // 2. Récupérer tous les groupes (OU sous OU=Computers)
            $groupesAd = $this->fetchGroupesFromAd();
            Log::info('[SyncAppProfiles] ' . count($groupesAd) . ' groupes trouvés dans OU=Computers');

            // 3. Filtrer : garder les parcs dont le nom N'EXISTE PAS dans les groupes
            $parcsAd = $this->filterParcsNotInGroupes($parcsAd, $groupesAd);
            Log::info('[SyncAppProfiles] ' . count($parcsAd) . ' parcs après filtrage (exclusion des parcs ayant un groupe du même nom)');

            if (empty($parcsAd)) {
                Log::info('[SyncAppProfiles] Aucun nouveau parc à importer');
                return;
            }

            DB::beginTransaction();

            try {
                $stats = $this->syncAppProfiles($parcsAd);
                DB::commit();
                Log::info('[SyncAppProfiles] Synchronisation terminée', $stats);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('[SyncAppProfiles] Erreur: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * 1. Récupère les parcs (groupes de sécurité) depuis OU=Parcs
     * 
     * @return array Liste des parcs avec leurs attributs
     */
    private function fetchParcsFromAd(): array
    {
        $parcs = [];

        try {
            $results = DeviceGroupTagModel::all();

            foreach ($results as $parc) {
                $dn = $parc->getDn();
                
                // Ne garder que les groupes dans OU=Parcs
                if (!preg_match('/OU=Parcs/i', $dn)) {
                    continue;
                }

                $name = $parc->getParcName();
                if (empty($name)) {
                    continue;
                }

                $rawGuid = $parc->getFirstAttribute('objectguid');
                $uuid = $rawGuid ? $this->convertGuidToString($rawGuid) : null;

                $parcs[] = [
                    'name' => $name,
                    'description' => $parc->getDescription(),
                    'dn' => $dn,
                    'uuid' => $uuid,
                ];
            }
        } catch (\Exception $e) {
            Log::error('[SyncAppProfiles] Erreur récupération parcs: ' . $e->getMessage());
            throw $e;
        }

        return $parcs;
    }

    /**
     * 2. Récupère les groupes (OU) depuis OU=Computers
     * 
     * @return array Liste des noms de groupes (en minuscules)
     */
    private function fetchGroupesFromAd(): array
    {
        $groupes = [];

        try {
            $results = DeviceGroupModel::all();

            foreach ($results as $groupe) {
                $dn = $groupe->getDn();
                
                // Ne garder que les OU sous OU=Computers
                if (!preg_match('/OU=computers/i', $dn)) {
                    continue;
                }

                $name = $groupe->getGroupName();
                if (empty($name) || strtolower($name) === 'computers') {
                    continue;
                }

                $groupes[] = strtolower($name);
            }
        } catch (\Exception $e) {
            Log::error('[SyncAppProfiles] Erreur récupération groupes: ' . $e->getMessage());
            throw $e;
        }

        return $groupes;
    }

    /**
     * 3. Filtre les parcs dont le nom N'EXISTE PAS dans les groupes
     * 
     * @param array $parcs Liste des parcs
     * @param array $groupes Liste des noms de groupes (en minuscules)
     * @return array Parcs filtrés
     */
    private function filterParcsNotInGroupes(array $parcs, array $groupes): array
    {
        $filtered = [];

        foreach ($parcs as $parc) {
            $name = $parc['name'] ?? null;
            if (empty($name)) {
                continue;
            }

            if (!in_array(strtolower($name), $groupes)) {
                // Le parc n'a pas de groupe du même nom -> on le garde
                $filtered[] = $parc;
            } else {
                Log::debug('[SyncAppProfiles] Parc exclu (groupe existant): ' . $name);
            }
        }

        return $filtered;
    }

    /**
     * Synchronise les profils applicatifs depuis AD vers MySQL
     * 
     * Crée un AppProfile pour chaque OU AD.
     * 
     * @param array $ousAd Liste des OU depuis AD
     * @return array Statistiques de synchronisation
     */
    private function syncAppProfiles(array $ousAd): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        // Récupérer les profils existants par nom
        $existingProfiles = AppProfile::all()->keyBy(fn($p) => strtolower($p->name));

        foreach ($ousAd as $ouAd) {
            $name = $ouAd['name'] ?? null;
            if (empty($name)) {
                continue;
            }

            // Vérifier si un profil existe déjà
            if ($existingProfiles->has(strtolower($name))) {
                Log::debug('[SyncAppProfiles] Profil déjà existant: ' . $name);
                $stats['skipped']++;
                continue;
            }

            // Préparer les données du profil
            $data = [
                'name' => $name,
                'description' => $ouAd['description'] ?? null,
                'ad_guid' => $ouAd['uuid'] ?? null,
            ];

            // Créer le profil
            AppProfile::create($data);
            $stats['created']++;
            Log::debug('[SyncAppProfiles] Profil créé: ' . $name);
        }

        return $stats;
    }

    /**
     * Convertit un GUID binaire AD en format string lisible
     * 
     * @param string $binaryGuid GUID binaire
     * @return string GUID au format xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
     */
    private function convertGuidToString(string $binaryGuid): string
    {
        $hex = bin2hex($binaryGuid);
        
        if (strlen($hex) !== 32) {
            return $hex; // Retourner tel quel si format inattendu
        }

        // Format AD GUID : les 3 premiers groupes sont en little-endian
        return sprintf(
            '%s%s%s%s-%s%s-%s%s-%s-%s',
            substr($hex, 6, 2),
            substr($hex, 4, 2),
            substr($hex, 2, 2),
            substr($hex, 0, 2),
            substr($hex, 10, 2),
            substr($hex, 8, 2),
            substr($hex, 14, 2),
            substr($hex, 12, 2),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[SyncAppProfiles] Job échoué définitivement: ' . $exception->getMessage());
    }
}
