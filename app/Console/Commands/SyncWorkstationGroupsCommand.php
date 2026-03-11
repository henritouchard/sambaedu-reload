<?php

namespace App\Console\Commands;

use App\Jobs\SyncWorkstationGroupsFromAd;
use Illuminate\Console\Command;

/**
 * Commande Artisan pour synchroniser les groupes de postes depuis AD
 * 
 * Usage:
 *   php artisan sync:workstation-groups        # Exécution synchrone
 *   php artisan sync:workstation-groups --queue # Dispatch vers la queue
 */
class SyncWorkstationGroupsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sync:workstation-groups 
                            {--queue : Dispatch le job vers la queue au lieu d\'exécuter en synchrone}';

    /**
     * The console command description.
     */
    protected $description = 'Synchronise les groupes de postes (parcs) depuis Active Directory vers MySQL';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Synchronisation des groupes de postes depuis AD...');

        try {
            if ($this->option('queue')) {
                SyncWorkstationGroupsFromAd::dispatch();
                $this->info('Job dispatché vers la queue.');
            } else {
                $job = new SyncWorkstationGroupsFromAd();
                $job->handle();
                $this->info('Synchronisation terminée avec succès.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Erreur: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
