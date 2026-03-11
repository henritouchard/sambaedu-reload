<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WorkstationGroup;
use App\Services\GpoSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job asynchrone pour la synchronisation GPO
 * 
 * Déclenché quand une délégation computer.elevate est accordée ou révoquée.
 * Exécute la synchronisation GPO en arrière-plan pour ne pas bloquer l'UI.
 */
class SyncGpoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        private int $userId,
        private int $workstationGroupId,
        private string $action // 'grant' ou 'revoke'
    ) {
    }

    public function handle(GpoSyncService $gpoSyncService): void
    {
        $user = User::find($this->userId);
        $group = WorkstationGroup::find($this->workstationGroupId);

        if (!$user || !$group) {
            Log::warning('[SyncGpoJob] Utilisateur ou groupe introuvable', [
                'user_id' => $this->userId,
                'workstation_group_id' => $this->workstationGroupId,
            ]);
            return;
        }

        $result = match ($this->action) {
            'grant' => $gpoSyncService->syncGpoForGrant($user, $group),
            'revoke' => $gpoSyncService->syncGpoForRevoke($user, $group),
            default => false,
        };

        if (!$result) {
            Log::warning('[SyncGpoJob] Synchronisation GPO échouée', [
                'user' => $user->login,
                'group' => $group->name,
                'action' => $this->action,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[SyncGpoJob] Job échoué définitivement', [
            'user_id' => $this->userId,
            'workstation_group_id' => $this->workstationGroupId,
            'action' => $this->action,
            'error' => $exception->getMessage(),
        ]);
    }
}
