<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\Workstation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `POST /api/v1/agent/shutdown` (route `agent.v1.shutdown`).
 *
 * Signal d'extinction best-effort : le service Windows l'envoie au
 * `svc.Shutdown` (arrêt/redémarrage machine — PAS au stop manuel du
 * service, où le poste reste allumé). Pose `agent_reported_offline_at`,
 * comparé à `agent_last_checkin_at` par {@see Workstation::agentPresence()} :
 * signal >= dernier check-in → présence « éteint » immédiate, au lieu
 * d'attendre le seuil de silence (2 × ttl ≈ 2 h). Le check-in du boot
 * suivant, plus récent, rend le signal inopérant — aucun nettoyage requis.
 *
 * Le corps est vide et ignoré : l'identité est le token (middleware
 * `agent.token`, qui porte aussi check-in et rotation — ce controller ne
 * touche que le timestamp de présence). Un signal perdu (coupure brutale,
 * timeout) est bénin : le seuil de silence reste le filet.
 */
class PresenceController extends Controller
{
    public function shutdown(Request $request): Response
    {
        /** @var Workstation $workstation */
        $workstation = $request->attributes->get('agent.workstation');

        $workstation->agent_reported_offline_at = now();
        $workstation->save();

        return response()->noContent();
    }
}
