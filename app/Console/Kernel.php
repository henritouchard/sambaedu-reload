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

        // Story 39.2 (canal ③) — Émission du rapport de conformité amont.
        // Tick 1 min ; la commande gère elle-même sa cadence fixe
        // (config controlHub.compliance.interval, défaut 15 min) et court-circuite
        // sans contrat actif / sans connexion valide (NFR-A1). Dispatche un job fin
        // (retry) traité par le worker laravel-queue-general habituel.
        $schedule->command('controlhub:report-compliance')
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

        // Story 3.11 : Déclenchement des réinstallations OS dûes (tick 1 min),
        // borné par le plafond de concurrence `reinstall.max_concurrent` (D11).
        // Tick léger (SELECT + N enqueue) → les workers habituels traitent les
        // DispatchMachinePowerActionJob (reboot forcé / WOL). withoutOverlapping
        // pour éviter deux vagues concurrentes.
        $schedule->command('parc:reinstall-due')
                 ->everyMinute()
                 ->withoutOverlapping()
                 ->runInBackground();

        // Réconciliation TOTP des comptes de service (se4install) : aligne le
        // mot de passe AD sur la fenêtre 6 h courante. Tick 1 min → désync
        // post-rollover bornée à ~1 min ; no-op idempotent quand rien n'a
        // changé. withoutOverlapping pour éviter deux runs concurrents.
        $schedule->command('sambaedu:totp:reconcile')
                 ->everyMinute()
                 ->withoutOverlapping()
                 ->runInBackground();

        // Synchronisation automatique des utilisateurs depuis l'AD
        $schedule->command('users:sync-from-ad --scope=all --mode=delta')
                 ->everyFiveMinutes()
                 ->withoutOverlapping()
                 ->runInBackground();

        // NB : pas d'entrée dédiée pour les groupes utilisateurs — le tick
        // `users:sync-from-ad` ci-dessus les synchronise DÉJÀ en premier
        // (SyncUsersFromAdJob → UserGroupService::syncFromAd(), avant les
        // users pour éviter les FK violations). L'ancienne entrée
        // `user-groups:sync-from-ad` pointait une commande jamais créée
        // (NamespaceNotFoundException loggée toutes les 5 min depuis l'origine).

        // Purge des error_logs de plus de 30 jours
        $schedule->command('error-logs:prune')
                 ->daily()
                 ->runInBackground();

        // Story 4-4 : Purge des runs d'historique de programmations > 30 jours
        $schedule->command('parc:prune-group-schedule-runs')
                 ->daily()
                 ->runInBackground();

        // Story 3.11 : Purge des réinstallations terminales (done/failed/canceled) > 30 jours
        $schedule->command('parc:prune-reinstall-requests')
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

        // Story 20.2 — Purge RGPD des identités externes fédérées à 02h30.
        // Anonymise (jamais hard-delete) les `external_identities` dont la
        // rétention PII a expiré (last_login_at < now - pii_ttl_days).
        // Conditionnée par le toggle `federated_auth.retention.anonymize_enabled`
        // évalué à chaque tick via `->when()` (D-8 : OFF par défaut tant que la
        // base légale n'est pas validée — prise d'effet sans redéploiement,
        // pattern trash:purge). Décalée de trash:purge (02h00) pour éviter le
        // chevauchement de fenêtre de maintenance nocturne.
        $schedule->command('federated:purge-identities')
                 ->dailyAt('02:30')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->when(function (): bool {
                     try {
                         return (bool) config('federated_auth.retention.anonymize_enabled', false);
                     } catch (\Throwable $e) {
                         // Doute sur la config : on ne planifie pas (pas de
                         // purge muette — cohérent garde-fou trash:purge).
                         return false;
                     }
                 });

        // Story 24.1 — Purge des données de rapport agent (D3) à 02h35 :
        // agent_report_events > 14 j et agent_report_history > 30 j
        // (rétentions config/agent.php). Toujours planifiée (pas de ->when
        // sur le flag report_history : la purge history nettoie aussi les
        // résidus d'une phase de debug terminée). Fenêtre nocturne entre
        // trash:purge (02h00) et quota:snapshot (03h00), décalée de
        // federated:purge-identities (02h30) — review 24.1 #3. Deletes
        // indexés par created_at, charge négligeable.
        $schedule->command('agent:reports:prune')
                 ->dailyAt('02:35')
                 ->withoutOverlapping()
                 ->runInBackground();

        // Story 26.3 — Snapshot quotidien des tailles de profils itinérants à 04h30.
        // Scanne `/home/profiles` (`du --max-depth=1 -b`) UNE FOIS par nuit et
        // persiste les tailles par-login (users.profile_snapshot) + la liste des
        // profils orphelins (SystemSetting profiles.orphans). L'UI (tableau
        // /app/users + onglet admin profils-itinérants) lit le cache : ZÉRO
        // shellout/scan FS au render (contrainte perf invariant). Créneau 04:30
        // libre (après script-logs:archive:rotate 04:00).
        $schedule->command('profiles:snapshot')
                 ->dailyAt('04:30')
                 ->withoutOverlapping()
                 ->runInBackground();

        // Story 16.14 Q2 — Warm-up cache santé GPO daily 22:00.
        // Pré-charge `getLinks` + `versionNumber` pour chaque GPO du domaine
        // (TTL 24 h) — évite N appels samba-tool sur le listing admin matinal.
        // `runInBackground` + `withoutOverlapping` : pas de blocage du tick scheduler.
        $schedule->command('gpo:warm-cache')
                 ->dailyAt('22:00')
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
