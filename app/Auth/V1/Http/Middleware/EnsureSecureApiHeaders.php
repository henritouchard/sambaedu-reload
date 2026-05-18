<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 16.10 — Review finding #A.
 *
 * Pose les headers HTTP de sécurité sur les réponses `/api/v1/agent/*` :
 *
 *  - `Cache-Control: no-store, no-cache, must-revalidate, private` +
 *    `Pragma: no-cache` : les réponses contiennent des access/refresh tokens
 *    clear dans le body — un proxy intermédiaire (CDN, entreprise, WiFi) ne
 *    doit jamais les cacher.
 *  - `Strict-Transport-Security: max-age=31536000; includeSubDomains` : force
 *    HTTPS sur le serveur local pour 1 an. Pas de `preload` (compat anciens
 *    postes Windows 8.1+ qui n'ont pas la liste préchargée navigateur).
 *  - `X-Content-Type-Options: nosniff` + `X-Frame-Options: DENY` : durcissement
 *    générique, gratuit.
 *
 * Appliqué sur le group `/api/v1/agent/*` dans `routes/api.php`.
 */
class EnsureSecureApiHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
