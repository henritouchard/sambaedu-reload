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

        // Story 5.1d : Purge corbeille `/home/trash/*` quotidiennement à 02h00.
        // Conditionné par le toggle `quota.trash.purge_auto` (SystemSetting),
        // évalué à chaque tick via `->when(closure)`. Si le toggle est false,
        // la commande n'est pas exécutée — décision admin prise dans
        // /admin/settings → Quotas & FS, prise d'effet immédiate sans redéploiement.
        // Placée à 02h00 pour précéder le snapshot 03h00 (purge → état stable
        // pour le rapport XFS).
        $schedule->command('trash:purge')
                 ->dailyAt('02:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->when(function (): bool {
                     try {
                         $cfg = \App\Models\SystemSetting::get('quota.trash', null);
                         return is_array($cfg) && (bool) ($cfg['purge_auto'] ?? false);
                     } catch (\Throwable $e) {
                         // BDD indispo / table absente : on ne planifie pas
                         // (interprétation prudente — pas de purge muette en cas
                         // de doute sur la config).
                         return false;
                     }
                 });

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

        // Story 6.2 : Réconciliation pilotes Windows ↔ table SER `printer_drivers` à 03h35
        // (5 min après printers:sync — monitoring séparé, D7 6.2). Idempotente.
        // Skip orphan-marking si Samba injoignable (cohérent fix #12 6.1).
        $schedule->command('printer-drivers:sync')
                 ->dailyAt('03:35')
                 ->withoutOverlapping()
                 ->runInBackground();

        // Story 15.5 : Rotation quotidienne des archives brutes des rapports WPKG.
        // Supprime les fichiers > config('sambaedu.wpkg.reports_archive_retention_days')
        // (90 jours par défaut). Best-effort : si le dossier d'archive est absent,
        // la commande retourne 0 sans erreur.
        $schedule->command('wpkg:reports:archive:rotate')
                 ->dailyAt('03:45')
                 ->withoutOverlapping()
                 ->runInBackground();

        // Story 16.11 : Alerte santé migration auto-bootstrap.
        // Calcule le ratio d'échecs des tentatives auto-bootstrap sur 7 jours
        // glissants. Si ratio > 5% → log critical `auth.migration.health.alert`
        // sur channel `auth-v1`. Henri tail les logs (Phase 3+ : intégration
        // mail/webhook). Commande informative — exit 0 même en alerte.
        $schedule->command('migration:health-check')
                 ->daily()
                 ->withoutOverlapping()
                 ->runInBackground();

        // Story 16.12 — Archivage daily des logs d'exécution scripts > 90j (timing 04:00 post code-review F1 : éviter collision avec printers:sync 03:30 et wpkg:reports:archive:rotate 03:45)
        $schedule->command('script-logs:archive:rotate')
                 ->dailyAt('04:00')
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
