<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Wpkg\Deployment\Services\WpkgDeploymentSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de restriction IP pour les endpoints d'ingestion locale.
 *
 * Autorise uniquement :
 *   - 127.0.0.1 et ::1 (localhost)
 *   - Les CIDR/IPs listés dans config('sambaedu.wpkg.report_ingestion_allowed_ips')
 *
 * Utilise Symfony\Component\HttpFoundation\IpUtils::checkIp() pour le support CIDR.
 *
 * En Phase 1, le worker tourne sur la même VM → pas d'exposition externe.
 * En Phase 2, on ajoutera un check token machine ici sans toucher le controller/service.
 */
class EnsureLocalRequest
{
    /**
     * IPs toujours autorisées (localhost IPv4 + IPv6).
     */
    private const ALWAYS_ALLOWED = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        // REMOTE_ADDR : vraie IP TCP, non spoofable via X-Forwarded-For
        // même si trusted_proxies = '*' (erreur de config fréquente).
        $clientIp = $request->server('REMOTE_ADDR', '') ?? '';

        if ($this->isAllowed($clientIp)) {
            return $next($request);
        }

        return response()->json(
            ['message' => 'Accès refusé : requête non locale.'],
            403
        );
    }

    private function isAllowed(string $ip): bool
    {
        // Localhost toujours autorisé (127.0.0.1/::1 — immuable, ne pas modifier).
        if (IpUtils::checkIp($ip, self::ALWAYS_ALLOWED)) {
            return true;
        }

        // IPs/CIDRs supplémentaires via résolveur (Story 15.6 : DB > env > défaut).
        // Le résolveur WpkgDeploymentSettings garantit la précédence DB > config()
        // et filtre les entrées vides/non-string.
        $allowedList = app(WpkgDeploymentSettings::class)->allowedIps();

        if (empty($allowedList)) {
            return false;
        }

        return IpUtils::checkIp($ip, $allowedList);
    }
}
