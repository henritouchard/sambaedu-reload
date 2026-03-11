<?php

namespace App\Console\Commands;

use App\Jobs\SyncAllFromAdJob;
use Illuminate\Console\Command;

class SyncFromAd extends Command
{
    protected $signature = 'sync:from-ad';
    protected $description = 'Synchronise WorkstationGroups, AppProfiles et Workstations depuis AD';

    public function handle(): int
    {
        $this->info('Démarrage de la synchronisation depuis AD...');

        try {
            $job = new SyncAllFromAdJob();
            $stats = $job->handle();

            $this->info('Synchronisation terminée !');
            $this->table(
                ['Type', 'Créés', 'Mis à jour', 'Ignorés'],
                [
                    ['WorkstationGroups', $stats['workstation_groups']['created'], $stats['workstation_groups']['updated'], $stats['workstation_groups']['skipped']],
                    ['AppProfiles', $stats['app_profiles']['created'], $stats['app_profiles']['updated'], $stats['app_profiles']['skipped']],
                    ['Liens Profile-Group', $stats['profile_group_links']['created'], '-', $stats['profile_group_links']['skipped']],
                    ['Workstations', $stats['workstations']['created'], $stats['workstations']['updated'], $stats['workstations']['skipped']],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Erreur: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
