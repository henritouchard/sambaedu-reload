<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Controllers;

use App\Auth\V1\Jwt\WorkstationJwtClaims;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Story 16.10 — AC5.3 / D5.
 *
 * Endpoint `GET /api/v1/agent/ping` — echo de test minimaliste pour valider
 * la chaîne complète (signature JWT → middleware → claim parsing → context
 * binding).
 *
 *  - **Pas de logique métier**. Lecture des claims JWT uniquement.
 *  - Réutilisé par 16.11 comme health-check post-enrollment.
 *  - Réponse :
 *      ```
 *      {
 *        "success": true,
 *        "message": "pong",
 *        "workstation_uuid": "<sub>",
 *        "server_time": "2026-05-16T17:48:32+00:00",
 *        "api_version": "v1",
 *        "se4fs_name": "se4fs-0991229y"
 *      }
 *      ```
 */
class PingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $claims = $request->attributes->get('auth_v1.jwt_claims');
        if (! $claims instanceof WorkstationJwtClaims) {
            // Sécurité défense en profondeur : si la route est appelée sans
            // le middleware (test sans `withoutMiddleware`), on refuse.
            throw new RuntimeException(
                'auth_v1.jwt_claims attribute missing — auth.v1.workstation middleware required'
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'pong',
            'workstation_uuid' => $claims->workstationUuid(),
            'server_time' => Carbon::now()->toIso8601String(),
            'api_version' => 'v1',
            'se4fs_name' => (string) config('sambaedu.se4fs_name', ''),
        ], 200);
    }
}
