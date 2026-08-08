<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Nextcloud\NextcloudProvisioningService;
use Illuminate\Console\Command;

/**
 * Story 61.1 — LE GESTE D'EXPLOITATION, en commande.
 *
 * Doctrine du dépôt : les opérations multi-instance sont des COMMANDES, jamais des
 * procédures manuelles à rejouer. Le bouton de `/admin/settings/files` n'est pas un
 * second chemin — il enfile un traitement qui exécute ce même service.
 *
 * **Codes de sortie, et ils veulent dire quelque chose :**
 *  - `0` — convergé : les deux montages sont en place, le stock est adopté ;
 *  - `1` — échecs PARTIELS : quelque chose a abouti et quelque chose a échoué. Les
 *    compteurs disent quoi. Un run qui a monté les stockages mais échoué sur les
 *    comptes tombe ici, et il le DIT ;
 *  - `2` — rien n'a été tenté : configuration incomplète, capacité éteinte,
 *    instance injoignable, privilège insuffisant, ou provisionnement concurrent.
 *
 * `--dry-run` n'émet AUCUNE écriture : ni montage, ni compte, ni cache d'identité.
 * Il LIT (l'état de l'instance et l'existence des comptes) parce qu'un aperçu qui
 * n'interrogerait rien n'apprendrait rien.
 */
class NextcloudProvisionCommand extends Command
{
    protected $signature = 'nextcloud:provision
        {--dry-run : Dit ce qui serait fait, sans émettre la moindre écriture}
        {--users-only : Ne traite que les comptes utilisateurs}
        {--mounts-only : Ne traite que les montages external storage}';

    protected $description = 'Provisionne l\'accès Nextcloud : montages external storage SMB + comptes utilisateurs';

    public function handle(NextcloudProvisioningService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $usersOnly = (bool) $this->option('users-only');
        $mountsOnly = (bool) $this->option('mounts-only');

        if ($usersOnly && $mountsOnly) {
            $this->error('--users-only et --mounts-only s\'excluent : choisissez lequel des deux.');

            return 2;
        }

        $report = $service->run(
            dryRun: $dryRun,
            mounts: ! $usersOnly,
            withUsers: ! $mountsOnly,
        );

        $this->render($report);

        return $report->exitCode();
    }

    private function render(\App\Services\Nextcloud\NextcloudProvisioningReport $report): void
    {
        if ($report->dryRun) {
            $this->comment('SIMULATION — aucune écriture n\'a été émise.');
        }

        $refusal = $report->refusal();
        if ($refusal !== null) {
            $this->error($refusal);

            return;
        }

        $connection = $report->toArray()['connection'] ?? null;
        if (is_array($connection) && $connection['ok'] !== true) {
            $this->error((string) $connection['message']);

            return;
        }

        $mounts = $report->mounts();
        if ($mounts !== []) {
            $this->line('');
            $this->line('<options=bold>Montages external storage</>');
            $this->table(
                ['Montage', 'État', 'Détail'],
                array_map(
                    static fn (array $m): array => [$m['name'], $m['label'], $m['detail']],
                    $mounts,
                ),
            );
        }

        $counters = $report->userCounters();
        $this->line('');
        $this->line('<options=bold>Comptes utilisateurs</>');
        $this->table(
            ['Créés', 'Adoptés', 'Introuvables', 'Échecs', 'Hors périmètre'],
            [[
                $counters['crees'],
                $counters['adoptes'],
                $counters['introuvables'],
                $counters['echecs'],
                $counters['exclus'],
            ]],
        );

        $issues = $report->userIssues();
        if ($issues !== []) {
            $this->line('');
            $this->line('<options=bold>Comptes demandant un geste</>');
            foreach ($issues as $issue) {
                $this->line(sprintf('  · %s — %s : %s', $issue['login'], $issue['issue'], $issue['detail']));
            }
        }
    }
}
