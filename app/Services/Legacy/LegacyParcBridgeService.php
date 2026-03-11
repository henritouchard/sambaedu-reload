<?php

namespace App\Services\Legacy;

use App\Models\WorkstationGroup;
use App\Models\AppProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * Service de pont entre le nouveau modèle WorkstationGroup et les tables legacy.
 * 
 * Ce service permet au code legacy d'interagir avec le nouveau modèle de données
 * via des fonctions simples qui traduisent les opérations.
 * 
 * Tables legacy concernées:
 * - parc : Contient les parcs ET les profils WPKG (mélangés)
 * - parc_profile : Liaison poste ↔ parc
 * - applications_profile : Liaison application ↔ entité (parc ou poste)
 * 
 * Nouveau modèle:
 * - workstation_groups : Groupes de postes uniquement
 * - app_profiles : Profils applicatifs séparés
 * - workstation_group_workstation : Liaison groupe ↔ poste
 * - app_profile_workstation_group : Liaison profil ↔ groupe
 * - app_profile_application : Liaison profil ↔ application
 */
class LegacyParcBridgeService
{
    /**
     * Récupère la liste des parcs au format legacy.
     * Traduit depuis workstation_groups vers le format attendu par info_parcs().
     * 
     * @return array Format: ['nom_parc' => ['id' => int, 'nom_parc' => string, 'nom_parc_wpkg' => string|null, 'uuid' => string|null]]
     */
    public function getParcs(): array
    {
        $groups = WorkstationGroup::all();
        $result = [];

        foreach ($groups as $group) {
            $result[$group->name] = [
                'id' => $group->id,
                'nom_parc' => $group->name,
                'nom_parc_wpkg' => $group->display_name,
                'uuid' => $group->ad_guid,
            ];
        }

        return $result;
    }

    /**
     * Récupère les postes d'un parc au format legacy.
     * Traduit depuis workstation_group_workstation.
     * 
     * @param string $nomParc Nom du parc
     * @return array Format: ['nom_poste' => ['id_poste' => int, 'nom_poste' => string, ...]]
     */
    public function getParcPostes(string $nomParc): array
    {
        $group = WorkstationGroup::where('name', $nomParc)->first();
        
        if (!$group) {
            return [];
        }

        // Récupérer les postes via la relation
        $workstations = $group->workstations()->get();
        $result = [];

        foreach ($workstations as $ws) {
            $result[strtolower($ws->nom_poste)] = [
                'id_poste' => $ws->id_poste,
                'nom_poste' => strtolower($ws->nom_poste),
                'OS_poste' => $ws->OS_poste ?? '',
                'date_rapport_poste' => $ws->date_rapport_poste ?? '',
                'ip_poste' => $ws->ip_poste ?? '',
                'mac_address_poste' => $ws->mac_address_poste ?? '',
                'file_log_poste' => $ws->file_log_poste ?? '',
            ];
        }

        return $result;
    }

    /**
     * Crée un nouveau parc.
     * 
     * @param string $nomParc Nom du parc
     * @param string|null $uuid UUID/GUID AD
     * @param bool $isPhysicalRoom True si salle physique
     * @return int ID du parc créé
     */
    public function insertParc(string $nomParc, ?string $uuid = null, bool $isPhysicalRoom = false): int
    {
        $group = WorkstationGroup::create([
            'name' => $nomParc,
            'display_name' => $nomParc,
            'ad_guid' => $uuid,
            'is_physical' => $isPhysicalRoom,
            'is_active' => true,
        ]);

        Log::info('LegacyParcBridge: Parc créé', [
            'id' => $group->id,
            'name' => $nomParc,
        ]);

        return $group->id;
    }

    /**
     * Met à jour un parc existant.
     * 
     * @param int $id ID du parc
     * @param string $nomParc Nouveau nom
     * @param string|null $uuid UUID/GUID AD
     * @return bool Succès
     */
    public function updateParc(int $id, string $nomParc, ?string $uuid = null): bool
    {
        $group = WorkstationGroup::find($id);
        
        if (!$group) {
            Log::warning('LegacyParcBridge: Parc non trouvé pour update', ['id' => $id]);
            return false;
        }

        $group->update([
            'name' => $nomParc,
            'display_name' => $nomParc,
            'ad_guid' => $uuid,
        ]);

        Log::info('LegacyParcBridge: Parc mis à jour', [
            'id' => $id,
            'name' => $nomParc,
        ]);

        return true;
    }

    /**
     * Supprime un parc et ses associations.
     * 
     * @param int $idParc ID du parc
     * @return bool Succès
     */
    public function deleteParc(int $idParc): bool
    {
        $group = WorkstationGroup::find($idParc);
        
        if (!$group) {
            Log::warning('LegacyParcBridge: Parc non trouvé pour suppression', ['id' => $idParc]);
            return false;
        }

        // Les relations sont supprimées en cascade via les FK
        $group->delete();

        Log::info('LegacyParcBridge: Parc supprimé', ['id' => $idParc]);

        return true;
    }

