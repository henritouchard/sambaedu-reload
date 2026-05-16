<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Middleware;

use App\Auth\V1\Jwt\WorkstationJwtRefreshService;
use App\Auth\V1\Models\WorkstationRefreshToken;
use App\Auth\V1\Support\JwtErrorCodes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 16.10 — AC4.3 / D10.
 *
 * Protège `POST /api/v1/agent/refresh`. Lit `refresh_token` du body JSON,
 * lookup le hash en DB, gère replay detection.
 *
 * **États possibles** :
 *
 *  - `refresh_token` absent / malformé → 400 `refresh.missing`
 *  - Hash inconnu en DB → 401 `refresh.invalid`
 *  - Hash trouvé mais `revoked_at != null` → **REPLAY**, cascade revocation
 *    via `WorkstationJwtRefreshService::handleReplay`, 401 `refresh.replay_detected`
 *  - Hash trouvé mais `expires_at <= now` → 401 `refresh.expired`
 *  - OK → injecte le record dans `request->attributes['auth_v1.refresh_token_record']`
 *
 * Le controller (`RefreshController::store`) prend le relais pour faire la
 * rotation effective.
 *
 * **Important** : la regex `refresh_token` (64 hex chars) est aussi validée
 * par `RefreshTokenRequest` côté FormRequest. Le middleware fait sa propre
 * validation pour pouvoir formater une réponse `code` cohérente avec D8.
 */
class EnsureRefreshToken
{
    public function __construct(
        private readonly WorkstationJwtRefreshService $refreshService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $payload = $request->json()->all();
        $refreshToken = is_array($payload) && isset($payload['refresh_token']) && is_string($payload['refresh_token'])
            ? trim($payload['refresh_token'])
            : '';

        if ($refreshToken === '' || ! preg_match('/^[a-f0-9]{64}$/i', $refreshToken)) {
            return $this->errorResponse(
                JwtErrorCodes::REFRESH_MISSING,
                'Missing or malformed refresh_token',
                400,
                'bad_request',
            );
        }

        $hash = hash('sha256', $refreshToken);

        $record = WorkstationRefreshToken::query()
            ->where('refresh_token_hash', $hash)
            ->first();

        if ($record === null) {
            return $this->errorResponse(
                JwtErrorCodes::REFRESH_INVALID,
                'Refresh token not recognized',
                401,
            );
        }

        // Détection replay : hash valide mais déjà révoqué.
        if ($record->revoked_at !== null) {
            $this->refreshService->handleReplay($record);

            return $this->errorResponse(
                JwtErrorCodes::REFRESH_REPLAY_DETECTED,
                'Refresh token replay detected — all sessions revoked',
                401,
            );
        }

        // Expiré ? Fail-secure : si le cast Eloquent n'a pas produit un Carbon
        // (cast échoué, valeur DB corrompue, NULL), on traite comme expiré
        // pour ne pas laisser passer un token sans expiration vérifiable.
        $expiresAt = $record->expires_at instanceof Carbon
            ? $record->expires_at
            : null;
        if ($expiresAt === null || $expiresAt->lessThanOrEqualTo(Carbon::now())) {
            return $this->errorResponse(
                JwtErrorCodes::REFRESH_EXPIRED,
                'Refresh token expired',
                401,
            );
        }

        $request->attributes->set('auth_v1.refresh_token_record', $record);

        return $next($request);
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        string $errorLabel = 'unauthorized',
    ): JsonResponse {
        return response()->json([
            'error' => $errorLabel,
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}
