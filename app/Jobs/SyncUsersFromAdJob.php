<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\UserSyncService;
use App\Services\UserGroupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncUsersFromAdJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 300;
    public int $uniqueFor = 3600;

    public function __construct(
        public string $scope = 'all',
        public string $mode = 'delta',
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return sprintf('users-sync:%s:%s', $this->scope, $this->mode);
    }

    public function handle(UserSyncService $userSyncService, UserGroupService $userGroupService): void
    {
        Log::info('[SyncUsersFromAdJob] Démarrage de la synchronisation AD -> SQL (users + groups)', [
            'scope' => $this->scope,
            'mode' => $this->mode,
        ]);

        // 1. Sync user_groups d'abord (pour éviter FK violations)
        Log::info('[SyncUsersFromAdJob] Étape 1/2: Synchronisation des groupes utilisateurs');
        $groupStats = $userGroupService->syncFromAd();
        Log::info('[SyncUsersFromAdJob] Groupes synchronisés', $groupStats);

        // 2. Sync users ensuite
        Log::info('[SyncUsersFromAdJob] Étape 2/2: Synchronisation des utilisateurs');
        $userStats = $this->mode === 'delta'
            ? $userSyncService->importFromAdDelta(establishmentScope: $this->scope)
            : $userSyncService->importFromAd(establishmentScope: $this->scope);

        Log::info('[SyncUsersFromAdJob] Synchronisation terminée', [
            'groups' => $groupStats,
            'users' => $userStats,
        ]);
    }
}
