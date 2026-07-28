<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\Oidc\Services\OidcClientRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Story 55.1 — Task 6.
 *
 * `php artisan oidc:client:revoke <client_id>`
 *
 * Révoque un client confidentiel. La révocation est une **désactivation**, pas
 * une suppression : toute résolution passe par
 * {@see OidcClientRegistry::findEnabledByClientId()}, de sorte qu'un client
 * révoqué n'obtient plus ni code ni jeton — codes et access tokens déjà émis
 * compris — tout en conservant sa trace au registre.
 *
 * **IDEMPOTENTE** (doctrine ops du projet) : rejouer la commande sur un client
 * déjà révoqué est un no-op signalé, pas une erreur. Un client inconnu, en
 * revanche, retourne 1 : c'est probablement une faute de frappe, et échouer
 * silencieusement laisserait croire à une révocation qui n'a pas eu lieu.
 *
 * Codes retour : 0 succès ou no-op, 1 client inconnu / erreur.
 */
class OidcClientRevoke extends Command
{
    /** @var string */
    protected $signature = 'oidc:client:revoke {client_id : Identifiant public du client à révoquer}';

    /** @var string */
    protected $description = 'Révoque (désactive) un client confidentiel du registre OIDC.';

    public function handle(OidcClientRegistry $registry): int
    {
        $clientId = (string) $this->argument('client_id');

        try {
            $result = $registry->revoke($clientId);
        } catch (Throwable $e) {
            $this->error('oidc:client:revoke a échoué : ' . $e->getMessage());

            return 1;
        }

        if (! $result['found']) {
            $this->error('Client inconnu du registre : ' . $clientId);

            return 1;
        }

        if (! $result['changed']) {
            $this->line('Client déjà révoqué — aucune action (commande idempotente) : ' . $clientId);

            return 0;
        }

        $this->info('Client révoqué : ' . $clientId);
        $this->line('Ses codes d\'autorisation et access tokens en circulation sont désormais inutilisables.');

        return 0;
    }
}
