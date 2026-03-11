<?php

declare(strict_types=1);

namespace App\Jobs\AdSync;

use App\Models\AppProfile;
use App\Services\AdSync\AppProfileAdSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job unifié pour la synchronisation des AppProfiles vers l'AD
 * 
 * Un AppProfile correspond à un groupe CN dans OU=Parcs.
 * 
 * Actions supportées :
 * - create : Crée le groupe CN dans l'AD
 * - rename : Renomme le groupe CN dans l'AD
 * - delete : Supprime le groupe CN de l'AD
 */
class AppProfileAdSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public const ACTION_CREATE = 'create';
    public const ACTION_RENAME = 'rename';
    public const ACTION_DELETE = 'delete';

    public function __construct(
        public int|string $appProfileId,
        public string $action,
        public array $params = []
    ) {
    }

    // ========================================================================
    // FACTORY METHODS
    // ========================================================================

    public static function create(int $appProfileId): self
    {
        return new self($appProfileId, self::ACTION_CREATE);
    }

    public static function rename(int $appProfileId, string $oldName, string $newName): self
    {
        return new self($appProfileId, self::ACTION_RENAME, [
            'old_name' => $oldName,
            'new_name' => $newName,
        ]);
    }

    public static function delete(string $profileName, ?string $adGuid = null): self
    {
        return new self($profileName, self::ACTION_DELETE, [
            'name' => $profileName,
            'ad_guid' => $adGuid,
        ]);
    }

    // ========================================================================
    // HANDLER
    // ========================================================================

    public function handle(AppProfileAdSyncService $syncService): void
    {
        Log::info('[AppProfileAdSyncJob] Début', [
            'action' => $this->action,
            'id' => $this->appProfileId,
            'params' => $this->params,
        ]);

        $result = match ($this->action) {
            self::ACTION_CREATE => $this->handleCreate($syncService),
            self::ACTION_RENAME => $this->handleRename($syncService),
            self::ACTION_DELETE => $this->handleDelete($syncService),
            default => ['success' => false, 'error' => "Action inconnue: {$this->action}"],
        };

        if (!$result['success']) {
            Log::error('[AppProfileAdSyncJob] Échec', [
                'action' => $this->action,
                'id' => $this->appProfileId,
                'error' => $result['error'],
            ]);
            throw new \RuntimeException($result['error'] ?? 'Erreur inconnue');
        }

        Log::info('[AppProfileAdSyncJob] Succès', [
            'action' => $this->action,
            'id' => $this->appProfileId,
        ]);
    }

    // ========================================================================
    // ACTION HANDLERS
    // ========================================================================

    private function handleCreate(AppProfileAdSyncService $syncService): array
    {
        $appProfile = $this->findAppProfile();
        if (!$appProfile) {
            return ['success' => false, 'error' => 'AppProfile non trouvé'];
        }

        if ($appProfile->ad_guid) {
            Log::debug('[AppProfileAdSyncJob] AppProfile déjà synchronisé', [
                'id' => $appProfile->id,
                'ad_guid' => $appProfile->ad_guid,
            ]);
            return ['success' => true];
        }

        $result = $syncService->createAppProfile($appProfile);

        if ($result['success'] && !empty($result['guid'])) {
            $appProfile->ad_guid = $result['guid'];
            $appProfile->save();
        }

        return $result;
    }

    private function handleRename(AppProfileAdSyncService $syncService): array
    {
        $appProfile = $this->findAppProfile();
        if (!$appProfile) {
            return ['success' => false, 'error' => 'AppProfile non trouvé'];
        }

        $oldName = $this->params['old_name'] ?? '';
        $newName = $this->params['new_name'] ?? '';

        if (empty($oldName) || empty($newName)) {
            return ['success' => false, 'error' => 'Paramètres old_name et new_name requis'];
        }

        return $syncService->renameAppProfile($oldName, $newName);
    }

    private function handleDelete(AppProfileAdSyncService $syncService): array
    {
        $name = $this->params['name'] ?? (is_string($this->appProfileId) ? $this->appProfileId : '');
        $adGuid = $this->params['ad_guid'] ?? null;

        if (empty($name)) {
            return ['success' => false, 'error' => 'Paramètre name requis pour la suppression'];
        }

        return $syncService->deleteAppProfile($name);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function findAppProfile(): ?AppProfile
    {
        if (!is_int($this->appProfileId)) {
            return null;
        }
        return AppProfile::find($this->appProfileId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[AppProfileAdSyncJob] Job échoué définitivement', [
            'action' => $this->action,
            'id' => $this->appProfileId,
            'params' => $this->params,
            'error' => $exception->getMessage(),
        ]);
    }
}
