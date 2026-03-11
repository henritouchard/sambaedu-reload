<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\AdSync\WorkstationGroupAdSyncJob;
use App\Models\AppProfile;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Log;

/**
 * Observer pour synchroniser automatiquement les WorkstationGroup vers l'AD
 * 
 * Déclenche les jobs de synchronisation lors des opérations CRUD sur les groupes.
 * Gère également la création/renommage/suppression des AppProfiles associés.
 */
class WorkstationGroupObserver
{
    /**
     * Indique si la synchronisation AD est activée
     * Peut être désactivée temporairement pour les imports en masse
     */
    public static bool $syncEnabled = true;

    /**
     * Désactive temporairement la synchronisation AD
     */
    public static function disableSync(): void
    {
        self::$syncEnabled = false;
    }

    /**
     * Réactive la synchronisation AD
     */
    public static function enableSync(): void
    {
        self::$syncEnabled = true;
    }

    /**
     * Appelé après la création d'un WorkstationGroup
     */
    public function created(WorkstationGroup $group): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        Log::debug('[WorkstationGroupObserver] Groupe créé', [
            'id' => $group->id,
            'name' => $group->name,
            'app_profile_name' => $group->app_profile_name
        ]);

        // Créer un AppProfile si app_profile_name est rempli
        if (!empty($group->app_profile_name)) {
            $this->createAssociatedAppProfile($group);
        }

