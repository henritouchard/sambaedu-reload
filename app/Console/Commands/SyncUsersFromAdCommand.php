<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncUsersFromAdJob;
use App\Services\UserSyncService;
use Illuminate\Console\Command;

class SyncUsersFromAdCommand extends Command
{
    protected $signature = 'users:sync-from-ad
        {--scope=all : Scope établissement (all|tree|memberOf)}
        {--mode=delta : Mode de synchronisation (delta|full)}
        {--now : Exécute immédiatement sans passer par la queue sync}
        {--reset-delta-cursor : Réinitialise le curseur before un run delta}';

    protected $description = 'Synchronise automatiquement les utilisateurs depuis l\'AD vers SQL';

    public function handle(UserSyncService $userSyncService): int
    {
        $scope = (string) $this->option('scope');
        $mode = (string) $this->option('mode');

        if (!in_array($scope, ['all', 'tree', 'memberOf'], true)) {
            $this->error('Option --scope invalide. Valeurs acceptées: all, tree, memberOf');
            return self::FAILURE;
        }

        if (!in_array($mode, ['delta', 'full'], true)) {
            $this->error('Option --mode invalide. Valeurs acceptées: delta, full');
            return self::FAILURE;
        }

        if ((bool) $this->option('reset-delta-cursor')) {
            $userSyncService->resetDeltaCursor();
            $this->line('Curseur delta réinitialisé.');
        }

        if (! (bool) $this->option('now')) {
            SyncUsersFromAdJob::dispatch($scope, $mode);
            $this->info("Job de synchronisation users dispatché sur la queue sync (scope={$scope}, mode={$mode}).");

            return self::SUCCESS;
        }

        $this->info("Démarrage de la synchronisation users AD -> SQL (scope={$scope}, mode={$mode})...");

        try {
            $logger = function (string $level, string $message): void {
                $this->line("[{$level}] {$message}");
            };

            $stats = $mode === 'delta'
                ? $userSyncService->importFromAdDelta(
                    logger: $logger,
                    establishmentScope: $scope,
                )
                : $userSyncService->importFromAd(
                    logger: $logger,
                    establishmentScope: $scope,
                );

            $this->table(
                ['Stat', 'Valeur'],
                [
                    ['mode', (string) ($stats['delta_mode'] ?? false ? 'delta' : 'full')],
                    ['created', (string) $stats['created']],
                    ['updated', (string) $stats['updated']],
                    ['skipped', (string) $stats['skipped']],
                    ['errors', (string) $stats['errors']],
                    ['total_ad', (string) $stats['total_ad']],
                    ['etab_tree', (string) $stats['etab_tree']],
                    ['etab_member_of', (string) $stats['etab_member_of']],
                    ['etab_excluded', (string) $stats['etab_excluded']],
                    ['delta_cursor_start', (string) ($stats['delta_cursor_start'] ?? '')],
                    ['delta_cursor_end', (string) ($stats['delta_cursor_end'] ?? '')],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Échec de la synchronisation users: ' . $exception->getMessage());
            return self::FAILURE;
        }
    }
}
