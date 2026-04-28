<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // ControlHub Heartbeat - toutes les minutes (la commande gère elle-même l'intervalle)
        $schedule->command('controlhub:heartbeat')
                 ->everyMinute()
                 ->withoutOverlapping()
                 ->runInBackground();

        // Story 4-4 : Exécution des programmations horaires WorkstationGroup (tick 1 min)
        // Tick scheduler léger (1 SELECT + N enqueue) → les workers habituels
        // (laravel-queue-general) traitent ensuite les DispatchMachinePowerActionJob.
        // withoutOverlapping(5) : lock de 5 min max si un run dépasse (safety net).
        $schedule->command('parc:execute-group-schedules')
                 ->everyMinute()
                 ->withoutOverlapping(5)
                 ->runInBackground();

        // Synchronisation automatique des utilisateurs depuis l'AD
        $schedule->command('users:sync-from-ad --scope=all --mode=delta')
                 ->everyFiveMinutes()
                 ->withoutOverlapping()
                 ->runInBackground();

        // Synchronisation automatique des groupes utilisateurs depuis l'AD
        $schedule->command('user-groups:sync-from-ad')
                 ->everyFiveMinutes()
                 ->withoutOverlapping()
                 ->runInBackground();

        // Purge des error_logs de plus de 30 jours
        $schedule->command('error-logs:prune')
                 ->daily()
                 ->runInBackground();

        // Story 4-4 : Purge des runs d'historique de programmations > 30 jours
        $schedule->command('parc:prune-group-schedule-runs')
                 ->daily()
                 ->runInBackground();

        // Story 5.1b : Snapshot quotas XFS quotidien à 03h00
        // Parse `xfs_quota -x -c 'report -a -N'` en une passe et alimente
        // users.quota_snapshot. Remplace le cache 5 min supprimé en 5.1a.
        $schedule->command('quota:snapshot')
                 ->dailyAt('03:00')
                 ->withoutOverlapping()
                 ->runInBackground();

        // Story 6.1 : Réconciliation imprimantes CUPS ↔ table SER `printers` à 03h30
        // Idempotente : ajoute les CUPS détectés hors SER, marque orphan les rows
        // SER absents de CUPS (sans delete pour préserver les rattachements parc),
        // restaure orphan=false à la réintroduction.
        $schedule->command('printers:sync')
                 ->dailyAt('03:30')
                 ->withoutOverlapping()
                 ->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
