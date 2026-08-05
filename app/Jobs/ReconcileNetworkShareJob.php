<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NetworkShare;
use App\Services\Filesystem\NetworkShareService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Story 60.4 — RÉCONCILIATION ENFILÉE d'un répertoire réseau géré.
 *
 * **Pourquoi enfiler.** La pose des droits est quadratique en nombre d'entrées
 * nominatives (mesuré : 0,32 s à 200, 7,16 s à 1 000, 63 s à 3 000). Dans le cycle
 * d'une requête d'écran, elle fait attendre l'administrateur sans rien lui
 * apprendre.
 *
 * ---------------------------------------------------------------------------
 * **LA CHARGE UTILE EST FAITE D'IDENTIFIANTS, ET DE RIEN D'AUTRE.**
 *
 * Deux raisons, et elles sont indépendantes :
 *
 *  1. **Le plan serait périmé.** La source autoritaire est la base. Un plan
 *     sérialisé au moment du clic serait un instantané ; s'il était rejoué plus
 *     tard, une assignation ajoutée entre-temps serait ÉCRASÉE par une intention
 *     dépassée. La projection se refait donc ICI, au moment de l'exécution.
 *
 *  2. **Un rapport ne se sérialise pas.** Les rapports de la ligne de contrat
 *     REFUSENT la sérialisation native, parce que leur complétude est un invariant
 *     de construction que la désérialisation contournerait — elle restaure les
 *     propriétés sans passer par aucune fabrique. Cette file de traitement est
 *     exactement le cas qui l'aurait contourné en silence : le jour où quelqu'un
 *     met un rapport ou un plan dans cette charge utile, la sérialisation ÉCHOUE
 *     BRUYAMMENT au point du mésusage. C'est le comportement voulu, pas un
 *     obstacle à contourner — et un test le dit.
 *
 * Ce traitement exécute donc EN DIRECT (il est déjà hors requête) et ne rend
 * rien : le dernier rapport est mis en cache par l'orchestrateur, en tableau, et
 * l'écran le relit au rafraîchissement suivant.
 *
 * **Pas de sérialisation de modèle non plus** : l'identifiant suffit, et le
 * répertoire peut avoir été supprimé entre l'enfilage et l'exécution — cas normal,
 * traité comme tel.
 */
class ReconcileNetworkShareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * File PERSISTANTE : un traitement perdu au redémarrage laisserait un écart
     * entre l'intention enregistrée en base et les droits en place, sans que
     * personne ne l'apprenne. Positionnée au constructeur (la propriété est déjà
     * déclarée par le trait de mise en file).
     */
    public const CONNECTION = 'database';

    public function __construct(
        public readonly int $shareId,
        public readonly ?string $performedBy = null,
    ) {
        $this->onConnection(self::CONNECTION);
    }

    public function handle(NetworkShareService $service): void
    {
        $share = NetworkShare::find($this->shareId);

        if ($share === null) {
            // Supprimé entre l'enfilage et l'exécution : son déprovisionnement a
            // déjà eu lieu, synchronement, avant la suppression de la ligne. Il
            // n'y a rien à réconcilier, et surtout rien à recréer.
            Log::info('ReconcileNetworkShareJob: répertoire disparu, réconciliation sans objet', [
                'share_id' => $this->shareId,
            ]);

            return;
        }

        $service->reconcile($share, $this->performedBy);
    }
}
