<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Middleware;

use App\Auth\V1\Services\LegacyBootstrapTokenValidator;
use App\Auth\V1\Services\MigrationAttemptRecorder;
use App\Auth\V1\Support\JwtErrorCodes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 16.10 — AC4.2.
 * Story 16.11 — AC3.1 (durcissement couple token↔UUID).
 *
 * Protège `POST /api/v1/agent/enroll` par un `X-Bootstrap-Token` md5 valide.
 * Le token est validé contre l'APCu legacy via
 * {@see LegacyBootstrapTokenValidator}.
 *
 * **Ne consomme PAS le token côté APCu** (cf. dev notes — race condition
 * retry réseau).
 *
 * **Story 16.11 — couple token↔UUID** :
 *
 *  - Le middleware extrait le `uuid` du body de la requête (`$request->json('uuid')`)
 *    si présent et au format UUID v4. Il est ensuite passé au validator
 *    durci `isValid($token, $uuid)`. Le format UUID est vérifié par une regex
 *    stricte pour ne pas envoyer n'importe quoi au validator.
 *  - Si le token est valide en APCu mais que l'uuid déclaré ≠ uuid du
 *    contexte → retour 401 `bootstrap_token.uuid_mismatch` (au lieu de
 *    `bootstrap_token.invalid`). Discrimination utile pour le poste qui
 *    doit pouvoir distinguer "ré-essaie avec un autre token" vs "stop, tu
 *    n'as pas le droit de prendre cet uuid".
 *
 * Log `auth.bootstrap.attempted` info en cas de succès, warning si échec.
 * Le token clear n'est jamais loggé (uniquement 8 premiers chars du hash
 * sha256 pour corrélation).
 */
class RequireBootstrapToken
{
    /** Regex stricte UUID v4 — accepte upper/lower case, version 4 strict. */
    private const UUID_V4_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly LegacyBootstrapTokenValidator $validator,
        private readonly MigrationAttemptRecorder $attemptRecorder,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = trim((string) $request->header('X-Bootstrap-Token', ''));

        if ($token === '') {
            $this->logAttempt($request, null, false, 'missing');
            $this->attemptRecorder->recordFailure(
                $request,
                JwtErrorCodes::BOOTSTRAP_TOKEN_MISSING,
                $this->extractDeclaredUuid($request),
                'Missing X-Bootstrap-Token header',
            );

            return $this->errorResponse(
                JwtErrorCodes::BOOTSTRAP_TOKEN_MISSING,
                'Missing X-Bootstrap-Token header',
            );
        }

        $declaredUuid = $this->extractDeclaredUuid($request);

        // Story 16.11 — si on a un uuid format valide, validation durcie
        // (couple token↔UUID via APCu).
        if ($declaredUuid !== null) {
            if (! $this->validator->isValid($token, $declaredUuid)) {
                // Discrimine `invalid` (token introuvable APCu) vs `uuid_mismatch`
                // (token OK mais uuid déclaré ≠ uuid du contexte).
                if ($this->validator->checkMismatch($token, $declaredUuid)) {
                    $this->logAttempt($request, $token, false, 'uuid_mismatch');
                    $this->attemptRecorder->recordFailure(
                        $request,
                        JwtErrorCodes::BOOTSTRAP_TOKEN_UUID_MISMATCH,
                        $declaredUuid,
                        'Bootstrap token does not match declared workstation_uuid',
                    );

                    return $this->errorResponse(
                        JwtErrorCodes::BOOTSTRAP_TOKEN_UUID_MISMATCH,
                        'Bootstrap token does not match declared workstation_uuid',
                    );
                }

                $this->logAttempt($request, $token, false, 'invalid_or_expired');
                $this->attemptRecorder->recordFailure(
                    $request,
                    JwtErrorCodes::BOOTSTRAP_TOKEN_INVALID,
                    $declaredUuid,
                    'Invalid or expired bootstrap token',
                );

                return $this->errorResponse(
                    JwtErrorCodes::BOOTSTRAP_TOKEN_INVALID,
                    'Invalid or expired bootstrap token',
                );
            }
        } elseif (! $this->validator->isValid($token)) {
            // Pas d'uuid déclaré — validation 16.10 standard (APCu présent suffit).
            $this->logAttempt($request, $token, false, 'invalid_or_expired');
            $this->attemptRecorder->recordFailure(
                $request,
                JwtErrorCodes::BOOTSTRAP_TOKEN_INVALID,
                null,
                'Invalid or expired bootstrap token (no uuid declared)',
            );

            return $this->errorResponse(
                JwtErrorCodes::BOOTSTRAP_TOKEN_INVALID,
                'Invalid or expired bootstrap token',
            );
        }

        $this->logAttempt($request, $token, true, 'ok');

        return $next($request);
    }

    /**
     * Extrait un UUID v4 strict du body de la requête (json ou input). Retourne
     * null si absent / vide / format invalide.
     */
    private function extractDeclaredUuid(Request $request): ?string
    {
        $candidate = trim((string) ($request->json('uuid', $request->input('uuid', '')) ?? ''));

        if ($candidate === '') {
            return null;
        }

        if (preg_match(self::UUID_V4_REGEX, $candidate) !== 1) {
            // Format invalide → on le traite comme non-fourni (le FormRequest
            // métier `EnrollAgentRequest` retournera 422 plus tard si besoin).
            return null;
        }

        return strtolower($candidate);
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
