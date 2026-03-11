<?php

namespace App\Console\Commands;

use App\Services\QuotaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Commande pour rafraîchir le cache des informations de quotas
 * 
 * Cette commande est exécutée périodiquement par le scheduler Laravel
 * pour maintenir à jour les informations des partitions.
 */
class RefreshQuotaCacheCommand extends Command
{
    protected $signature = 'quota:refresh-cache 
                            {--partition= : Partition spécifique à rafraîchir}';

    protected $description = 'Rafraîchit le cache des informations de quotas des partitions';

    public function handle(QuotaService $quotaService): int
    {
        $this->info('Rafraîchissement du cache des quotas...');

        $partitions = $this->option('partition')
            ? [$this->option('partition')]
            : array_keys($quotaService->getSupportedPartitions());

        foreach ($partitions as $partition) {
            $this->line("  - Partition: {$partition}");

            try {
                // Invalider le cache existant
                Cache::forget('quota_partition_info_' . md5($partition));

                // Recharger les infos
                $info = $quotaService->getPartitionInfo($partition);

                $this->line("    État: " . ($info['enabled'] ? 'Activé' : 'Désactivé'));
                $this->line("    Grâce: {$info['grace_days']} jours");

            } catch (\Throwable $e) {
                $this->error("    Erreur: {$e->getMessage()}");
                Log::error('RefreshQuotaCacheCommand: Erreur', [
                    'partition' => $partition,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info('Cache des quotas rafraîchi.');

        return self::SUCCESS;
    }
}
