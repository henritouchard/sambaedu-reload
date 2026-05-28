<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\AdSync\WorkstationAdSyncJob;
use App\Models\Workstation;
use Illuminate\Support\Facades\Log;

/**
 * Story 4.9 — Observer pour synchroniser automatiquement les Workstations vers l'AD.
 *
 * Pattern miroir strict de {@see WorkstationGroupObserver}.
 *
 * Hooks Eloquent :
 *  - created : dispatch ACTION_CREATE (assure existence AD + ad_guid PG).
 *  - updated : dispatch ACTION_RENAME si `isDirty('name')`,
 *              dispatch ACTION_STATUS si `isDirty('status')`.
 *  - deleting : dispatch ACTION_DELETE avant suppression PG (on a encore
 *               accès à `$ws->name`).
 *
 * Helper {@see withoutSync()} : SEUL moyen autorisé pour écrire un
 * Workstation sans déclencher le job AD (seeders, imports CSV, sync inverse
 * depuis AD).
 *
 * Décision D4 (Henri 2026-05-28) : les hooks pivot audit-only
 * (`onGroupAttached`/`onGroupDetached`/`onGroupsSynced`) ont été supprimés —
 * code mort depuis Epic 4 (sync AD machine→groupe retirée 2026-05-20).
 */
class WorkstationObserver
{
    /**
     * Flag global de synchronisation. Conservé `public` (lecture seule
     * conventionnelle) pour les tests qui doivent l'inspecter mais NE DOIT
     * PAS être écrit en dehors de {@see withoutSync()}.
     *
     * Auto-fix #10 (review 4.9, 2026-05-28) : les helpers `disableSync()` /
     * `enableSync()` sont passés `private` — l'unique API publique est
     * `withoutSync()` qui garantit la restauration du flag (try/finally-safe).
     */
    public static bool $syncEnabled = true;

    private static function disableSync(): void
    {
        self::$syncEnabled = false;
    }

    private static function enableSync(): void
    {
        self::$syncEnabled = true;
    }

    /**
     * Exécute le callback avec la synchronisation AD désactivée. Restaure
     * l'état précédent du flag même en cas d'exception.
     */
    public static function withoutSync(callable $callback): mixed
    {
        $wasEnabled = self::$syncEnabled;
        self::disableSync();

        try {
            return $callback();
        } finally {
            self::$syncEnabled = $wasEnabled;
        }
    }

    /**
     * Hook Eloquent : appelé après la création d'une Workstation.
     */
    public function created(Workstation $workstation): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        Log::debug('[WorkstationObserver] Workstation créée', [
            'id' => $workstation->id,
            'name' => $workstation->name,
        ]);

        // Auto-fix #8 (review 4.9) : `->afterCommit()` garantit que le job ne
        // soit pas exécuté avant que la transaction Eloquent englobante ne
        // soit committée (sinon `find($id)` du worker pourrait renvoyer null
        // en queue=sync ou en mode database).
        dispatch(WorkstationAdSyncJob::create((int) $workstation->id))->afterCommit();
    }

    /**
     * Hook Eloquent : appelé après une mise à jour d'une Workstation.
     *
     * Utilise `wasChanged()` car dans le hook `updated` les attributs ont déjà
     * été persistés et `isDirty()` retourne false (parité avec
     * `WorkstationGroupObserver` qui résout le même problème).
     */
    public function updated(Workstation $workstation): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        $nameChanged = $workstation->wasChanged('name');
        $statusChanged = $workstation->wasChanged('status');

        // Décision design #3 (Henri 2026-05-28) : si name ET status changent
        // dans le même `save()`, on dispatche UN SEUL job `update` (fusion
        // rename + status en une transaction LDAP). Sinon, comportement
        // standard (rename ou status indépendants).
        if ($nameChanged && $statusChanged) {
            $oldName = (string) $workstation->getOriginal('name');
            $newName = (string) $workstation->name;
            $newStatus = (string) $workstation->status;

            Log::debug('[WorkstationObserver] Workstation name+status changés, dispatch update job (fusionné)', [
                'id' => $workstation->id,
                'old_name' => $oldName,
                'new_name' => $newName,
                'status' => $newStatus,
            ]);

            dispatch(WorkstationAdSyncJob::update(
                (int) $workstation->id,
                $oldName,
                $newName,
                $newStatus,
            ))->afterCommit();

            return;
        }

        if ($nameChanged) {
            $oldName = (string) $workstation->getOriginal('name');
            $newName = (string) $workstation->name;

            Log::debug('[WorkstationObserver] Workstation renommée, dispatch rename job', [
                'id' => $workstation->id,
                'old_name' => $oldName,
                'new_name' => $newName,
            ]);

            // Auto-fix #8 : afterCommit.
            dispatch(WorkstationAdSyncJob::rename((int) $workstation->id, $oldName, $newName))->afterCommit();
        }

        if ($statusChanged) {
            Log::debug('[WorkstationObserver] Workstation status changé, dispatch status job', [
                'id' => $workstation->id,
                'status' => $workstation->status,
            ]);

            // Auto-fix #8 : afterCommit.
            dispatch(WorkstationAdSyncJob::status((int) $workstation->id, (string) $workstation->status))->afterCommit();
        }
    }

    /**
     * Hook Eloquent : appelé AVANT la suppression d'une Workstation (pour
     * avoir encore accès à `$ws->name` et `$ws->ad_guid`).
     */
    public function deleting(Workstation $workstation): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        Log::debug('[WorkstationObserver] Workstation en cours de suppression, dispatch delete job', [
            'id' => $workstation->id,
            'name' => $workstation->name,
        ]);

        // Auto-fix #8 : afterCommit (cohérence avec les autres dispatch).
        // Note : `deleting` est avant le commit, donc afterCommit garantit
        // qu'on ne supprime AD que si la row PG est bien supprimée.
        dispatch(WorkstationAdSyncJob::delete(
            (string) $workstation->name,
            $workstation->ad_guid,
        ))->afterCommit();
    }
}
