<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Controllers;

use App\Auth\V1\Http\Requests\RefreshTokenRequest;
use App\Auth\V1\Jwt\WorkstationJwtRefreshService;
use App\Auth\V1\Models\WorkstationRefreshToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Story 16.10 — AC5.2.
 *
 * Endpoint `POST /api/v1/agent/refresh`.
 *
 *  - Amont protégé par `EnsureRefreshToken` (qui gère replay detection
 *    avec cascade revocation).
 *  - `EnsureRefreshToken` injecte `request->attributes['auth_v1.refresh_token_record']`
 *    si le token est valide.
 *  - Le controller délègue la rotation à `WorkstationJwtRefreshService::refresh`.
 *
 * Pas de `ca_cert_pem` ni `server_base_url` dans la réponse refresh — le
 * poste les a déjà depuis son enroll initial.
 */
class RefreshController extends Controller
{
    public function __construct(
        private readonly WorkstationJwtRefreshService $refreshService,
    ) {
    }

    public function store(RefreshTokenRequest $request): JsonResponse
    {
        $record = $request->attributes->get('auth_v1.refresh_token_record');
        if (! $record instanceof WorkstationRefreshToken) {
            // Ne devrait jamais arriver : si on est ici, c'est que le
            // middleware EnsureRefreshToken a laissé passer la requête.
            throw new RuntimeException(
                'EnsureRefreshToken middleware did not inject auth_v1.refresh_token_record'
            );
        }

        $payload = $this->refreshService->refresh($record);

        return response()->json([
            'success' => true,
            'message' => 'Refresh rotated',
            'access_token' => $payload['access_token'],
            'refresh_token' => $payload['refresh_token'],
            'token_type' => $payload['token_type'],
            'expires_in' => $payload['expires_in'],
            'refresh_expires_in' => $payload['refresh_expires_in'],
        ], 200);
    }
}
