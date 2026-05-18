<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Middleware;

use App\Auth\V1\Jwt\Exceptions\InvalidJwtException;
use App\Auth\V1\Jwt\WorkstationJwtVerifier;
use App\Auth\V1\Support\JwtErrorCodes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 16.10 — AC4.1.
 *
 * Middleware Laravel qui protège les routes `/api/v1/agent/*` (sauf
 * `/enroll` et `/refresh`) par un JWT RS256 `tier=workstation` valide.
 *
 * Extrait le JWT du header `Authorization: Bearer <jwt>`. Délègue la
 * validation cryptographique + révocation au `WorkstationJwtVerifier`.
 * En cas de succès, injecte dans `$request->attributes` :
 *
 *  - `auth_v1.workstation_uuid` (string) : claim `sub`
 *  - `auth_v1.jwt_claims` ({@see WorkstationJwtClaims}) : DTO complet
 *
 * Réponse erreur (D8) — JSON `{error, message, code}` HTTP 401.
 */
class EnsureWorkstationJwt
{
    public function __construct(
        private readonly WorkstationJwtVerifier $verifier,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $jwt = $this->extractJwt($request);
        if ($jwt === null) {
            return $this->errorResponse(JwtErrorCodes::JWT_MISSING, 'Missing Authorization header', 401);
        }

        try {
            $claims = $this->verifier->verify($jwt, ['expected_tier' => 'workstation']);
        } catch (InvalidJwtException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $request->attributes->set('auth_v1.workstation_uuid', $claims->workstationUuid());
        $request->attributes->set('auth_v1.jwt_claims', $claims);

        return $next($request);
    }

    /**
     * Extrait le JWT du header Authorization: Bearer (iso-pattern
     * ControlHubAuth).
     */
    private function extractJwt(Request $request): ?string
    {
        $authHeader = $request->header('Authorization');
        if (! is_string($authHeader)) {
            return null;
        }
        if (! str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }
        $jwt = trim(substr($authHeader, 7));

        return $jwt === '' ? null : $jwt;
    }

    private function errorResponse(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => $status === 401 ? 'unauthorized' : 'forbidden',
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}
