<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\SambaEduConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 8.4 — Garde de l'endpoint DDNS `/dhcp/dnsupdate`.
 *
 * Le seul appelant légitime est `dhcp-dyndns.sh`, exécuté par dhcpd **sur le
 * serveur lui-même** : l'origine autorisée se réduit donc au loopback et à
 * l'adresse propre du serveur (`se4fs_ip` de `sambaedu.conf`, source de
 * vérité partagée avec le legacy).
 *
 * On n'utilise délibérément PAS `local.request` (allowlist WPKG) : sa
 * sémantique est « les postes du parc », typiquement élargie à un CIDR LAN
 * entier. Or cet endpoint est une primitive d'écriture ET de suppression DNS
 * non authentifiée — l'ouvrir au parc permettrait à n'importe quel poste de
 * supprimer l'enregistrement A du DC ou de détourner un nom vers son IP. Le
 * legacy exigeait `REMOTE_ADDR == se4fs_ip` (`config.inc.php:1057`) : cette
 * garde le restaure à l'identique.
 */
class EnsureDhcpServerRequest
{
    /**
     * Loopback : toujours autorisé (le script cible 127.0.0.1).
     */
    private const ALWAYS_ALLOWED = ['127.0.0.1', '::1'];

    public function __construct(private readonly SambaEduConfig $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        // REMOTE_ADDR : vraie IP TCP, non spoofable via X-Forwarded-For même
        // si trusted_proxies est mal configuré (iso EnsureLocalRequest).
        $clientIp = (string) ($request->server('REMOTE_ADDR', '') ?? '');

        if ($this->isServerItself($clientIp)) {
            return $next($request);
        }

        return response()->json(
            ['message' => 'Accès refusé : origine non serveur.'],
            403
        );
    }

    private function isServerItself(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        if (IpUtils::checkIp($ip, self::ALWAYS_ALLOWED)) {
            return true;
        }

        // `se4fs_ip` vient de /etc/sambaedu/sambaedu.conf (pas du .env) : la
        // garde reste opérante sur une instance vierge, sans réglage. Lecture
        // par `get()` et non `network()` — même source brute, sans exiger la
        // construction complète du NetworkConfig.
        $serverIp = trim((string) $this->config->get('se4fs_ip', ''));

        return $serverIp !== '' && IpUtils::checkIp($ip, [$serverIp]);
    }
}