    /**
     * Ajoute un poste à un parc.
     * 
     * @param int $idPoste ID du poste
     * @param int $idParc ID du parc
     * @return int|null ID de l'association créée
     */
    public function insertParcProfile(int $idPoste, int $idParc): ?int
    {
        $group = WorkstationGroup::find($idParc);
        
        if (!$group) {
            Log::warning('LegacyParcBridge: Parc non trouvé pour ajout poste', ['id_parc' => $idParc]);
            return null;
        }

        // Vérifier si l'association existe déjà
        if ($group->workstations()->where('workstation_id', $idPoste)->exists()) {
            Log::debug('LegacyParcBridge: Association poste-parc existe déjà', [
                'id_poste' => $idPoste,
                'id_parc' => $idParc,
            ]);
            return null;
        }

        $group->workstations()->attach($idPoste);

        Log::info('LegacyParcBridge: Poste ajouté au parc', [
            'id_poste' => $idPoste,
            'id_parc' => $idParc,
        ]);

        // Retourner un ID fictif (la table pivot n'a pas d'ID auto-incrémenté visible)
        return DB::getPdo()->lastInsertId() ?: 1;
    }

    /**
     * Retire un poste d'un parc.
     * 
     * @param int $idPoste ID du poste
     * @param int $idParc ID du parc
     * @return bool Succès
     */
    public function deleteParcProfile(int $idPoste, int $idParc): bool
    {
        $group = WorkstationGroup::find($idParc);
        
        if (!$group) {
            return false;
        }

        $group->workstations()->detach($idPoste);

        Log::info('LegacyParcBridge: Poste retiré du parc', [
            'id_poste' => $idPoste,
            'id_parc' => $idParc,
        ]);

        return true;
    }

    /**
     * Récupère les applications d'un parc via les AppProfiles.
     * Traduit la relation parc → app_profiles → applications.
     * 
     * @param string $nomParc Nom du parc
     * @return array Liste des applications
     */
    public function getParcApplications(string $nomParc): array
    {
        $group = WorkstationGroup::where('name', $nomParc)->first();
        
        if (!$group) {
            return [];
        }

        $applications = [];

        // Récupérer les applications via les profils applicatifs
        foreach ($group->appProfiles as $profile) {
            foreach ($profile->applications as $app) {
                $applications[$app->id_depot_applications] = [
                    'id_app' => $app->id_depot_applications,
                    'id_nom_app' => $app->id_nom_app ?? '',
                    'nom_app' => $app->nom_app ?? '',
                ];
            }
        }

        return $applications;
    }

    /**
     * Associe une application à un parc via un AppProfile.
     * Si aucun profil n'existe pour ce parc, en crée un automatiquement.
     * 
     * @param int $idParc ID du parc
     * @param int $idAppli ID de l'application
     * @return int|null ID de l'association
     */
    public function insertApplicationProfile(int $idParc, int $idAppli): ?int
    {
        $group = WorkstationGroup::find($idParc);
        
        if (!$group) {
            Log::warning('LegacyParcBridge: Parc non trouvé pour ajout application', ['id_parc' => $idParc]);
            return null;
        }

        // Trouver ou créer un profil applicatif pour ce groupe
        $profile = $group->appProfiles()->first();
        
        if (!$profile) {
            // Créer un profil par défaut pour ce groupe
            $profile = AppProfile::create([
                'name' => 'profile_' . $group->name,
                'display_name' => 'Profil ' . $group->display_name,
                'description' => 'Profil applicatif auto-généré pour ' . $group->name,
                'is_active' => true,
            ]);
            $group->appProfiles()->attach($profile->id);
        }

        // Ajouter l'application au profil
        if (!$profile->applications()->where('application_id', $idAppli)->exists()) {
            $profile->applications()->attach($idAppli);
        }

        Log::info('LegacyParcBridge: Application ajoutée au parc via profil', [
            'id_parc' => $idParc,
            'id_appli' => $idAppli,
            'profile_id' => $profile->id,
        ]);

        return $profile->id;
    }

    /**
     * Définit les applications d'un parc (remplace toutes les existantes).
     * 
     * @param array $listIdAppli Liste des IDs d'applications
     * @param string $nomEntite Nom du parc
     * @return bool Succès
     */
    public function setEntiteApps(array $listIdAppli, string $nomEntite): bool
    {
        $group = WorkstationGroup::where('name', $nomEntite)->first();
        
        if (!$group) {
            Log::warning('LegacyParcBridge: Parc non trouvé pour set apps', ['nom' => $nomEntite]);
            return false;
        }

        // Trouver ou créer le profil
        $profile = $group->appProfiles()->first();
        
        if (!$profile) {
            $profile = AppProfile::create([
                'name' => 'profile_' . $group->name,
                'display_name' => 'Profil ' . $group->display_name,
                'is_active' => true,
            ]);
            $group->appProfiles()->attach($profile->id);
        }

        // Synchroniser les applications
        $profile->applications()->sync($listIdAppli);

        Log::info('LegacyParcBridge: Applications synchronisées pour parc', [
            'nom' => $nomEntite,
            'nb_apps' => count($listIdAppli),
        ]);

        return true;
    }

