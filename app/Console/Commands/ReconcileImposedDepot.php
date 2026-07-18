<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ControlHubContract;
use App\Services\ControlHub\ImposedDepotReconciler;
use Illuminate\Console\Command;

/**
 * Story 51.1 — Réconciliation manuelle du dépôt IMPOSÉ par le contrat amont (controlHub).
 *
 * Point d'invocation EXPLICITE et IDEMPOTENT (reprise après incident, re-jeu après
 * correction d'une app en échec de désinstallation) hors réception d'un contrat. Délègue
 * à {@see ImposedDepotReconciler::reconcile()} et affiche les compteurs.
 *
 * NFR3 — sans contrat amont actif : message standalone + exit 0, rien d'écrit.
 *
 * ⚠️ GARDE-FOU R3 : vocabulaire « imposé » / « amont » / `Imposed` / `Upstream`
 * exclusivement, terme prohibé proscrit. [Source: prd-contrat-manage-se5.md#R3]
 */
class ReconcileImposedDepot extends Command
{
    protected $signature = 'controlhub:reconcile-imposed-depot';

    protected $description = 'Bascule le canal dépôts vers le dépôt imposé par le contrat amont (projection catalogue, transfert des communes, désinstallation du hors-catalogue, suppression des anciens dépôts — idempotent, re-jouable).';

    public function handle(ImposedDepotReconciler $reconciler): int
    {
        // NFR3 — standalone : sans contrat amont actif, ne rien écrire.
        if (ControlHubContract::active() === null) {
            $this->info('Aucun contrat amont actif — réconciliation du dépôt imposé ignorée (comportement standalone, rien écrit).');

            return self::SUCCESS;
        }

        $result = $reconciler->reconcile();

        $this->info('Réconciliation du dépôt imposé terminée :');
        $this->line("  Catalogue matérialisé : {$result->materialized}");
        $this->line("  Catalogue purgé       : {$result->purged}");
        $this->line("  Apps transférées      : {$result->transferred}");
        $this->line("  Apps désinstallées    : {$result->uninstalled}");
        $this->line("  Dépôts supprimés      : {$result->depotsDeleted}");

        if ($result->duplicatesRemoved > 0) {
            $this->warn("  Doublons détruits     : {$result->duplicatesRemoved} (app_id redondant déjà sur le dépôt imposé — cf. logs)");
        }

        if ($result->errors !== []) {
            $this->warn('Erreurs rencontrées :');
            foreach ($result->errors as $error) {
                $this->warn("  - {$error}");
            }

            // Au moins une opération a échoué → exit non-zéro (reprise/CI). Le cas
            // standalone (aucun contrat actif) reste exit 0, traité plus haut.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
