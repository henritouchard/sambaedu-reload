<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1\ControlHub;

use App\Http\Controllers\Controller;
use App\Models\ControlHubLinkAuditLog;
use App\Services\ControlHub\ControlHubContractSeveranceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 32.1 (FR7 + NFR5) — Endpoint de RÉCEPTION du signal de rupture du lien
 * amont (controlHub), authentifié par le middleware `controlhub.auth`.
 *
 * Canal jumeau de la commande artisan `controlhub:sever-link` (Q4) : les DEUX
 * partagent le service UNIQUE {@see ControlHubContractSeveranceService}. La forme
 * exacte du payload wire relève d'Epic 33 ; ici elle reste MINIMALE (motif
 * optionnel). L'acteur tracé est dérivé du type de clé controlHub posé par le
 * middleware (`controlhub_key_type`).
 *
 * Idempotent : un signal en standalone OU sur un contrat déjà `severed` renvoie
 * `severed=false` (no-op, 200) sans rien écrire.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central ». Vocabulaire « amont » / `ControlHub*`.
 * [Source: prd-contrat-manage-se5.md#R3]
 */
class LinkSeveranceController extends Controller
{
    public function __invoke(
        Request $request,
        ControlHubContractSeveranceService $severanceService,
    ): JsonResponse {
        $reason = $request->input('reason');
        $keyType = $request->attributes->get('controlhub_key_type');

        $result = $severanceService->sever(
            origin: ControlHubLinkAuditLog::ORIGIN_API,
            actorLabel: is_string($keyType) && $keyType !== ''
                ? 'controlhub:'.$keyType
                : 'controlhub',
            reason: is_string($reason) && $reason !== '' ? $reason : null,
        );

        return response()->json([
            'success' => true,
            'severed' => $result->severed,
            'contract_id' => $result->contractId,
            'items_lifted' => $result->itemsLifted,
            'apps_preserved' => $result->appsPreserved,
            'values_materialized' => $result->valuesMaterialized,
            'applications_assigned' => $result->applicationsAssigned,
        ]);
    }
}
