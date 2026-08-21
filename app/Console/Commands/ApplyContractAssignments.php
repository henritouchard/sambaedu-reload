<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ControlHubContract;
use App\Services\ControlHub\ContractAssignmentReconciler;
use Illuminate\Console\Command;

/**
 * Point de reprise manuel de la pose des assignations réclamées par le contrat
 * amont, hors réception d'un contrat.
 */
class ApplyContractAssignments extends Command
{
    protected $signature = 'controlhub:apply-assignments';

    protected $description = 'Assigne aux parcs porteurs de label les applications, raccourcis, capacités et fonds d\'écran du contrat amont (idempotent, re-jouable).';

    protected $help = <<<'HELP'
    Traduit les items du contrat amont en assignations réelles : chaque item ciblant
    un label est posé sur les parcs qui portent ce label, chaque item ciblant
    l'instance devient un défaut d'établissement.

    Cette pose a normalement lieu toute seule à la réception d'un contrat. Cette
    commande est le point de reprise MANUEL — utile en particulier quand un fond
    d'écran n'avait pas encore été téléchargé au moment de la réception.

      <info>php artisan controlhub:apply-assignments</info>

    Le retrait ne concerne QUE les assignations posées par le contrat : ce que
    l'administrateur a assigné à la main n'est jamais défait.

    Rejouable sans risque. Sans contrat amont actif, elle le dit et sort normalement
    sans rien écrire.
    HELP;

    public function handle(ContractAssignmentReconciler $reconciler): int
    {
        if (ControlHubContract::active() === null) {
            $this->info('Aucun contrat amont actif — pose des assignations ignorée (comportement standalone, rien écrit).');

            return self::SUCCESS;
        }

        $result = $reconciler->reconcile();

        $this->info('Assignations du contrat amont appliquées :');
        $this->line("  Posées        : {$result->attached}");
        $this->line("  Retirées      : {$result->detached}");
        $this->line("  Défauts étab  : {$result->defaults}");
        $this->line("  Non résolues  : {$result->unresolved}");

        if ($result->errors !== []) {
            $this->newLine();
            $this->warn('Échecs :');
            foreach ($result->errors as $error) {
                $this->line("  · {$error}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
