<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AgentReportEvent;
use App\Models\AgentReportHistory;
use Illuminate\Console\Command;

/**
 * Story 24.1 — Purge des données de rapport agent (D3, AC3).
 *
 * Deux rétentions, lues dans `config/agent.php` (clés EXISTANTES 23.5,
 * planchers `max(1, …)` déjà appliqués au config:cache) :
 *
 *  - `agent_report_events` > `report_events_retention_days` (14 j) ;
 *  - `agent_report_history` > `report_history_retention_days` (30 j) —
 *    purge inconditionnelle au flag `report_history` : vide si flag off,
 *    et nettoie les résidus d'une phase de débogage terminée.
 *
 * Planifiée daily 02:30 ({@see \App\Console\Kernel}), iso pattern
 * `error-logs:prune`.
 */
class PruneAgentReportsCommand extends Command
{
    protected $signature = 'agent:reports:prune';

    protected $description = 'Purge les agent_report_events et agent_report_history au-delà des rétentions config/agent.php';

    protected $help = <<<'HELP'
    Supprime les données de rapport des agents devenues trop anciennes. Deux
    rétentions distinctes, lues dans <info>config/agent.php</info> :

      <comment>agent_report_events</comment>    au-delà de <info>report_events_retention_days</info>  (14 jours par défaut)
      <comment>agent_report_history</comment>   au-delà de <info>report_history_retention_days</info> (30 jours par défaut)

    L'historique est purgé INCONDITIONNELLEMENT, que sa collecte soit active ou non :
    la table reste donc vide quand la fonction est coupée, et les résidus d'une phase
    de diagnostic terminée finissent par disparaître d'eux-mêmes.

    Planifiée quotidiennement — vous n'avez normalement pas à la lancer à la main.
    HELP;

    public function handle(): int
    {
        $eventsCutoff = now()->subDays(max(1, (int) config('agent.report_events_retention_days', 14)));
        $historyCutoff = now()->subDays(max(1, (int) config('agent.report_history_retention_days', 30)));

        $events = AgentReportEvent::query()->where('created_at', '<', $eventsCutoff)->delete();
        $history = AgentReportHistory::query()->where('created_at', '<', $historyCutoff)->delete();

        $this->info(sprintf(
            '%d événement(s) de rapport purgé(s) (antérieurs au %s), %d ligne(s) d\'historique purgée(s) (antérieures au %s).',
            $events,
            $eventsCutoff->format('d/m/Y'),
            $history,
            $historyCutoff->format('d/m/Y'),
        ));

        return self::SUCCESS;
    }
}
