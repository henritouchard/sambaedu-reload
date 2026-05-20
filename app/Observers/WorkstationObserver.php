<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Workstation;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Log;

/**
 * Observer pour synchroniser automatiquement les Workstations vers l'AD
 */
class WorkstationObserver
{
    public static bool $syncEnabled = true;

    public static function disableSync(): void
    {
        self::$syncEnabled = false;
    }

    public static function enableSync(): void
    {
        self::$syncEnabled = true;
    }

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
     * Appelé après l'ajout d'une machine à un groupe (pivot attach).
     *
     * Audit-only depuis 2026-05-20 : la sync AD machine→groupe a été retirée
     * (décision Epic 4 — `WorkstationMembershipAdSyncJob` ne supporte plus
     * `add`/`remove`, seul `move` salle subsiste). Le pivot SQL est la source
     * de vérité unique pour l'appartenance aux groupes logiques (parcs).
     */
    public static function onGroupAttached(Workstation $workstation, int $groupId): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        $group = WorkstationGroup::find($groupId);
        if (!$group) {
            Log::warning('[WorkstationObserver] Groupe non trouvé pour attach', [
                'workstation_id' => $workstation->id,
                'group_id' => $groupId
            ]);
            return;
        }

        Log::debug('[WorkstationObserver] Machine ajoutée au groupe', [
            'machine' => $workstation->name,
            'group' => $group->name
        ]);
    }

    /**
     * Appelé après le retrait d'une machine d'un groupe (pivot detach).
     *
     * Audit-only — voir {@see onGroupAttached()} pour la note archi.
     */
    public static function onGroupDetached(Workstation $workstation, int $groupId): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        $group = WorkstationGroup::find($groupId);
        if (!$group) {
            Log::warning('[WorkstationObserver] Groupe non trouvé pour detach', [
                'workstation_id' => $workstation->id,
                'group_id' => $groupId
            ]);
            return;
        }

        Log::debug('[WorkstationObserver] Machine retirée du groupe', [
            'machine' => $workstation->name,
            'group' => $group->name
        ]);
    }

    /**
     * Appelé après la synchronisation des groupes (pivot sync)
     */
    public static function onGroupsSynced(Workstation $workstation, array $changes): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        foreach ($changes['attached'] ?? [] as $groupId) {
            self::onGroupAttached($workstation, (int) $groupId);
        }

        foreach ($changes['detached'] ?? [] as $groupId) {
            self::onGroupDetached($workstation, (int) $groupId);
        }
    }
}
