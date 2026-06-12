<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kill-switch de dev (2026-06-12) — canal de config legacy.
 *
 * Quand `sambaedu.legacy_config_channel_enabled` est false, neutralise les
 * endpoints natifs « façon legacy » qui livrent au poste le wallpaper, les
 * raccourcis, le wpkg, les registres et les applications
 * (`/api/v1/workstation-config/*` et `/wpkg/*`). Objectif : tester l'agent Go
 * (`/api/v1/agent/*`, totalement indépendant) sans interférence du canal
 * historique. Aucun code n'est supprimé — bascule réversible via `.env`.
 *
 * Le canal `/gpo/*` est neutralisé symétriquement par un garde inline dans
 * MigrationController::serveFragment (il renvoie son propre fragment no-op).
 *
 * Réponse quand désactivé : 410 Gone text/plain no-store — signal explicite
 * « canal coupé » pour les consommateurs cmd/sh/json côté poste.
 */
class EnsureLegacyConfigChannelEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('sambaedu.legacy_config_channel_enabled', true)) {
            return response(
                "SambaEdu : canal de configuration legacy desactive (test agent).\n",
                410,
            )
                ->header('Content-Type', 'text/plain; charset=UTF-8')
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
                ->header('X-Legacy-Config-Channel', 'disabled');
        }

        return $next($request);
    }
}
