<?php

namespace App\Http\Middleware\SE4;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Services\SE4FSService;

/**
 * Middleware d'authentification SE4FS
 * Valide les tokens API pour les endpoints protégés
 */
class SE4FSAuth
{
    private SE4FSService $se4fsService;
    
    public function __construct(SE4FSService $se4fsService)
    {
        $this->se4fsService = $se4fsService;
    }
    
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);
        
        if (!$token) {
            return $this->unauthorizedResponse('Missing API token');
        }
        
        if (!$this->se4fsService->validateApiToken($token)) {
            return $this->unauthorizedResponse('Invalid API token');
        }
        
        // Ajouter les informations du token à la requête
        $request->attributes->set('se4fs_token', $token);
        $request->attributes->set('se4fs_authenticated', true);
        
        return $next($request);
    }
    
    /**
     * Extrait le token de la requête
     */
    private function extractToken(Request $request): ?string
    {
        // Vérifier l'en-tête Authorization: Bearer
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }
        
        // Vérifier en paramètre de requête (moins sécurisé, pour debug uniquement)
        return $request->query('token');
    }
    
    /**
     * Réponse d'erreur d'authentification
     */
    private function unauthorizedResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'AUTH_REQUIRED'
        ], 401);
    }
} 