    /**
     * Synchronise les données depuis la table legacy 'parc' vers workstation_groups.
     * À utiliser pour la migration initiale.
     * 
     * @return array Statistiques de synchronisation
     */
    public function syncFromLegacyParc(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            // Lire les données de la table legacy
            $legacyParcs = DB::table('parc')->get();

            foreach ($legacyParcs as $legacy) {
                try {
                    $existing = WorkstationGroup::where('name', $legacy->nom_parc)->first();

                    if ($existing) {
                        // Mettre à jour
                        $existing->update([
                            'display_name' => $legacy->nom_parc_wpkg ?? $legacy->nom_parc,
                            'ad_guid' => $legacy->ad_guid_cn ?? $legacy->uuid ?? null,
                        ]);
                        $stats['updated']++;
                    } else {
                        // Créer
                        WorkstationGroup::create([
                            'name' => $legacy->nom_parc,
                            'display_name' => $legacy->nom_parc_wpkg ?? $legacy->nom_parc,
                            'ad_guid' => $legacy->ad_guid_cn ?? $legacy->uuid ?? null,
                            'is_physical' => (bool) ($legacy->is_physical ?? false),
                            'is_active' => true,
                        ]);
                        $stats['created']++;
                    }
                } catch (\Exception $e) {
                    Log::error('LegacyParcBridge: Erreur sync parc', [
                        'nom_parc' => $legacy->nom_parc ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                    $stats['errors']++;
                }
            }

            // Synchroniser les associations parc_profile
            $this->syncParcProfileFromLegacy();

        } catch (\Exception $e) {
            Log::error('LegacyParcBridge: Erreur sync globale', ['error' => $e->getMessage()]);
            $stats['errors']++;
        }

        Log::info('LegacyParcBridge: Sync depuis legacy terminée', $stats);

        return $stats;
    }

    /**
     * Synchronise les associations poste-parc depuis la table legacy.
     */
    protected function syncParcProfileFromLegacy(): void
    {
        $legacyProfiles = DB::table('parc_profile')
            ->join('parc', 'parc_profile.id_parc', '=', 'parc.id_parc')
            ->select('parc_profile.id_poste', 'parc.nom_parc')
            ->get();

        foreach ($legacyProfiles as $profile) {
            $group = WorkstationGroup::where('name', $profile->nom_parc)->first();
            
            if ($group && !$group->workstations()->where('workstation_id', $profile->id_poste)->exists()) {
                try {
                    $group->workstations()->attach($profile->id_poste);
                } catch (\Exception $e) {
                    Log::debug('LegacyParcBridge: Erreur attach poste', [
                        'id_poste' => $profile->id_poste,
                        'parc' => $profile->nom_parc,
                    ]);
                }
            }
        }
    }

    /**
     * Synchronise les données vers la table legacy 'parc'.
     * À utiliser pour maintenir la compatibilité avec le code legacy.
     * 
     * @return array Statistiques de synchronisation
     */
    public function syncToLegacyParc(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'errors' => 0];

        try {
            $groups = WorkstationGroup::all();

            foreach ($groups as $group) {
                try {
                    $existing = DB::table('parc')->where('nom_parc', $group->name)->first();

                    $data = [
                        'nom_parc' => $group->name,
                        'nom_parc_wpkg' => $group->display_name,
                        'ad_guid_cn' => $group->ad_guid,
                        'description' => $group->description,
                        'is_physical' => $group->is_physical,
                        'updated_at' => now(),
                    ];

                    if ($existing) {
                        DB::table('parc')->where('id_parc', $existing->id_parc)->update($data);
                        $stats['updated']++;
                    } else {
                        $data['created_at'] = now();
                        DB::table('parc')->insert($data);
                        $stats['created']++;
                    }
                } catch (\Exception $e) {
                    Log::error('LegacyParcBridge: Erreur sync vers legacy', [
                        'name' => $group->name,
                        'error' => $e->getMessage(),
                    ]);
                    $stats['errors']++;
                }
            }

        } catch (\Exception $e) {
            Log::error('LegacyParcBridge: Erreur sync vers legacy globale', ['error' => $e->getMessage()]);
            $stats['errors']++;
        }

        Log::info('LegacyParcBridge: Sync vers legacy terminée', $stats);

        return $stats;
    }

    /**
     * Vide le cache APCu des données WPKG (comme le fait le legacy).
     */
    public function clearWpkgCache(): void
    {
        if (function_exists('apcu_delete')) {
            // Pattern utilisé par le legacy
            $iterator = new \APCUIterator('/^wpkg_/');
            foreach ($iterator as $item) {
                apcu_delete($item['key']);
            }
        }
    }
}
