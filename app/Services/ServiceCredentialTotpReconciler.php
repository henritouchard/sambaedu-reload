<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Réconcilie le mot de passe AD des comptes de service TOTP avec la fenêtre
 * de 6 h courante (parité legacy `ent.inc.php` + `usersetpassword`).
 *
 * Garantie anti-désync permanente : le compteur appliqué
 * (`totp_applied_counter`) n'est avancé QU'APRÈS un write AD confirmé. Un échec
 * laisse le compteur en arrière → rejoué au tick suivant. L'opération est
 * idempotente (re-poser le même mot de passe est sans effet de bord) et le
 * suivi est PAR COMPTE (l'échec de l'un ne masque ni ne bloque les autres).
 *
 * @see \App\Services\ServiceCredentials  (source de vérité chiffrée + calcul TOTP)
 * @see \App\Services\UserService::changePasswordInAd()  (write AD réutilisé)
 */
class ServiceCredentialTotpReconciler
{
    public function __construct(
        private readonly ServiceCredentials $credentials,
        private readonly UserService $users,
    ) {
    }

    /**
     * Réconcilie tous les comptes ayant un secret TOTP.
     *
     * @return array<string, string> map nom => statut
     */
    public function reconcileAll(): array
    {
        $results = [];
        foreach ($this->credentials->managedTotpNames() as $name) {
            $results[$name] = $this->reconcile($name);
        }

        return $results;
    }

    /**
     * Réconcilie un compte.
     *
     * @return string 'skipped' | 'up_to_date' | 'applied' | 'failed'
     */
    public function reconcile(string $name): string
    {
        if ($this->credentials->totpSecret($name) === null) {
            return 'skipped'; // pas de TOTP → rien à rotationner
        }

        $current = $this->credentials->currentCounter();

        if ($this->credentials->appliedCounter($name) === $current) {
            return 'up_to_date'; // AD déjà sur la fenêtre courante
        }

        $password = $this->credentials->passwordForCounter($name, $current);
        if ($password === null) {
            // Secret TOTP présent mais base absente : on ne peut pas calculer
            // base+code. Ne devrait pas arriver pour un compte de service géré.
            Log::warning('TOTP reconcile: base manquante, compte ignoré', [
                'name' => $name,
            ]);

            return 'skipped';
        }

        // Write AD. mustChangeAtNextLogin=false : c'est un compte de service,
        // le mot de passe est rotatif et ne doit pas exiger de changement.
        $ok = $this->users->changePasswordInAd($name, $password, mustChangeAtNextLogin: false);

        if (! $ok) {
            // Compteur volontairement NON avancé → rejoué au prochain tick.
            Log::error('TOTP reconcile: write AD échoué, compteur inchangé (retry au prochain tick)', [
                'name' => $name,
                'counter' => $current,
            ]);

            return 'failed';
        }

        $this->credentials->markApplied($name, $current);

        Log::info('TOTP reconcile: mot de passe AD synchronisé sur la fenêtre courante', [
            'name' => $name,
            'counter' => $current,
        ]);

        return 'applied';
    }
}
