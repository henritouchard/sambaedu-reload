<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Network\DnsRecordService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Story 8.4 — Endpoint DDNS appelé par `dhcp-dyndns.sh`, lui-même déclenché
 * par les événements `on commit` / `on release` / `on expiry` de dhcpd.
 *
 * Remplace le legacy `dhcp/dnsupdate.php` (qui répondait 500 depuis
 * l'extinction du canal legacy — fonction morte de fait, silencieusement, le
 * script appelant utilisant `curl -s -f` en tâche de fond).
 *
 * Contrôleur volontairement mince : toute la logique — et surtout
 * l'idempotence — vit dans {@see DnsRecordService}.
 *
 * Réponse toujours **200 avec un corps inerte** : l'appelant est un `curl`
 * détaché qui ne lit rien et dont dhcpd ignore le code de sortie. Un 4xx/5xx
 * n'aurait aucun consommateur et ferait seulement du bruit dans les logs
 * Apache ; l'issue réelle est tracée sur le channel `network`.
 */
class DhcpDnsUpdateController extends Controller
{
    public function __invoke(Request $request, DnsRecordService $dns): Response
    {
        // `se4_key` est encore posté par les scripts non redéployés : ignoré.
        // La protection est `local.request` (allowlist LAN) + throttle, iso
        // 17.6 — pas d'auth par clé partagée.
        $outcome = $dns->apply(
            action: strtolower(trim((string) $request->input('action', ''))),
            name: (string) $request->input('name', ''),
            ip: (string) $request->input('ip', ''),
        );

        return response($outcome->value . "\n", 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
