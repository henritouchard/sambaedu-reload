<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ControlHubContract;
use App\Services\ControlHub\OrderedApplicationProvisioner;
use Illuminate\Console\Command;

/**
 * Story 31.3 — Approvisionnement manuel des applications ordonnées par le contrat amont
 * (controlHub).
 *
 * Point d'invocation EXPLICITE et IDEMPOTENT (reprise après incident, provisioning) hors
 * réception d'un contrat. Délègue à {@see OrderedApplicationProvisioner::provision()} et
 * affiche les compteurs.
 *
 * NFR3 — sans contrat amont actif : message standalone + exit 0, rien d'écrit.
 *
 * ⚠️ GARDE-FOU R3 : vocabulaire « amont » exclusivement, terme prohibé proscrit.
 * [Source: prd-contrat-manage-se5.md#R3]
 */
class ProvisionOrderedApplications extends Command
{
    protected $signature = 'controlhub:provision-ordered-apps';

    protected $description = 'Matérialise en inventaire les applications ordonnées par le contrat amont depuis leur source de dépôt (idempotent, re-jouable).';

    public function handle(OrderedApplicationProvisioner $provisioner): int
    {
        // NFR3 — standalone : sans contrat amont actif, ne rien écrire.
        if (ControlHubContract::active() === null) {
            $this->info('Aucun contrat amont actif — approvisionnement ignoré (comportement standalone, rien écrit).');

            return self::SUCCESS;
        }

        $result = $provisioner->provision();

        $this->info('Approvisionnement des applications ordonnées terminé :');
        $this->line("  Matérialisées  : {$result->provisioned}");
        $this->line("  Déjà présentes : {$result->alreadyPresent}");
        $this->line("  Ignorées       : {$result->skipped}");
        $this->line("  Échecs         : {$result->failed}");

        if ($result->errors !== []) {
            $this->warn('Erreurs rencontrées :');
            foreach ($result->errors as $error) {
                $this->warn("  - {$error}");
            }

            // Au moins une app a échoué → exit non-zéro (reprise/CI). Le cas standalone
            // (aucun contrat actif) reste exit 0, traité plus haut.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
