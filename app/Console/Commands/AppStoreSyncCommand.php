<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AppStore\AppStoreService;
use Illuminate\Console\Command;

/**
 * Commande Artisan pour synchroniser le catalogue d'applications depuis les dépôts
 * 
 * Usage :
 *   php artisan appstore:sync          # Synchronise tous les dépôts actifs
 *   php artisan appstore:sync --force   # Force la re-synchronisation même si le hash n'a pas changé
 */
class AppStoreSyncCommand extends Command
{
    protected $signature = 'appstore:sync
        {--force : Force la re-synchronisation même si le XML n\'a pas changé}';

    protected $description = 'Synchronise le catalogue d\'applications depuis les dépôts distants';

    public function handle(AppStoreService $appStoreService): int
    {
        $this->info('Synchronisation du catalogue d\'applications...');
        $this->newLine();

        try {
            $result = $appStoreService->syncAllDepots();

            $this->info("Dépôts synchronisés : {$result['synced']}");
            $this->info("Nouvelles applications : {$result['new']}");
            $this->info("Applications mises à jour : {$result['updated']}");

            if (!empty($result['errors'])) {
                $this->newLine();
                $this->warn('Erreurs rencontrées :');
                foreach ($result['errors'] as $error) {
                    $this->error("  - {$error}");
                }
                return Command::FAILURE;
            }

            $this->newLine();
            $this->info('Synchronisation terminée avec succès.');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Erreur fatale : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
