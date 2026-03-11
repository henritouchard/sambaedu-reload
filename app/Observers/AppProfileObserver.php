<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\AdSync\AppProfileAdSyncJob;
use App\Models\AppProfile;
use Illuminate\Support\Facades\Log;

/**
 * Observer pour synchroniser automatiquement les AppProfiles vers l'AD
 * 
 * Un AppProfile correspond à un groupe CN dans OU=Parcs de l'AD.
 * Cet Observer déclenche les jobs de synchronisation lors des opérations CRUD.
 */
class AppProfileObserver
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
     * Exécute un callback sans synchronisation AD
     */
    public static function withoutSync(callable $callback): mixed
    {
        $wasEnabled = self::$syncEnabled;
        self::$syncEnabled = false;

        try {
            return $callback();
        } finally {
            self::$syncEnabled = $wasEnabled;
        }
    }

    /**
     * Appelé après la création d'un AppProfile
     */
    public function created(AppProfile $appProfile): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        Log::debug('[AppProfileObserver] AppProfile créé', [
            'id' => $appProfile->id,
            'name' => $appProfile->name
        ]);

        // Dispatch le job de sync AD pour créer le groupe CN dans OU=Parcs
        dispatch(AppProfileAdSyncJob::create($appProfile->id));
    }

    /**
     * Appelé après la mise à jour d'un AppProfile
     */
    public function updated(AppProfile $appProfile): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        // Vérifier si le nom a changé
        if ($appProfile->isDirty('name')) {
            $oldName = $appProfile->getOriginal('name');
            $newName = $appProfile->name;

            Log::debug('[AppProfileObserver] AppProfile renommé', [
                'id' => $appProfile->id,
                'old_name' => $oldName,
                'new_name' => $newName
            ]);

            // Dispatch le job de renommage AD
            dispatch(AppProfileAdSyncJob::rename($appProfile->id, $oldName, $newName));
        }

        // Si le AppProfile n'a pas encore de GUID AD, le créer
        if (!$appProfile->ad_guid && !$appProfile->isDirty('ad_guid')) {
            Log::debug('[AppProfileObserver] AppProfile sans GUID AD, dispatch sync job', [
                'id' => $appProfile->id,
                'name' => $appProfile->name
            ]);

            dispatch(AppProfileAdSyncJob::create($appProfile->id));
        }
    }

    /**
     * Appelé avant la suppression d'un AppProfile
     * 
     * Note: On utilise "deleting" pour capturer les données avant suppression
     */
    public function deleting(AppProfile $appProfile): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        Log::debug('[AppProfileObserver] AppProfile en cours de suppression', [
            'id' => $appProfile->id,
            'name' => $appProfile->name
        ]);

        // Dispatcher le job avec les données nécessaires (le modèle sera supprimé)
        dispatch(AppProfileAdSyncJob::delete(
            $appProfile->name,
            $appProfile->ad_guid
        ));
    }
}
