<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Agent\ReportRequest;
use App\Models\Workstation;
use App\Services\Agent\Reporting\ReportIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Story 24.1 — `POST /api/v1/agent/report` (route `agent.v1.report`).
 *
 * Chemin retour du canal agent desired-state (FR8) : l'agent rapporte la
 * conformité par item, le serveur stocke en volume borné (D3/FR9). La
 * réponse est un ACK serveur→agent au format SE5 `{success, counts}` —
 * contrairement à `GET /state`, AUCUN hash n'est calculé sur ce corps :
 * le wrapper est licite ici (seul le state sert le contrat brut).
 *
 * Controller mince : la validation vit dans {@see ReportRequest} (422
 * AVANT toute écriture — defer review 23.1 résolu), l'ingestion dans
 * {@see ReportIngestService}, l'auth et toutes les écritures `workstations`
 * (check-in, rotation, header X-Agent-New-Token) dans le middleware
 * `agent.token` — ici AUCUNE écriture hors délégation au service.
 *
 * Identité = le token (décision n° 1) : le bloc `workstation` du payload
 * est déclaratif (debug agent) — une divergence avec le poste authentifié
 * est loggée en warning, l'ingestion POURSUIT (l'anti-clonage MAC est le
 * travail du middleware 23.2, pas du report).
 *
 * Middlewares (routes/api.php) : `auth.v1.secure-headers` + `throttle:60,1`
 * + `agent.token`. Erreurs 401/403 = formats du middleware 23.2, intouchés.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportIngestService $ingest,
    ) {
    }

    public function store(ReportRequest $request): JsonResponse
    {
        /** @var Workstation $workstation */
        $workstation = $request->attributes->get('agent.workstation');
        $report = $request->validated();

        $this->warnIfDeclaredIdentityDiverges($workstation, $report);

        // History de debug = payload BRUT (champs inconnus §9 inclus, review
        // 24.1 #2) — validated() les stripperait de l'historique.
        $counts = $this->ingest->ingest($workstation, $report, $request->json()->all());

        return response()->json([
            'success' => true,
            'counts' => $counts,
        ]);
    }

    /**
     * Divergence identité déclarée vs poste authentifié (décision n° 1) :
     * warning, jamais de refus — le payload n'est PAS une identité.
     * Comparaisons insensibles à la casse (sémantique hostname/uuid),
     * contexte borné avant log (input user-controlled, conventions 23.2).
     *
     * @param  array<string, mixed>  $report
     */
    private function warnIfDeclaredIdentityDiverges(Workstation $workstation, array $report): void
    {
        $declared = $report['workstation'] ?? null;
        if (! is_array($declared)) {
            return;
        }

        $hostname = $declared['hostname'] ?? null;
        $uuid = $declared['uuid'] ?? null;

        $hostnameDiverges = is_string($hostname) && trim($hostname) !== ''
            && $workstation->name !== null
            && strcasecmp(trim($hostname), $workstation->name) !== 0;
        $uuidDiverges = is_string($uuid) && trim($uuid) !== ''
            && $workstation->uuid !== null
            && strcasecmp(trim($uuid), (string) $workstation->uuid) !== 0;

        if (! $hostnameDiverges && ! $uuidDiverges) {
            return;
        }

        Log::channel('agent')->warning('[ReportController] agent.report.identity_mismatch', [
            'action_type' => 'agent.report.identity_mismatch',
            'workstation_id' => $workstation->id,
            'declared_hostname' => is_string($hostname) ? Str::limit($hostname, 255) : null,
            'declared_uuid' => is_string($uuid) ? Str::limit($uuid, 64) : null,
        ]);
    }
}
