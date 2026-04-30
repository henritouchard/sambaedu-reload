<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware d'authentification des scripts d'exploitation SE_FS.
 *
 * Story 1bis.18f — port natif du legacy `header_authorize_script` de
 * `sambaedu/includes/config.inc.php:947` :
 *
 *   ```php
 *   if (! ($_SERVER['REMOTE_ADDR'] == $config['se4fs_ip']
 *          && $se4_key == $config["se4_key"])) { ... 403 }
 *   ```
 *
 * Stratégie d'auth (semantique OR — choix produit Henri 2026-04-27) :
 *  - Whitelist IP (`config('sambaedu.se4fs_ip')`) ; **OU**
 *  - Paramètre query/post `se4_key` matchant `config('sambaedu.se4_key')`.
 *
 * Si aucun match : 403 Forbidden (log warning).
 * Si IP est vide ET clé est vide : 403 (configuration absente = blocage par défaut).
 *
 * Ce middleware sécurise les endpoints text/plain consommés par les scripts
 * d'exploitation (logon scripts, cron VM SE_FS) — ex. `/admin/gpo/del-roam.sh`.
 */
class AllowSe4FsScript
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedIp = trim((string) config('sambaedu.se4fs_ip', ''));
        $expectedKey = trim((string) config('sambaedu.se4_key', ''));

        $clientIp = (string) $request->ip();
        $providedKey = trim((string) ($request->input('se4_key', '')));

        $ipMatch = $expectedIp !== '' && hash_equals($expectedIp, $clientIp);
        $keyMatch = $expectedKey !== '' && $providedKey !== '' && hash_equals($expectedKey, $providedKey);

        if (!$ipMatch && !$keyMatch) {
            Log::warning('[AllowSe4FsScript] Accès refusé', [
                'path' => $request->path(),
                'ip' => $clientIp,
                'has_key' => $providedKey !== '',
            ]);
            abort(403, 'Forbidden');
        }

        // Log debug (pas info) : endpoint consommé par les logon scripts à
        // chaque login utilisateur sur chaque poste — un info logger
        // produirait des centaines d'entrées/jour sans valeur diagnostique.
        // Les refus restent en `warning` (cf. branche ci-dessus).
        Log::debug('[AllowSe4FsScript] Accès autorisé', [
            'path' => $request->path(),
            'mode' => $ipMatch ? ($keyMatch ? 'ip+key' : 'ip') : 'key',
        ]);

        return $next($request);
    }
}
