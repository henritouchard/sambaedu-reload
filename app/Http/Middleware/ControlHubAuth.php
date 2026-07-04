<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware d'authentification ControlHub
 * Valide les clés API pour les endpoints protégés du ControlHub
 */
class ControlHubAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $providedKey = $this->extractApiKeyFromRequest($request);

        if (!$providedKey) {
            $this->logSecurityEvent($request, 'Missing API key', false);
            return $this->forbiddenResponse('Missing API key');
        }

        if (!$this->isValidApiKeyFormat($providedKey)) {
            $this->logSecurityEvent($request, 'Invalid API key format', false);
            return $this->forbiddenResponse('Invalid API key format');
        }

        $expectedInstanceKey = (string) config('controlHub.se4fs.instance_api_key', '');

        $isValid = false;
        $keyType = null;

        if (!empty($expectedInstanceKey) && hash_equals($expectedInstanceKey, $providedKey)) {
            $isValid = true;
            $keyType = 'instance';
        }

        if (!$isValid) {
            $this->logSecurityEvent($request, 'Invalid API key', false);
            return $this->forbiddenResponse('Invalid API key');
        }

        // Ajouter les informations d'authentification à la requête
        $request->attributes->set('controlhub_authenticated', true);
        $request->attributes->set('controlhub_key_type', $keyType);

        $this->logSecurityEvent($request, "Successful authentication with {$keyType} key", true);

        return $next($request);
    }

    /**
     * Extrait la clé API de la requête
     * 
     * Source unique: Authorization: Bearer <token>
     */
    private function extractApiKeyFromRequest(Request $request): ?string
    {
        $authHeader = $request->header('Authorization');
        if (is_string($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }

    /**
     * Valide le format de la clé API
     */
    private function isValidApiKeyFormat(string $key): bool
    {
        // Clé API doit contenir au moins 16 caractères alphanumériques
        return preg_match('/^[a-zA-Z0-9_-]{16,}$/', $key) === 1;
    }

    /**
     * Log les événements de sécurité
     */
    private function logSecurityEvent(Request $request, string $message, bool $success): void
    {
        $logLevel = $success ? 'info' : 'warning';

        Log::$logLevel('ControlHub API access attempt', [
            'message' => $message,
            'success' => $success,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Réponse d'erreur d'authentification
     */
    private function forbiddenResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'Forbidden',
            'message' => $message
        ], 403);
    }
}
