<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\AuthenticationService;

class PasswordChangeMiddleware
{
    private AuthenticationService $authService;

    public function __construct(AuthenticationService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->get('token');
        
        // Vérifier si un token est requis et fourni
        if (!$token) {
            Log::warning('Accès à la page de changement de mot de passe sans token');
            return redirect()->route('auth.login')
                ->with('toast_error', 'Accès non autorisé');
        }

        // Valider le token
        $tokenData = $this->authService->validatePasswordChangeToken($token);
        
        if (!$tokenData) {
            Log::warning('Token de changement de mot de passe invalide ou expiré', [
                'token' => substr($token, 0, 8) . '...',
                'ip' => $request->ip()
            ]);
            
            return redirect()->route('auth.login')
                ->with('toast_error', 'Token invalide ou expiré. Veuillez vous reconnecter.');
        }

        // Restreindre aux routes de changement de mot de passe uniquement
        if (!$request->routeIs('auth.change-password*')) {
            Log::info('Redirection vers changement de mot de passe', [
                'requested_route' => $request->route()->getName(),
                'login' => $tokenData['login']
            ]);
            
            return redirect()->route('auth.change-password', ['token' => $token]);
        }

        // Ajouter les données du token à la requête pour utilisation dans le contrôleur
        $request->merge(['token_data' => $tokenData]);

        return $next($request);
    }
}