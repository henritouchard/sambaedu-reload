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
