<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Console\Commands;

use App\Models\Workstation;
use App\Wpkg\Deployment\Models\WorkstationApiSecret;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 15.5 / AC5.5 — Révoque définitivement le secret API d'un poste.
 *
 * Toute requête future avec ce token (ou son ancien dans la fenêtre rotation)
 * sera rejetée 401 par `WorkstationBearerAuth`.
 *
 * Utilisé en cas de compromission ou de retrait d'un poste du parc.
 */
final class RevokeWorkstationSecretCommand extends Command
{
    protected $signature = 'wpkg:revoke-secret
                            {workstation : Hostname OU ID du poste}';

    protected $description = 'Révoque définitivement le secret API d\'un poste (Story 15.5).';

    public function handle(): int
    {
        $identifier = (string) $this->argument('workstation');
        $workstation = $this->resolveWorkstation($identifier);

        if ($workstation === null) {
            $this->error("Poste '{$identifier}' introuvable.");

            return self::FAILURE;
        }

        $secretRow = WorkstationApiSecret::where('workstation_id', $workstation->id)->first();

        if ($secretRow === null) {
            $this->warn("Aucun secret à révoquer pour '{$workstation->name}'.");

            return self::SUCCESS;
        }

        if ($secretRow->isRevoked()) {
            $this->info("Secret déjà révoqué pour '{$workstation->name}' (le {$secretRow->revoked_at}).");

            return self::SUCCESS;
        }

        $secretRow->update(['revoked_at' => now()]);

        Log::channel('wpkg-deploy')->warning('[wpkg:revoke-secret] secret révoqué', [
            'event' => 'wpkg_revoke_secret',
            'workstation_id' => $workstation->id,
            'hostname' => $workstation->name,
        ]);

        $this->info("Secret révoqué pour '{$workstation->name}'. Toute requête future avec ce token → 401.");

        return self::SUCCESS;
    }

    private function resolveWorkstation(string $identifier): ?Workstation
    {
        if (ctype_digit($identifier)) {
            return Workstation::find((int) $identifier);
        }

        return Workstation::where('name', $identifier)->first();
    }
}
