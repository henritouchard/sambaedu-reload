<?php

namespace App\Console\Commands;

use App\Jobs\SyncAllFromAdJob;
use Illuminate\Console\Command;

/**
 * Story 15.3 / AC3.1 — `--dry-run` : exécute la lecture AD complète + le
 * diff vs Eloquent + le rapport stats sans aucune écriture DB.
 */
class SyncFromAd extends Command
{
    protected $signature = 'sync:from-ad {--dry-run : Lecture AD + diff sans écriture DB (mode Aperçu)}';

    protected $description = 'Outil de remédiation drift : synchronise WorkstationGroups, AppProfiles depuis AD vers Eloquent (mode --dry-run pour Aperçu).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'Démarrage de la synchronisation en mode dry-run (Aperçu)...'
            : 'Démarrage de la synchronisation depuis AD...');

        try {
            $job = new SyncAllFromAdJob(dryRun: $dryRun);
            $stats = $job->handle();

            if ($stats['skipped_lock'] ?? false) {
                $this->warn('Synchronisation déjà en cours — exécution sautée (lock anti-double-clic).');

                return Command::SUCCESS;
            }

            if ($aborted = $stats['aborted_reason'] ?? null) {
                $this->error("Synchronisation interrompue : {$aborted}");

                return Command::FAILURE;
            }

            $this->info($dryRun ? 'Aperçu terminé !' : 'Synchronisation terminée !');
            $this->table(
                ['Type', 'Total AD', 'Total DB', 'Archivés DB', 'Créés', 'MAJ', 'Restaurés', 'Archivés', 'Ignorés'],
                [
                    [
                        'WorkstationGroups',
                        $stats['workstation_groups']['total_ad'],
                        $stats['workstation_groups']['total_db'],
                        $stats['workstation_groups']['total_db_archived'] ?? 0,
                        $stats['workstation_groups']['created'],
                        $stats['workstation_groups']['updated'],
                        $stats['workstation_groups']['restored'] ?? 0,
                        $stats['workstation_groups']['archived'],
                        $stats['workstation_groups']['skipped'],
                    ],
                    [
                        'AppProfiles',
                        $stats['app_profiles']['total_ad'],
                        $stats['app_profiles']['total_db'],
                        $stats['app_profiles']['total_db_archived'] ?? 0,
                        $stats['app_profiles']['created'],
                        $stats['app_profiles']['updated'],
                        $stats['app_profiles']['restored'] ?? 0,
                        $stats['app_profiles']['archived'],
                        $stats['app_profiles']['skipped'],
                    ],
                    [
                        'Liens Profile-Group',
                        '-',
                        '-',
                        '-',
                        $stats['profile_group_links']['created'],
                        '-',
                        '-',
                        '-',
                        $stats['profile_group_links']['skipped'],
                    ],
                ]
            );

            if ($stats['idempotent']) {
                $this->info('Idempotent : aucune écriture nécessaire (état AD = état DB).');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Erreur: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
