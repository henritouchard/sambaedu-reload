<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\AdSync\WorkstationGroupAdSyncJob;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Log;

/**
 * Observer de synchronisation SQL → AD des WorkstationGroup.
 *
 * ── Asymétrie `OU=Parcs` / `OU=Computers` (Story 38.7) ───────────────────────
 * `OU=Parcs` est devenu un vestige SE4 en LECTURE SEULE : on le LIT à l'import
 * de migration (`sync-from-ad`), on n'y ÉCRIT plus rien. Les groupes LOGIQUES
 * (`is_physical = false`) sont purement SQL — cet observer ne dispatche donc
 * AUCUN job pour eux.
 *
 * Seuls les groupes PHYSIQUES (`is_physical = true`) restent synchronisés, et
 * uniquement vers leur `OU` sous `OU=Computers` (là où sont rangées les machines
 * et où sont liées les GPO — l'unique invariant AD à préserver). Le miroir `CN`
 * de ces salles dans `OU=Parcs` n'est plus écrit (cf. {@see \App\Services\AdSync\AdSyncService}).
 *
 * La création automatique d'`AppProfile` (profil WPKG) à la création d'un groupe
 * a été RETIRÉE en 38.7 : un profil se crée désormais dans /parc-settings/profiles
 * et s'attache explicitement — un profil est réutilisable entre parcs. La colonne
 * `workstation_groups.app_profile_name` subsiste mais est inerte.
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
     * Appelé après la création d'un WorkstationGroup.
     *
     * Groupe logique ⇒ aucune écriture AD (SQL-only). Groupe physique ⇒ création
     * de l'`OU` sous `OU=Computers`.
     */
    public function created(WorkstationGroup $group): void
    {
        if (! self::$syncEnabled || ! $group->is_physical) {
            return;
        }

        Log::debug('[WorkstationGroupObserver] Groupe physique créé, dispatch create job', [
            'id' => $group->id,
            'name' => $group->name,
        ]);

        dispatch(WorkstationGroupAdSyncJob::create($group->id));
    }

    /**
     * Appelé après la mise à jour d'un WorkstationGroup.
     *
     * Tous les dispatches sont gardés par `is_physical` : un groupe logique ne
     * produit jamais d'écriture AD.
     */
    public function updated(WorkstationGroup $group): void
    {
        if (! self::$syncEnabled || ! $group->is_physical) {
            return;
        }

        // Renommage → renommer l'OU sous OU=Computers
        if ($group->isDirty('name')) {
            $oldName = $group->getOriginal('name');
            $newName = $group->name;

            dispatch(WorkstationGroupAdSyncJob::rename($group->id, $oldName, $newName));
        }

        // Déplacement → déplacer l'OU dans la hiérarchie OU=Computers
        if ($group->isDirty('parent_id')) {
            $oldParentId = $group->getOriginal('parent_id');
            $newParentId = $group->parent_id;

            Log::debug('[WorkstationGroupObserver] Groupe physique déplacé, dispatch move job', [
                'id' => $group->id,
                'name' => $group->name,
                'old_parent_id' => $oldParentId,
                'new_parent_id' => $newParentId,
            ]);

            dispatch(WorkstationGroupAdSyncJob::move($group->id, $oldParentId, $newParentId));
        }

        // Backfill : groupe physique sans GUID AD → (re)créer l'OU
        if (! $group->ad_guid && ! $group->isDirty('ad_guid')) {
            dispatch(WorkstationGroupAdSyncJob::create($group->id));
        }
    }

    /**
     * Appelé avant la suppression d'un WorkstationGroup.
     *
     * Groupe physique uniquement : suppression de l'`OU` sous `OU=Computers`.
     * Un groupe logique disparaît sans aucune écriture AD.
     */
    public function deleting(WorkstationGroup $group): void
    {
        if (! self::$syncEnabled || ! $group->is_physical) {
            return;
        }

        Log::debug('[WorkstationGroupObserver] Groupe physique en cours de suppression, dispatch delete job', [
            'id' => $group->id,
            'name' => $group->name,
        ]);

        dispatch(WorkstationGroupAdSyncJob::delete(
            $group->name,
            $group->ad_guid,
            $group->is_physical
        ));
    }
}