        // Dispatch le job de sync AD pour le WorkstationGroup
        dispatch(WorkstationGroupAdSyncJob::create($group->id));
    }

    /**
     * Appelé après la mise à jour d'un WorkstationGroup
     */
    public function updated(WorkstationGroup $group): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        // Vérifier si le nom a changé
        if ($group->isDirty('name')) {
            $oldName = $group->getOriginal('name');
            $newName = $group->name;

            // Renommer le AppProfile associé si il porte le même nom que l'ancien nom du groupe
            $this->renameAssociatedAppProfile($oldName, $newName);

            // Dispatch le job de renommage AD
            dispatch(WorkstationGroupAdSyncJob::rename($group->id, $oldName, $newName));
        }

        // Vérifier si app_profile_name a changé
        if ($group->isDirty('app_profile_name')) {
            $oldProfileName = $group->getOriginal('app_profile_name');
            $newProfileName = $group->app_profile_name;

            // Si on ajoute un nouveau nom de profil (était vide, maintenant rempli)
            if (empty($oldProfileName) && !empty($newProfileName)) {
                $this->createAppProfileByName($newProfileName, $group);
            }
            // Si on change le nom du profil
            elseif (!empty($oldProfileName) && !empty($newProfileName) && $oldProfileName !== $newProfileName) {
                $this->renameAssociatedAppProfile($oldProfileName, $newProfileName);
            }
            // Si on retire le profil (était rempli, maintenant vide) - on ne supprime pas le profil
        }

        // Vérifier si le parent a changé (déplacement)
        if ($group->isDirty('parent_id')) {
            $oldParentId = $group->getOriginal('parent_id');
            $newParentId = $group->parent_id;

            Log::debug('[WorkstationGroupObserver] Groupe déplacé, dispatch move job', [
                'id' => $group->id,
                'name' => $group->name,
                'old_parent_id' => $oldParentId,
                'new_parent_id' => $newParentId
            ]);

            dispatch(WorkstationGroupAdSyncJob::move($group->id, $oldParentId, $newParentId));
        }

        // Si le groupe n'a pas encore de GUID AD, le créer
        if (!$group->ad_guid && !$group->isDirty('ad_guid')) {
            Log::debug('[WorkstationGroupObserver] Groupe sans GUID AD, dispatch sync job', [
                'id' => $group->id,
                'name' => $group->name
            ]);

            dispatch(WorkstationGroupAdSyncJob::create($group->id));
        }
    }

    /**
     * Appelé avant la suppression d'un WorkstationGroup
     * 
     * Note: On utilise "deleting" pour capturer les données avant suppression
     */
    public function deleting(WorkstationGroup $group): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        Log::debug('[WorkstationGroupObserver] Groupe en cours de suppression, dispatch delete job', [
            'id' => $group->id,
            'name' => $group->name
        ]);

        // Note: On conserve l'AppProfile lors de la suppression du groupe
        // On pourrait imaginer supprimer l'AppProfile si aucun autre groupe 
        // ne l'utilise mais ça pourrait être confusant pour l'utilisateur qui 
        // supprime une salle pour la remplacer par une autre et devrait refaire le wpkg. 

        // Dispatcher le job avec les données nécessaires (le modèle sera supprimé)
        dispatch(WorkstationGroupAdSyncJob::delete(
            $group->name,
            $group->ad_guid,
            $group->is_physical
        ));
    }

    // ========================================================================
    // GESTION AUTOMATIQUE DES APPPROFILES
    // ========================================================================

    /**
     * Crée un AppProfile associé au groupe
     * 
     * Appelé uniquement si app_profile_name est rempli
     * Note: L'AppProfileObserver se charge de la synchronisation AD
     */
    private function createAssociatedAppProfile(WorkstationGroup $group): void
    {
        $profileName = $group->app_profile_name;
        
        // Vérifier si un AppProfile du même nom existe déjà
        $existingProfile = AppProfile::where('name', $profileName)->first();
        
        if ($existingProfile) {
            Log::debug('[WorkstationGroupObserver] AppProfile existe déjà', [
                'name' => $profileName,
                'profile_id' => $existingProfile->id
            ]);
            return;
        }

        // Créer le AppProfile (l'AppProfileObserver gère la sync AD)
        $appProfile = AppProfile::create([
            'name' => $profileName,
            'display_name' => $profileName,
            'description' => "Profil applicatif créé pour le groupe {$group->name}",
            'is_active' => true,
        ]);

        // Créer le lien dans la table pivot
        $group->appProfiles()->attach($appProfile->id);

        Log::info('[WorkstationGroupObserver] AppProfile créé et lié', [
            'workstation_group_id' => $group->id,
            'workstation_group_name' => $group->name,
            'app_profile_name' => $profileName,
            'app_profile_id' => $appProfile->id
        ]);
    }

    /**
     * Crée un AppProfile par son nom
     */
    private function createAppProfileByName(string $profileName, WorkstationGroup $group): void
    {
        // Vérifier si un AppProfile du même nom existe déjà
        $existingProfile = AppProfile::where('name', $profileName)->first();
        
        if ($existingProfile) {
            // Lier le profil existant au groupe s'il n'est pas déjà lié
            if (!$group->appProfiles()->where('app_profiles.id', $existingProfile->id)->exists()) {
                $group->appProfiles()->attach($existingProfile->id);
                Log::debug('[WorkstationGroupObserver] AppProfile existant lié au groupe', [
                    'name' => $profileName,
                    'profile_id' => $existingProfile->id,
                    'group_id' => $group->id
                ]);
            }
            return;
        }

        // Créer le AppProfile (l'AppProfileObserver gère la sync AD)
        $appProfile = AppProfile::create([
            'name' => $profileName,
            'display_name' => $profileName,
            'description' => "Profil applicatif créé pour le groupe {$group->name}",
            'is_active' => true,
        ]);

        // Créer le lien dans la table pivot
        $group->appProfiles()->attach($appProfile->id);

        Log::info('[WorkstationGroupObserver] AppProfile créé et lié', [
            'workstation_group_id' => $group->id,
            'workstation_group_name' => $group->name,
            'app_profile_name' => $profileName,
            'app_profile_id' => $appProfile->id
        ]);
    }

    /**
     * Renomme un AppProfile si il porte le même nom
     * 
     * Note: L'AppProfileObserver se charge de la synchronisation AD
     */
    private function renameAssociatedAppProfile(string $oldName, string $newName): void
    {
        $appProfile = AppProfile::where('name', $oldName)->first();
        
        if (!$appProfile) {
            Log::debug('[WorkstationGroupObserver] Pas de AppProfile à renommer', [
                'old_name' => $oldName
            ]);
            return;
        }

        // Vérifier qu'un AppProfile avec le nouveau nom n'existe pas déjà
        $existingNewProfile = AppProfile::where('name', $newName)->first();
        if ($existingNewProfile) {
            Log::warning('[WorkstationGroupObserver] Un AppProfile avec le nouveau nom existe déjà', [
                'old_name' => $oldName,
                'new_name' => $newName,
                'existing_profile_id' => $existingNewProfile->id
            ]);
            return;
        }

        // Renommer le AppProfile (l'AppProfileObserver gère la sync AD)
        $appProfile->update([
            'name' => $newName,
            'display_name' => $newName,
        ]);

        Log::info('[WorkstationGroupObserver] AppProfile renommé', [
            'old_name' => $oldName,
            'new_name' => $newName,
            'app_profile_id' => $appProfile->id
        ]);
    }

    /**
     * Supprime un AppProfile par son nom
     * 
     * Note: L'AppProfileObserver se charge de la synchronisation AD
     */
    private function deleteAssociatedAppProfile(string $name): void
    {
        $appProfile = AppProfile::where('name', $name)->first();
        
        if (!$appProfile) {
            Log::debug('[WorkstationGroupObserver] Pas de AppProfile à supprimer', [
                'name' => $name
            ]);
            return;
        }

        $profileId = $appProfile->id;
        
        // Supprimer le AppProfile (l'AppProfileObserver gère la sync AD)
        $appProfile->delete();

        Log::info('[WorkstationGroupObserver] AppProfile supprimé', [
            'name' => $name,
            'app_profile_id' => $profileId
        ]);
    }
}
