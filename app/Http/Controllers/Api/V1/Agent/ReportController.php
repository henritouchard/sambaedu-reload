<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Agent\ReportRequest;
use App\Models\Workstation;
use App\Services\Agent\Reporting\ReportIngestService;
use App\Services\Agent\SyncRequestService;
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
 * Story 24.7 — « forcer la synchro » (décision n° 1/2) : après ingestion,
 * une éventuelle demande pendante (`agent_sync_requested_at`, posée par l'UI
 * via {@see SyncRequestService::request()}) est SOLDÉE
 * ({@see SyncRequestService::fulfill()} — remise à null + log
 * `agent.sync.fulfilled`). Le cycle agent étant GET(s) → … → report, tous
 * les contextes du cycle ont déjà bénéficié du bypass 304 avant ce solde.
 * C'est le SECOND (et dernier) écrivain de la colonne — l'invariant « 2
 * écrivains » de la décision n° 2.
 *
 * Middlewares (routes/api.php) : `auth.v1.secure-headers` + `throttle:60,1`
 * + `agent.token`. Erreurs 401/403 = formats du middleware 23.2, intouchés.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportIngestService $ingest,
        private readonly SyncRequestService $syncRequests,
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

        // Story 24.7 — solde de la demande « forcer la synchro » (no-op si
        // aucune demande n'était pendante : le cas nominal). Hors transaction
        // d'ingestion : l'écriture de la colonne agent_* est indépendante du
        // stockage D3 et idempotente.
        $this->syncRequests->fulfill($workstation);

        // Story 25.5 — greffe persistance de la version rapportée (AC4). La
        // version vient du payload VALIDÉ (`max:32`, jamais le brut), écrite
        // hors transaction D3 (iso fulfill / check-in). `ReportIngestService`
        // reste read-only sur `workstations` : la greffe est ICI, pas dans le
        // service. Colonnes hors $fillable → forceFill explicite. Le contrat de
        // report est inchangé (la version était déjà dans chaque payload).
        $this->persistReportedVersion($workstation, $report);

        return response()->json([
            'success' => true,
            'counts' => $counts,
        ]);
    }

    /**
     * Story 25.5 — persiste la version rapportée par l'agent (AC4).
     *
     * `agent_version` est `required` dans `ReportRequest` : `$report` la
     * contient toujours quand on arrive ici (validation passée). Garde de
     * forme défensive néanmoins (chaîne non vide) avant l'écriture, et
     * troncature à 32 (= largeur colonne / borne `max:32`) par sécurité —
     * SQLite n'applique pas les varchar en test. `forceFill` car les colonnes
     * `agent_*` sont volontairement hors `$fillable`. Écriture idempotente :
     * un re-report de la même version ne casse rien, `agent_reported_version_at`
     * est rafraîchi à chaque rapport (fraîcheur = donnée de progression).
     *
     * @param  array<string, mixed>  $report
     */
    private function persistReportedVersion(Workstation $workstation, array $report): void
    {
        $version = $report['agent_version'] ?? null;
        if (! is_string($version) || trim($version) === '') {
            return;
        }

        $workstation->forceFill([
            'agent_reported_version' => Str::limit(trim($version), 32, ''),
            'agent_reported_version_at' => now(),
        ])->save();
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
