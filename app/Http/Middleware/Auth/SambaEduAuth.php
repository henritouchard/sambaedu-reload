<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware d'authentification SambaEdu
 *
 * Délègue toute la logique au guard actif résolu par le conteneur IoC.
 * Pour swapper l'implémentation (Phase 2 Keycloak), modifier uniquement
 * le binding dans AppServiceProvider — aucun changement ici ni dans les routes.
 */
class SambaEduAuth
{
    public function __construct(private AuthGuardInterface $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->guard->handle($request, $next);
    }
}
