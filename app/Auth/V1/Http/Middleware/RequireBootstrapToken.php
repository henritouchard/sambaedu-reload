<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Middleware;

use App\Auth\V1\Services\LegacyBootstrapTokenValidator;
use App\Auth\V1\Support\JwtErrorCodes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 16.10 — AC4.2.
 *
 * Protège `POST /api/v1/agent/enroll` par un `X-Bootstrap-Token` md5 valide.
 * Le token est validé contre l'APCu legacy via
 * {@see LegacyBootstrapTokenValidator}.
 *
 * **Ne consomme PAS le token côté APCu** (cf. dev notes — race condition
 * retry réseau).
 *
 * Log `auth.bootstrap.attempted` info en cas de succès, warning si échec.
 * Le token clear n'est jamais loggé (uniquement 8 premiers chars du hash
 * sha256 pour corrélation).
 */
class RequireBootstrapToken
{
    public function __construct(
        private readonly LegacyBootstrapTokenValidator $validator,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = trim((string) $request->header('X-Bootstrap-Token', ''));

        if ($token === '') {
            $this->logAttempt($request, null, false, 'missing');

            return $this->errorResponse(
                JwtErrorCodes::BOOTSTRAP_TOKEN_MISSING,
                'Missing X-Bootstrap-Token header',
            );
        }

        if (! $this->validator->isValid($token)) {
            $this->logAttempt($request, $token, false, 'invalid_or_expired');

            return $this->errorResponse(
                JwtErrorCodes::BOOTSTRAP_TOKEN_INVALID,
                'Invalid or expired bootstrap token',
            );
        }

        $this->logAttempt($request, $token, true, 'ok');

        return $next($request);
    }

    private function errorResponse(string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => 'unauthorized',
            'message' => $message,
            'code' => $code,
        ], 401);
    }

    /**
     * Log une tentative — token clear jamais loggé.
     */
    private function logAttempt(Request $request, ?string $token, bool $success, string $outcome): void
    {
        $context = [
            'action_type' => 'auth.bootstrap.attempted',
            'success' => $success,
            'outcome' => $outcome,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 200),
        ];
        if ($token !== null && $token !== '') {
            // Hash partiel pour corrélation (8 chars) — pas le token clear.
            $context['token_hash_prefix'] = substr(hash('sha256', $token), 0, 8);
        }

        $level = $success ? 'info' : 'warning';
        Log::channel('auth-v1')->{$level}('[RequireBootstrapToken] auth.bootstrap.attempted', $context);
    }
}
