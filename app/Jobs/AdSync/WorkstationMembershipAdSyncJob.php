<?php

declare(strict_types=1);

namespace App\Jobs\AdSync;

use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\AdSync\AdSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job pour déplacer une machine vers une salle (OU) dans l'AD
 * 
 * NOTE: Les actions add/remove ont été supprimées.
 * L'appartenance des machines aux groupes (parcs) est maintenant gérée uniquement en SQL.
 * Seul le déplacement physique d'une machine vers une salle (OU) reste synchronisé vers AD.
 */
class WorkstationMembershipAdSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public const ACTION_MOVE = 'move';

    public function __construct(
        public int $workstationId,
        public int $targetSalleId,
        public string $action = self::ACTION_MOVE
    ) {
    }

    // ========================================================================
    // FACTORY METHOD
    // ========================================================================

    public static function move(int $workstationId, int $targetSalleId): self
    {
        return new self($workstationId, $targetSalleId, self::ACTION_MOVE);
    }

    // ========================================================================
    // HANDLER
    // ========================================================================

    public function handle(AdSyncService $adSyncService): void
    {
        $workstation = Workstation::find($this->workstationId);
        $targetSalle = WorkstationGroup::find($this->targetSalleId);

        if (!$workstation) {
            Log::warning('[WorkstationMembershipAdSyncJob] Machine non trouvée', [
                'id' => $this->workstationId,
            ]);
            return;
        }

        if (!$targetSalle) {
            Log::warning('[WorkstationMembershipAdSyncJob] Salle cible non trouvée', [
                'id' => $this->targetSalleId,
            ]);
            return;
        }

        Log::info('[WorkstationMembershipAdSyncJob] Déplacement machine vers salle', [
            'machine' => $workstation->name,
            'target_salle' => $targetSalle->name,
        ]);

        $result = $adSyncService->moveMachineToSalle($workstation, $targetSalle);

        if ($result['success']) {
            Log::info('[WorkstationMembershipAdSyncJob] Succès', [
                'machine' => $workstation->name,
                'target_salle' => $targetSalle->name,
            ]);
        } else {
            Log::error('[WorkstationMembershipAdSyncJob] Échec', [
                'machine' => $workstation->name,
                'target_salle' => $targetSalle->name,
                'error' => $result['error'],
            ]);
            throw new \RuntimeException($result['error'] ?? 'Erreur inconnue');
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[WorkstationMembershipAdSyncJob] Job échoué définitivement', [
            'workstation_id' => $this->workstationId,
            'target_salle_id' => $this->targetSalleId,
            'error' => $exception->getMessage(),
        ]);
    }
}
