<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 2 — Implémentation Keycloak SSO
 * TODO: Implémenter quand Keycloak est disponible
 */
class KeycloakAuthGuard implements AuthGuardInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::critical('KeycloakAuthGuard: guard activé mais non implémenté — vérifier le binding AuthGuardInterface dans AppServiceProvider');

        abort(503, 'Authentification Keycloak non disponible — Phase 2');
    }
}
