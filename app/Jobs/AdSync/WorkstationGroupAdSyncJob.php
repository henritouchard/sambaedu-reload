<?php

declare(strict_types=1);

namespace App\Jobs\AdSync;

use App\Models\WorkstationGroup;
use App\Services\AdSync\AdSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job de synchronisation SQL → AD des WorkstationGroup PHYSIQUES.
 *
 * Story 38.7 — n'est dispatché QUE pour les groupes physiques (`is_physical =
 * true`) : l'observer filtre en amont. Il n'écrit plus que l'`OU` de la salle
 * sous `OU=Computers` (rangement des machines + liens GPO — l'unique invariant
 * AD). `OU=Parcs` est en LECTURE SEULE : on l'y LIT à l'import de migration, on
 * n'y ÉCRIT plus rien (ni parcs logiques, ni miroir CN des salles, ni profils
 * applicatifs). Les groupes logiques sont purement SQL et ne produisent aucun job.
 *
 * Actions supportées :
 * - create : Crée l'OU de la salle dans OU=Computers
 * - rename : Renomme l'OU
 * - move   : Déplace l'OU vers un nouveau parent
 * - delete : Supprime l'OU
 */
class WorkstationGroupAdSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public const ACTION_CREATE = 'create';
    public const ACTION_RENAME = 'rename';
    public const ACTION_MOVE = 'move';
    public const ACTION_DELETE = 'delete';

    public function __construct(
        public int|string $workstationGroupId,
        public string $action,
        public array $params = []
    ) {
    }

    // ========================================================================
    // FACTORY METHODS
    // ========================================================================

    public static function create(int $workstationGroupId): self
    {
        return new self($workstationGroupId, self::ACTION_CREATE);
    }

    public static function rename(int $workstationGroupId, string $oldName, string $newName): self
    {
        return new self($workstationGroupId, self::ACTION_RENAME, [
            'old_name' => $oldName,
            'new_name' => $newName,
        ]);
    }

    public static function move(int $workstationGroupId, ?int $oldParentId, ?int $newParentId): self
    {
        return new self($workstationGroupId, self::ACTION_MOVE, [
            'old_parent_id' => $oldParentId,
            'new_parent_id' => $newParentId,
        ]);
    }

    public static function delete(string $groupName, ?string $adGuid = null, bool $isPhysical = true): self
    {
        return new self($groupName, self::ACTION_DELETE, [
            'name' => $groupName,
            'ad_guid' => $adGuid,
            'is_physical' => $isPhysical,
        ]);
    }

    // ========================================================================
    // HANDLER
    // ========================================================================

    public function handle(AdSyncService $adSyncService): void
    {
        Log::info('[WorkstationGroupAdSyncJob] Début', [
            'action' => $this->action,
            'id' => $this->workstationGroupId,
            'params' => $this->params,
        ]);

        $result = match ($this->action) {
            self::ACTION_CREATE => $this->handleCreate($adSyncService),
            self::ACTION_RENAME => $this->handleRename($adSyncService),
            self::ACTION_MOVE => $this->handleMove($adSyncService),
            self::ACTION_DELETE => $this->handleDelete($adSyncService),
            default => ['success' => false, 'error' => "Action inconnue: {$this->action}"],
        };

        if (!$result['success']) {
            Log::error('[WorkstationGroupAdSyncJob] Échec', [
                'action' => $this->action,
                'id' => $this->workstationGroupId,
                'error' => $result['error'],
            ]);
            throw new \RuntimeException($result['error'] ?? 'Erreur inconnue');
        }

        Log::info('[WorkstationGroupAdSyncJob] Succès', [
            'action' => $this->action,
            'id' => $this->workstationGroupId,
        ]);
    }

    // ========================================================================
    // ACTION HANDLERS
    // ========================================================================

    private function handleCreate(AdSyncService $adSyncService): array
    {
        $group = $this->findGroup();
        if (!$group) {
            return ['success' => false, 'error' => 'Groupe non trouvé'];
        }

        // Toujours tenter de créer/récupérer l'OU dans AD
        // Le service vérifie si l'OU existe déjà et retourne son GUID
        $result = $adSyncService->createWorkstationGroup($group);

        if ($result['success']) {
            $group->ad_guid = $result['guid'];
            $group->ad_dn = $result['dn'] ?? null;
            $group->save();
            
            Log::debug('[WorkstationGroupAdSyncJob] Groupe synchronisé', [
                'id' => $group->id,
                'ad_guid' => $result['guid'],
                'ad_dn' => $result['dn'] ?? null,
            ]);
        }

        return $result;
    }

    private function handleRename(AdSyncService $adSyncService): array
    {
        $group = $this->findGroup();
        if (!$group) {
            return ['success' => false, 'error' => 'Groupe non trouvé'];
        }

        $oldName = $this->params['old_name'] ?? '';
        $newName = $this->params['new_name'] ?? '';

        if (empty($oldName) || empty($newName)) {
            return ['success' => false, 'error' => 'Paramètres old_name et new_name requis'];
        }

        return $adSyncService->renameWorkstationGroup($group, $oldName, $newName);
    }

    private function handleMove(AdSyncService $adSyncService): array
    {
        $group = $this->findGroup();
        if (!$group) {
            return ['success' => false, 'error' => 'Groupe non trouvé'];
        }

        $newParentId = $this->params['new_parent_id'] ?? null;
        $newParent = $newParentId ? WorkstationGroup::find($newParentId) : null;

        return $adSyncService->moveWorkstationGroup($group, $newParent);
    }

    private function handleDelete(AdSyncService $adSyncService): array
    {
        $name = $this->params['name'] ?? (is_string($this->workstationGroupId) ? $this->workstationGroupId : '');
        $adGuid = $this->params['ad_guid'] ?? null;
        $isPhysical = $this->params['is_physical'] ?? true;

        if (empty($name)) {
            return ['success' => false, 'error' => 'Paramètre name requis pour la suppression'];
        }

        return $adSyncService->deleteWorkstationGroupByName($name, $adGuid, $isPhysical);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function findGroup(): ?WorkstationGroup
    {
        if (!is_int($this->workstationGroupId)) {
            return null;
        }
        return WorkstationGroup::find($this->workstationGroupId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[WorkstationGroupAdSyncJob] Job échoué définitivement', [
            'action' => $this->action,
            'id' => $this->workstationGroupId,
            'params' => $this->params,
            'error' => $exception->getMessage(),
        ]);
    }
}
