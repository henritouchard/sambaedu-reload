<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Agent\EnrollmentRequest;
use App\Services\Agent\Enrollment\EnrollmentService;
use Illuminate\Http\JsonResponse;

/**
 * Story 23.3 — `POST /api/v1/agent/enrollment` (route `agent.v1.enrollment`).
 *
 * Échange du ticket d'enrôlement one-time (émis à la génération de
 * l'unattend.xml — porte 1 iPXE) contre le token agent. Controller mince :
 * toute la logique vit dans {@see EnrollmentService::redeem()}, ici on ne
 * fait que mapper le résultat typé vers HTTP.
 *
 * Middlewares (routes/api.php) : `local.request` (LAN only) +
 * `auth.v1.secure-headers` (Cache-Control no-store — la réponse 200 porte le
 * token en clair, une seule fois) + `throttle:10,1`. PAS `agent.token` : le
 * poste n'a pas encore de token.
 *
 * Réponses (codes figés au contrat architecture) :
 *  - 200 `{success: true, token}` ;
 *  - 409 `{error, message, code: AGENT_ENROLL_CONFLICT}` — poste déjà
 *    enrôlé, rien n'est écrasé silencieusement ;
 *  - 403 `{error, message, code: AGENT_ENROLL_NOT_ALLOWED}` — tout le reste,
 *    sans oracle (porte 2 → Story 25.3).
 */
class EnrollController extends Controller
{
    public const CODE_CONFLICT = 'AGENT_ENROLL_CONFLICT';
    public const CODE_NOT_ALLOWED = 'AGENT_ENROLL_NOT_ALLOWED';

    public function __construct(
        private readonly EnrollmentService $enrollment,
    ) {
    }

    public function store(EnrollmentRequest $request): JsonResponse
    {
        $result = $this->enrollment->redeem(
            (string) ($request->validated('ticket') ?? ''),
            [
                'uuid' => $request->validated('uuid'),
                'mac' => $request->validated('mac'),
                'hostname' => $request->validated('hostname'),
            ],
        );

        if ($result->enrolled) {
            return response()->json([
                'success' => true,
                'token' => $result->token,
            ]);
        }

        if ($result->conflict) {
            return response()->json([
                'error' => 'conflict',
                'message' => 'Workstation already enrolled',
                'code' => self::CODE_CONFLICT,
            ], 409);
        }

        return response()->json([
            'error' => 'forbidden',
            'message' => 'Enrollment not allowed',
            'code' => self::CODE_NOT_ALLOWED,
        ], 403);
    }
}
