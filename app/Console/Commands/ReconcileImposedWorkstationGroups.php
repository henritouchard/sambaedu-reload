<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ControlHubContract;
use App\Services\ControlHub\ImposedWorkstationGroupReconciler;
use Illuminate\Console\Command;

/**
 * Story 30.3 — Réconciliation manuelle des groupes imposés par le contrat amont
 * (controlHub).
 *
 * Point d'invocation **explicite et idempotent** (reprise après incident,
 * provisioning) hors réception d'un contrat. Délègue à
 * {@see ImposedWorkstationGroupReconciler::reconcile()} et affiche les compteurs.
 *
 * NFR3 — sans contrat amont actif : message standalone + exit 0, rien d'écrit.
 *
 * ⚠️ GARDE-FOU R3 : vocabulaire « amont » exclusivement, terme prohibé proscrit. [Source: prd-contrat-manage-se5.md#R3]
 */
class ReconcileImposedWorkstationGroups extends Command
{
    protected $signature = 'controlhub:reconcile-imposed-groups';

    protected $description = 'Garantit l\'existence des groupes imposés par le contrat amont (création/confirmation idempotente + levée du verrou des non-imposés).';

    protected $help = <<<'HELP'
    Garantit que les groupes de postes imposés par le contrat amont existent bien :
    elle crée ceux qui manquent, confirme ceux qui sont déjà là, et LÈVE le verrou
    des groupes qui ne sont plus imposés — sans jamais les supprimer.

    Elle a normalement lieu toute seule à la réception d'un contrat. Cette commande
    est le point de reprise MANUEL : après un incident, ou pour provisionner une
    instance neuve.

      <info>php artisan controlhub:reconcile-imposed-groups</info>

    Rejouable sans risque. Sans contrat amont actif, elle le dit et sort normalement
    sans rien écrire.
    HELP;

    public function handle(ImposedWorkstationGroupReconciler $reconciler): int
    {
        // NFR3 — standalone : sans contrat amont actif, ne rien écrire.
        if (ControlHubContract::active() === null) {
            $this->info('Aucun contrat amont actif — réconciliation ignorée (comportement standalone, rien écrit).');

            return self::SUCCESS;
        }

        $result = $reconciler->reconcile();

        $this->info('Réconciliation des groupes imposés terminée :');
        $this->line("  Créés      : {$result->created}");
        $this->line("  Confirmés  : {$result->confirmed}");
        $this->line("  Adoptés    : {$result->adopted}");
        $this->line("  Verrou levé: {$result->released}");

        if ($result->errors !== []) {
            $this->warn('Erreurs rencontrées :');
            foreach ($result->errors as $error) {
                $this->warn("  - {$error}");
            }

            // Au moins un groupe a échoué → exit non-zéro (reprise/CI). Le cas
            // standalone (aucun contrat actif) reste exit 0, traité plus haut.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
