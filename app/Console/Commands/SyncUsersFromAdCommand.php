<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncUsersFromAdJob;
use App\Services\UserSyncService;
use Illuminate\Console\Command;

class SyncUsersFromAdCommand extends Command
{
    protected $signature = 'users:sync-from-ad
        {--scope=all : Scope établissement (all|tree|memberOf)}
        {--mode=delta : Mode de synchronisation (delta|full)}
        {--now : Exécute immédiatement sans passer par la queue sync}
        {--dry-run : Balaye l\'AD et affiche ce qui serait écrit, sans rien conserver}
        {--reset-delta-cursor : Réinitialise le curseur before un run delta}';

    protected $description = 'Synchronise automatiquement les utilisateurs depuis l\'AD vers SQL';

    protected $help = <<<'HELP'
    Importe les utilisateurs de l'annuaire vers la base.

      <info>php artisan users:sync-from-ad</info>                       incrémental, mis en file
      <info>php artisan users:sync-from-ad --mode=full --now</info>     complet, immédiat
      <info>php artisan users:sync-from-ad --scope=tree</info>

    <comment>--mode</comment> : <info>delta</info> ne reprend que ce qui a changé depuis le dernier passage ;
    <info>full</info> rebalaie tout.

    <comment>--scope</comment> délimite le périmètre d'établissement : <info>all</info>, <info>tree</info> ou
    <info>memberOf</info>.

    Par défaut la commande MET EN FILE et rend la main aussitôt — c'est le mode à
    préférer sur un gros annuaire. <comment>--now</comment> exécute dans le terminal et affiche la
    progression ; utile pour diagnostiquer, mais bloquant.

    <comment>--reset-delta-cursor</comment> repart de zéro pour l'incrémental, quand on soupçonne le
    curseur d'avoir sauté des modifications.

    <comment>--dry-run</comment> balaie l'annuaire pour de vrai, applique l'import dans une
    transaction, puis l'annule : rien n'est conservé, pas même le curseur delta.
    Le rapport liste les logins qui seraient créés, mis à jour ou réactivés.
    L'option implique <comment>--now</comment>, et refuse <comment>--reset-delta-cursor</comment> qui,
    lui, écrit. Pour voir ce que donnerait un incrémental reparti de zéro,
    utiliser <info>--mode=full --dry-run</info>.

    <comment>Cette commande n'A JAMAIS d'effet de désactivation</comment>, même en mode complet :
    les départs relèvent de <info>users:reconcile-departures</info>.
    HELP;

    public function handle(UserSyncService $userSyncService): int
    {
        $scope = (string) $this->option('scope');
        $mode = (string) $this->option('mode');
        $dryRun = (bool) $this->option('dry-run');

        if (!in_array($scope, ['all', 'tree', 'memberOf'], true)) {
            $this->error('Option --scope invalide. Valeurs acceptées: all, tree, memberOf');
            return self::FAILURE;
        }

        if (!in_array($mode, ['delta', 'full'], true)) {
            $this->error('Option --mode invalide. Valeurs acceptées: delta, full');
            return self::FAILURE;
        }

        if ($dryRun && (bool) $this->option('reset-delta-cursor')) {
            $this->error('--reset-delta-cursor écrit en base : incompatible avec --dry-run. Utiliser --mode=full --dry-run.');
            return self::FAILURE;
        }

        if ((bool) $this->option('reset-delta-cursor')) {
            $userSyncService->resetDeltaCursor();
            $this->line('Curseur delta réinitialisé.');
        }

        if (!$dryRun && ! (bool) $this->option('now')) {
            SyncUsersFromAdJob::dispatch($scope, $mode);
            $this->info("Job de synchronisation users dispatché sur la queue sync (scope={$scope}, mode={$mode}).");

            return self::SUCCESS;
        }

        $this->info($dryRun
            ? "Dry-run de la synchronisation users AD -> SQL (scope={$scope}, mode={$mode})..."
            : "Démarrage de la synchronisation users AD -> SQL (scope={$scope}, mode={$mode})...");

        try {
            $logger = function (string $level, string $message): void {
                $this->line("[{$level}] {$message}");
            };

            $stats = $mode === 'delta'
                ? $userSyncService->importFromAdDelta(
                    logger: $logger,
                    establishmentScope: $scope,
                    dryRun: $dryRun,
                )
                : $userSyncService->importFromAd(
                    logger: $logger,
                    establishmentScope: $scope,
                    dryRun: $dryRun,
                );

            $this->table(
                ['Stat', 'Valeur'],
                [
                    ['mode', (string) ($stats['delta_mode'] ?? false ? 'delta' : 'full')],
                    ['created', (string) $stats['created']],
                    ['updated', (string) $stats['updated']],
                    ['skipped', (string) $stats['skipped']],
                    ['errors', (string) $stats['errors']],
                    ['total_ad', (string) $stats['total_ad']],
                    ['etab_tree', (string) $stats['etab_tree']],
                    ['etab_ou_tree', (string) ($stats['etab_ou_tree'] ?? 0)],
                    ['etab_member_of', (string) $stats['etab_member_of']],
                    ['etab_excluded', (string) $stats['etab_excluded']],
                    ['delta_cursor_start', (string) ($stats['delta_cursor_start'] ?? '')],
                    ['delta_cursor_end', (string) ($stats['delta_cursor_end'] ?? '')],
                    ['dry_run', $dryRun ? 'oui' : 'non'],
                ]
            );

            if ($dryRun) {
                $this->renderDryRunDetail('Seraient créés', $stats['dry_run_created'] ?? []);
                $this->renderDryRunDetail('Seraient mis à jour', $stats['dry_run_updated'] ?? []);
                $this->renderDryRunDetail('Seraient réactivés', $stats['dry_run_reactivated'] ?? []);
                $this->warn('Dry-run : aucune écriture conservée.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Échec de la synchronisation users: ' . $exception->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param list<string> $logins
     */
    private function renderDryRunDetail(string $title, array $logins): void
    {
        if ($logins === []) {
            return;
        }

        $this->newLine();
        $this->line("<options=bold>{$title}</> (" . count($logins) . ') :');

        // Un annuaire d'établissement se compte en milliers de comptes ; sur un
        // premier balayage la liste complète noierait le rapport.
        $shown = array_slice($logins, 0, 50);
        $this->line('  ' . implode(', ', $shown));

        if (count($logins) > count($shown)) {
            $this->line('  … et ' . (count($logins) - count($shown)) . ' autres.');
        }
    }
}
