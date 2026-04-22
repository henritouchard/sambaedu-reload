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

        // Rafraîchissement du cache des quotas - toutes les 5 minutes
        $schedule->command('quota:refresh-cache')
                 ->everyFiveMinutes()
                 ->withoutOverlapping()
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
