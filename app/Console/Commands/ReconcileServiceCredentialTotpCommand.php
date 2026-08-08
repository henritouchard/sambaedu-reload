<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ServiceCredentialTotpReconciler;
use Illuminate\Console\Command;

/**
 * Réconciliation du mot de passe AD des comptes de service TOTP (ex.
 * `se4install`) avec la fenêtre de 6 h courante.
 *
 * Exécutée :
 *  - chaque minute par `app/Console/Kernel.php` (`->withoutOverlapping()`) :
 *    borne la fenêtre de désync post-rollover à ~1 min, les ticks où rien n'a
 *    changé sont des no-op idempotents très légers (compare un compteur, ne
 *    touche pas l'AD).
 *  - à la demande : `php artisan sambaedu:totp:reconcile`.
 *
 * Idempotente et auto-réparante : le compteur appliqué n'avance qu'après un
 * write AD confirmé ; un échec est rejoué au tick suivant (cf.
 * {@see ServiceCredentialTotpReconciler}). Aucune désync permanente possible.
 */
class ReconcileServiceCredentialTotpCommand extends Command
{
    protected $signature = 'sambaedu:totp:reconcile';

    protected $description = 'Synchronise le mot de passe AD des comptes de service TOTP avec la fenêtre 6 h courante';

    protected $help = <<<'HELP'
    Aligne le mot de passe des comptes de service à mot de passe temporaire sur la
    fenêtre de six heures en cours.

    Planifiée chaque minute, sans recouvrement : la fenêtre pendant laquelle un compte
    peut être désaligné après un changement de fenêtre est ainsi bornée à une minute.
    Les passages où rien n'a changé sont quasi gratuits — un compteur est comparé,
    l'annuaire n'est pas touché.

      <info>php artisan sambaedu:totp:reconcile</info>

    Auto-réparante : le compteur n'avance qu'APRÈS une écriture confirmée dans
    l'annuaire. Un échec est donc simplement rejoué au passage suivant, et aucun
    désalignement ne peut s'installer durablement.
    HELP;

    public function handle(ServiceCredentialTotpReconciler $reconciler): int
    {
        $results = $reconciler->reconcileAll();

        if ($results === []) {
            $this->info('Aucun compte de service TOTP à réconcilier.');

            return self::SUCCESS;
        }

        foreach ($results as $name => $status) {
            $this->line(sprintf('  %-20s %s', $name, $status));
        }

        // Un seul échec → exit non-zéro pour que le monitoring du scheduler le
        // remonte (le compteur reste en arrière, donc rejoué au prochain tick).
        $failed = array_keys($results, 'failed', true);
        if ($failed !== []) {
            $this->error('Échec de réconciliation : ' . implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
