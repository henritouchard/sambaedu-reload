<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Middleware;

use App\Auth\V1\Services\MigrationAttemptRecorder;
use App\Auth\V1\Support\JwtErrorCodes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 16.11 — AC2.1.
 *
 * Restreint l'accès aux endpoints `/api/v1/agent/enroll` et
 * `/api/v1/agent/bootstrap.{cmd,sh}` au LAN scolaire (subnets RFC1918 par
 * défaut, surchargeable via `AUTH_V1_BOOTSTRAP_ALLOWED_SUBNETS`).
 *
 * Mitigation D1 — contre fixation UUID re-enroll : combiné avec le couple
 * token↔UUID, un attaquant doit être à la fois sur le LAN ET capter un
 * token md5 valide ET deviner le bon uuid déclaré dans le contexte APCu —
 * trois conditions cumulées qui rendent l'attaque très peu réaliste en
 * environnement scolaire (LAN strict).
 *
 * Pattern iso `App\Http\Middleware\EnsureLocalRequest` (WPKG reports).
 *
 * **Choix techniques** :
 *
 *  - IP lue via `$request->server('REMOTE_ADDR')` — **non spoofable** par
 *    X-Forwarded-For (qui peut être attaquant-contrôlé sur LAN).
 *  - `Symfony\Component\HttpFoundation\IpUtils::checkIp()` pour le support
 *    CIDR + IPv6.
 *  - Default RFC1918 + localhost : 192.168.0.0/16, 10.0.0.0/8, 172.16.0.0/12,
 *    127.0.0.0/8, ::1/128. Override env CSV (`192.168.0.0/16,10.0.0.0/8,...`)
 *    ou array.
 *  - Refus 403 (pas 401) — la requête est syntaxiquement OK, c'est l'IP qui
 *    est rejetée → `forbidden`. Format `{success, error, message, code}`
 *    complet (D12).
 *
 * Appliqué UNIQUEMENT sur `/enroll` et `/bootstrap.*` — PAS sur `/refresh`
 * ni `/ping` (un poste en VPN admin légitime peut avoir une IP hors LAN
 * après enrollment).
 */
class EnsureLanIp
{
    /**
     * Default RFC1918 + localhost si `config('auth_v1.bootstrap.allowed_subnets')`
     * absent ou vide.
     *
     * @var list<string>
     */
    private const DEFAULT_SUBNETS = [
        '192.168.0.0/16',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '127.0.0.0/8',
        '::1/128',
    ];

    public function __construct(
        private readonly MigrationAttemptRecorder $attemptRecorder,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = (string) ($request->server('REMOTE_ADDR', '') ?? '');

        if ($this->isAllowed($clientIp)) {
            return $next($request);
        }

        Log::channel('auth-v1')->warning(
            '[EnsureLanIp] auth.bootstrap.lan_blocked',
            [
                'action_type' => 'auth.bootstrap.lan_blocked',
                'ip' => $clientIp,
                'requested_url' => $request->fullUrl(),
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
            ],
        );

        // Story 16.11 Q2 (Opus-D) — tracer le rejet pour `migration:health-check`.
        // L'uuid n'est pas extrait ici (le LAN check précède l'auth bootstrap-token).
        $this->attemptRecorder->recordFailure(
            $request,
            JwtErrorCodes::BOOTSTRAP_NOT_LAN,
            null,
            'Bootstrap endpoint is restricted to LAN (ip=' . $clientIp . ')',
        );

        return response()->json([
            'success' => false,
            'error' => 'forbidden',
            'message' => 'Bootstrap endpoint is restricted to LAN',
            'code' => JwtErrorCodes::BOOTSTRAP_NOT_LAN,
        ], 403);
    }

    /**
     * Vrai si `$ip` matche au moins un des subnets autorisés.
     */
    private function isAllowed(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $subnets = $this->resolveSubnets();
        if ($subnets === []) {
            // Sécurité par défaut : si admin a fixé `auth_v1.bootstrap.allowed_subnets`
            // à une string vide volontairement, on bloque tout (fail-closed).
            return false;
        }

        // IpUtils::checkIp gère les CIDR IPv4 + IPv6 + IP simples + bad input.
        return IpUtils::checkIp($ip, $subnets);
    }

    /**
     * Résout la liste des subnets autorisés :
     *  - depuis `config('auth_v1.bootstrap.allowed_subnets')` (string CSV ou array)
     *  - fallback DEFAULT_SUBNETS si null/vide
     *
     * @return list<string>
     */
    private function resolveSubnets(): array
    {
        $configured = config('auth_v1.bootstrap.allowed_subnets');

        if ($configured === null) {
            return self::DEFAULT_SUBNETS;
        }

        if (is_array($configured)) {
            $list = array_map(static fn ($v): string => trim((string) $v), $configured);
        } else {
            // String CSV.
            $list = array_map('trim', explode(',', (string) $configured));
        }

        $list = array_values(array_filter($list, static fn (string $v): bool => $v !== ''));

        if ($list === []) {
            // Configuration explicitement vide → fail-closed (cf. comportement
            // isAllowed). Ne PAS retomber sur DEFAULT_SUBNETS quand
            // l'admin a choisi un override (intentionnellement vide signale
            // un "ne laisse rien passer" qu'on doit honorer).
            return [];
        }

        return $list;
    }
}
