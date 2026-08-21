<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ControlHubContract;
use App\Services\ControlHub\ShortcutContractReconciler;
use Illuminate\Console\Command;

/**
 * Point de reprise manuel de la matérialisation des raccourcis imposés par le
 * contrat amont, hors réception d'un contrat.
 */
class MaterializeContractShortcuts extends Command
{
    protected $signature = 'controlhub:materialize-shortcuts';

    protected $description = 'Aligne la bibliothèque de raccourcis sur les raccourcis imposés par le contrat amont (idempotent, re-jouable).';

    protected $help = <<<'HELP'
    Crée, met à jour et retire les raccourcis de la bibliothèque locale d'après les
    items « shortcuts » du contrat amont actif.

    Cet alignement a normalement lieu tout seul à la réception d'un contrat. Cette
    commande est le point de reprise MANUEL : après un incident, ou pour rejouer une
    fois corrigé un item dont la matérialisation avait échoué.

      <info>php artisan controlhub:materialize-shortcuts</info>

    Le retrait ne concerne QUE les raccourcis nés du contrat : un raccourci d'origine
    locale, ou posé par le canal de tâches, n'est jamais supprimé.

    Rejouable sans risque. Sans contrat amont actif, elle le dit et sort normalement
    sans rien écrire.
    HELP;

    public function handle(ShortcutContractReconciler $reconciler): int
    {
        if (ControlHubContract::active() === null) {
            $this->info('Aucun contrat amont actif — matérialisation des raccourcis ignorée (comportement standalone, rien écrit).');

            return self::SUCCESS;
        }

        $result = $reconciler->reconcile();

        $this->info('Matérialisation des raccourcis imposés terminée :');
        $this->line("  Créés     : {$result->created}");
        $this->line("  Mis à jour: {$result->updated}");
        $this->line("  Inchangés : {$result->unchanged}");
        $this->line("  Sans cible: {$result->skipped}");
        $this->line("  Retirés   : {$result->removed}");

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
