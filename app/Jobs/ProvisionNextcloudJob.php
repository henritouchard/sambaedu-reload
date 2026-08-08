<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Nextcloud\NextcloudProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Story 61.1 — LE PROVISIONNEMENT NEXTCLOUD, ENFILÉ.
 *
 * **Pourquoi enfiler.** Le balayage du stock est linéaire en nombre d'utilisateurs,
 * et chaque compte non encore résolu coûte jusqu'à deux allers-retours réseau vers
 * l'instance. Dans le cycle d'une requête d'écran, cela fait attendre
 * l'administrateur sans rien lui apprendre — et le premier timeout HTTP laisserait
 * un provisionnement à moitié fait dont personne ne saurait où il s'est arrêté.
 *
 * ---------------------------------------------------------------------------
 * **LA CHARGE UTILE NE PORTE QUE DES IDENTIFIANTS** (patron 60.4). Pas de rapport,
 * pas de configuration, pas de secret. Deux raisons indépendantes :
 *
 *  1. **La configuration serait périmée.** L'autorité est l'état persisté
 *     (`files.policy` + `service_credentials`). Un instantané sérialisé au moment
 *     du clic serait rejoué plus tard avec une URL ou un secret que
 *     l'administrateur vient peut-être de corriger.
 *  2. **Un secret ne se met pas dans une file.** La table des travaux est en clair
 *     et survit à l'exécution ; y déposer l'app password admin le rendrait lisible
 *     à quiconque lit la file, et à toute sauvegarde de la base. Le seul domicile
 *     du secret est `service_credentials`, chiffré.
 * ---------------------------------------------------------------------------
 *
 * Le traitement ne rend rien : le dernier rapport est mis en cache par le service,
 * en tableau, et l'écran le relit au rafraîchissement suivant.
 */
class ProvisionNextcloudJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * File PERSISTANTE : un provisionnement perdu au redémarrage laisserait
     * l'instance sans montage sans que personne ne l'apprenne.
     */
    public const CONNECTION = 'database';

    /**
     * ---------------------------------------------------------------------------
     * **LE DÉLAI MAXIMAL EST DÉCLARÉ ICI, ET IL EST INFÉRIEUR AU TTL DU VERROU.**
     *
     * Les unités des ouvriers (`scripts/config/laravel-queue-*.service`) lancent
     * `queue:work` avec `--max-time` mais **sans `--timeout`** : sans cette
     * propriété, le délai par job resterait le défaut du framework — 60 secondes.
     * Or l'AC8 anticipe explicitement de très grandes populations, chaque compte
     * non résolu coûtant jusqu'à deux allers-retours réseau. Passé le délai,
     * l'ouvrier envoie un SIGKILL, que PHP ne peut pas intercepter : le
     * `finally { $lock->release(); }` du service **ne s'exécute jamais**.
     *
     * D'où l'ordre à ne jamais inverser : **le verrou doit survivre à l'exécution
     * la plus longue permise**, donc `timeout < LOCK_SECONDS`. Ici 1500 s contre
     * 1800 s de verrou : cinq minutes de marge, largement de quoi absorber le
     * décalage entre le moment où l'ouvrier arme son alarme et celui où le service
     * pose son verrou. L'inverse (timeout ≥ TTL) rendrait la file muette pendant
     * jusqu'à une demi-heure, avec un écran sans aucune trace de l'exécution
     * interrompue. Un test de garde casse si quelqu'un désaligne les deux valeurs.
     * ---------------------------------------------------------------------------
     */
    public int $timeout = 1500;

    /**
     * **Une seule tentative, explicitement.** Un provisionnement rejoué en boucle
     * sur une instance en panne est un défaut à part entière : chaque rejeu
     * repaie le balayage complet, et le verrou du service n'a aucune raison
     * d'être encore libre au moment où l'ouvrier remet le travail en file. La
     * dégradation se dit par le rapport (compteurs, code de sortie), pas par une
     * insistance silencieuse ; relancer est un geste d'exploitation — le bouton
     * ou `nextcloud:provision`.
     */
    public int $tries = 1;

    public function __construct(
        /** Login de l'opérateur — trace, jamais autorisation (la garde est sur l'écran). */
        public readonly ?string $performedBy = null,
    ) {
        $this->onConnection(self::CONNECTION);
    }

    public function handle(NextcloudProvisioningService $service): void
    {
        $report = $service->run();

        Log::info('nextcloud.provision.job.done', [
            'performed_by' => $this->performedBy ?? 'system',
            'exit_code' => $report->exitCode(),
            'mounts' => count($report->mounts()),
            'users' => $report->userCounters(),
        ]);
    }
}
