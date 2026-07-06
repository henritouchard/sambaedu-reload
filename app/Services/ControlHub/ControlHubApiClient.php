<?php

namespace App\Services\ControlHub;

use App\Services\ControlHub\Data\HandshakeRequest;
use App\Services\ControlHub\Data\ApiResponse;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Client HTTP pour communiquer avec l'API ControlHub
 * Responsabilité unique : effectuer les appels HTTP
 */
class ControlHubApiClient
{
    private Client $httpClient;
    private string $baseUrl;
    private string $userAgent = 'SE4FS-API/4.2.1';

    public function __construct(
        string $baseUrl,
        int $timeout = 30,
        int $connectTimeout = 10
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->httpClient = new Client([
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
        ]);
    }

    /**
     * Effectuer le handshake avec ControlHub
     */
    public function sendHandshake(HandshakeRequest $request): ApiResponse
    {
        try {
            $response = $this->httpClient->post(
                $this->baseUrl . config('controlHub.endpoints.handshake', '/api/sambaedu/handshake'),
                [
                    'json' => $request->toArray(),
                    'headers' => $this->getDefaultHeaders()
                ]
            );

            $data = json_decode($response->getBody(), true);

            return ApiResponse::success($data, $response->getStatusCode());

        } catch (RequestException $e) {
            return ApiResponse::failed(
                'Handshake request failed: ' . $e->getMessage(),
                $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500
            );
        } catch (\Exception $e) {
            return ApiResponse::failed('Handshake error: ' . $e->getMessage());
        }
    }

    /**
     * Envoyer un heartbeat
     */
    public function sendHeartbeat(string $instanceId, string $token): ApiResponse
    {
        return $this->request(
            'POST',
            '/heartbeat/' . $instanceId,
            [],
            $token
        );
    }

    /**
     * Notifier la déconnexion de l'instance
     */
    public function notifyDisconnection(string $instanceId, string $token): ApiResponse
    {
        $endpoint = str_replace(
            '{instance_id}',
            $instanceId,
            config('controlHub.endpoints.disconnect', '/api/sambaedu/unregister/{instance_id}')
        );

        return $this->request(
            'POST',
            $endpoint,
            [
                'instance_id' => $instanceId,
                'disconnected_at' => now()->toIso8601String()
            ],
            $token
        );
    }

    /**
     * Appeler un endpoint générique de l'API
     */
    public function callEndpoint(
        string $endpoint,
        string $method = 'GET',
        array $data = [],
        ?string $token = null
    ): ApiResponse {
        return $this->request($method, $endpoint, $data, $token);
    }

    /**
     * Méthode générique pour effectuer des requêtes HTTP
     */
    private function request(
        string $method,
        string $endpoint,
        array $data = [],
        ?string $token = null
    ): ApiResponse {
        try {
            $options = [
                'headers' => $this->getDefaultHeaders($token)
            ];

            if ($method === 'POST' && !empty($data)) {
                $options['json'] = $data;
            }

            $url = $this->buildUrl($endpoint);
            $response = $this->httpClient->request($method, $url, $options);

            $responseData = json_decode($response->getBody(), true);

            return ApiResponse::success($responseData, $response->getStatusCode());

        } catch (RequestException $e) {
            Log::error('ControlHub API - Request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'response_body' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null
            ]);

            return ApiResponse::failed(
                'API request failed: ' . $e->getMessage(),
                $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500
            );
        } catch (\Exception $e) {
            Log::error('ControlHub API - Request error', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);

            return ApiResponse::failed('Request error: ' . $e->getMessage());
        }
    }

    /**
     * Construire l'URL complète
     */
    private function buildUrl(string $endpoint): string
    {
        // Si l'endpoint commence par /api/sambaedu, on l'ajoute directement
        if (str_starts_with($endpoint, '/api/sambaedu')) {
            return $this->baseUrl . $endpoint;
        }

        // Sinon, on ajoute le préfixe /api/sambaedu
        return $this->baseUrl . '/api/sambaedu' . $endpoint;
    }

    /**
     * Obtenir les headers par défaut
     */
    private function getDefaultHeaders(?string $token = null): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent
        ];

        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * Changer l'URL de base (utile pour les tests ou configurations dynamiques)
     */
    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Obtenir l'URL de base actuelle
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}

