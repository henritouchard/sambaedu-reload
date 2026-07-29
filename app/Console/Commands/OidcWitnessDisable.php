<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\Oidc\Services\OidcClientRegistry;
use App\OidcWitness\Support\WitnessCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 55.3 — `php artisan oidc:witness:disable`
 *
 * Retire l'app-témoin : révoque son client OIDC et supprime son fichier de
 * credentials. **IDEMPOTENTE** : rejouer sur une instance déjà nettoyée est un
 * no-op signalé, pas une erreur (doctrine ops).
 *
 * ⚠️ La révocation est une DÉSACTIVATION du client, jamais une suppression :
 * la trace reste au registre (patron `oidc:client:revoke`). Une fois révoqué,
 * `/sso-demo` échoue de façon EXPLICITE — c'est vérifié par test.
 */
class OidcWitnessDisable extends Command
{
    /** @var string */
    protected $signature = 'oidc:witness:disable';

    /** @var string */
    protected $description = 'Retire l\'app-témoin SSO (révoque son client OIDC, supprime ses credentials).';

    public function handle(OidcClientRegistry $registry): int
    {
        $credentials = WitnessCredentials::load();

        if ($credentials === null) {
            // Le fichier peut exister mais être inexploitable : on le supprime
            // quand même, sinon `enable` refuserait de repartir.
            $removed = WitnessCredentials::forget();

            if ($removed) {
                $this->warn('Fichier de credentials illisible supprimé — aucun client n\'a pu être révoqué.');
                $this->line('  Vérifier `php artisan oidc:client:revoke <client_id>` si un client orphelin subsiste.');

                return 0;
            }

            $this->info('App-témoin déjà retirée — aucune action.');

            return 0;
        }

        try {
            $result = $registry->revoke($credentials->clientId);
        } catch (Throwable $e) {
            $this->error('Révocation impossible : ' . $e->getMessage());

            return 1;
        }

        if (! WitnessCredentials::forget()) {
            $this->error('Suppression du fichier de credentials impossible : ' . WitnessCredentials::path());

            return 1;
        }

        Log::channel('oidc')->info('[OidcWitnessDisable] oidc.witness.retired', [
            'action_type' => 'oidc.witness.retired',
            'client_id' => $credentials->clientId,
            'client_found' => $result['found'],
            'client_revoked' => $result['changed'],
        ]);

        $this->info('App-témoin retirée.');
        $this->line('  client_id : ' . $credentials->clientId
            . ($result['changed'] ? ' (révoqué)' : ' (déjà révoqué ou inconnu du registre)'));
        $this->line('  fichier   : supprimé');
        $this->line('');
        $this->line('La tuile « Démo SSO » reste au catalogue : la désinstaller depuis /admin/extensions.');

        return 0;
    }
}
