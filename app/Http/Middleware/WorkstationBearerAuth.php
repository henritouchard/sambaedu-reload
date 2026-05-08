<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Workstation;
use App\Wpkg\Deployment\Models\WorkstationApiSecret;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 15.5 / AC1.2 — Middleware d'authentification Phase 2 pour l'endpoint
 * d'ingestion `POST /api/[v1/]wpkg/reports/{hostname}`.
 *
 * Comportement :
 *   1. Si header `Authorization: Bearer {secret}` présent
 *      → vérifie via `WorkstationApiSecret::verify()` (couvre rotation 7j).
 *   2. Si header absent
 *      → fallback sur l'allowlist IP Phase 1 (`EnsureLocalRequest`)
 *        jusqu'à 15.7 (compat transitoire).
 *
 * Causes 401 :
 *   - secret invalide (pas de match `secret_hash` ni `previous_secret_hash`)
 *   - secret révoqué (`revoked_at IS NOT NULL`)
 *   - secret de l'ancien hash expiré (`previous_valid_until` passé)
 *   - hostname inconnu
 *
 * Tous les échecs auth Phase 2 (Bearer présent mais KO) sont loggés sur le
 * channel `wpkg-deploy` (évite les 401 silencieux).
 *
 * Tous les échecs Phase 1 fallback (Bearer absent ET IP non autorisée)
 * retournent 401 (pas 403 — uniformité de la réponse côté client).
 */
final class WorkstationBearerAuth
{
    private const ALWAYS_ALLOWED_IPS = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization', '');
        $hostname = $this->extractHostnameFromPath($request);

        // Mode Bearer — Phase 2.
        if ($authHeader !== '' && str_starts_with($authHeader, 'Bearer ')) {
            return $this->handleBearer($request, $next, $authHeader, $hostname);
        }

        // Fallback Phase 1 — IP allowlist (transitoire jusqu'à 15.7).
        if ($this->isFromAllowedIp($request)) {
            return $next($request);
        }

        // Compatibilité 9.4 : sans Bearer ET IP non-locale → 403 (sémantique
        // historique de `EnsureLocalRequest`). Les rejets 401 sont réservés
        // aux tentatives Bearer présentes mais invalides (sémantique 15.5).
        Log::channel('wpkg-deploy')->warning('[WorkstationBearerAuth] auth refusée', [
            'event' => 'wpkg_auth_failed',
            'reason' => 'bearer_absent_phase1_fallback_denied',
            'hostname' => $hostname,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Accès refusé : requête non locale.',
        ], Response::HTTP_FORBIDDEN);
    }

    private function handleBearer(
        Request $request,
        Closure $next,
        string $authHeader,
        ?string $hostname,
    ): Response {
        $secret = trim(substr($authHeader, 7)); // "Bearer ".length === 7

        if ($secret === '' || $hostname === null) {
            return $this->logAndReject($request, $hostname, 'bearer_malformed_or_no_hostname');
        }

        $workstation = Workstation::where('name', $hostname)->first();
        if ($workstation === null) {
            return $this->logAndReject($request, $hostname, 'workstation_not_found');
        }

        $secretRow = WorkstationApiSecret::where('workstation_id', $workstation->id)->first();
        if ($secretRow === null) {
            return $this->logAndReject($request, $hostname, 'no_secret_provisioned');
        }

        if ($secretRow->isRevoked()) {
            return $this->logAndReject($request, $hostname, 'bearer_revoked');
        }

        if (! $secretRow->verify($secret)) {
            return $this->logAndReject($request, $hostname, 'bearer_invalid');
        }

        // Auth OK — touch last_used_at (best-effort, non-bloquant).
        $secretRow->touchLastUsed();

        return $next($request);
    }

    /**
     * Extrait le hostname du path : `/api/v1/wpkg/reports/{hostname}` ou
     * `/api/wpkg/reports/{hostname}`. Retourne null si pas dans une route
     * compatible.
     */
    private function extractHostnameFromPath(Request $request): ?string
    {
        // Le middleware est attaché aux routes Wpkg report → on récupère le
        // paramètre de route directement.
        $hostname = $request->route('hostname');

        if (is_string($hostname) && $hostname !== '') {
            return $hostname;
        }

        return null;
    }

    private function isFromAllowedIp(Request $request): bool
    {
        $clientIp = (string) ($request->server('REMOTE_ADDR') ?? '');

        if (IpUtils::checkIp($clientIp, self::ALWAYS_ALLOWED_IPS)) {
            return true;
        }

        $configIps = config('sambaedu.wpkg.report_ingestion_allowed_ips', '');

        if (empty($configIps)) {
            return false;
        }

        $list = is_array($configIps)
            ? $configIps
            : array_map('trim', explode(',', $configIps));

        $list = array_filter($list, static fn (string $ip): bool => $ip !== '');

        if (empty($list)) {
            return false;
        }

        return IpUtils::checkIp($clientIp, $list);
    }

    private function logAndReject(Request $request, ?string $hostname, string $reason): Response
    {
        Log::channel('wpkg-deploy')->warning('[WorkstationBearerAuth] auth refusée', [
            'event' => 'wpkg_auth_failed',
            'reason' => $reason,
            'hostname' => $hostname,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Authentification refusée.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
