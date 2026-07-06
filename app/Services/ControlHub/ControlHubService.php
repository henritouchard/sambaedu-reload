<?php

namespace App\Services\ControlHub;

use App\Services\ControlHub\Data\HandshakeRequest;
use App\Services\ControlHub\Data\HandshakeResponse;
use App\Services\ControlHub\Data\HeartbeatResponse;
use App\Services\ControlHub\Data\DisconnectionResponse;
use App\Repositories\ControlHubConnectionRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service de gestion de la logique métier ControlHub
 * Orchestration entre l'API client et le repository
 */
class ControlHubService
{
    private string $instanceId;
    private string $instanceApiKey;

    public function __construct(
        private ControlHubApiClient $apiClient,
        private ControlHubConnectionRepository $repository
    ) {
        $this->instanceId = (string) config('controlHub.se4fs.instance_id', (string) Str::uuid());
        $this->instanceApiKey = (string) config('controlHub.se4fs.instance_api_key', 'se4fs_instance_' . Str::random(16));
    }

    /**
     * Effectuer le handshake avec ControlHub
     */
    public function performHandshake(string $masterApiKey, string $baseUrl): HandshakeResponse
    {
        Log::info('SE4FS Handshake avec ControlHub', [
            'controlhub_url' => $baseUrl,
            'se4fs_instance_id' => $this->instanceId,
        ]);

        // Client dédié à l'URL cible du handshake (seam surchargeable en test).
        $tempClient = $this->makeHandshakeClient($baseUrl);

        // Préparer la requête de handshake
        $request = HandshakeRequest::create(
            registrationKey: $masterApiKey,
            instanceApiKey: $this->instanceApiKey,
            instanceId: $this->instanceId,
            instanceName: config('sambaedu.se4fs.establishment.name', 'SE-Établissement'),
            url: url('/'),
            capabilities: [
                'user_management',
                'file_sharing',
                'system_monitoring',
                'quota_alerts'
            ],
            uai: config('sambaedu.se4fs.establishment.uai'),
            academy: config('sambaedu.se4fs.establishment.academie'),
            type: config('sambaedu.se4fs.establishment.type')
        );

        $apiResponse = $tempClient->sendHandshake($request);
        $handshakeResponse = HandshakeResponse::fromApiResponse($apiResponse);

        // Vérifier si le handshake a réussi
        if (!$handshakeResponse->success) {
            Log::error('SE4FS Handshake avec ControlHub échoué', [
                'controlhub_url' => $baseUrl,
                'error' => $handshakeResponse->message
            ]);

            return $handshakeResponse;
        }

        // Sauvegarder la connexion.
        //
        // E10 — couture d'auth entrante CH→SE5 : le credential que le controlHub
        // présentera sur ses appels entrants (ingestion de contrat, rupture de
        // lien) est le token qu'il frappe et nous renvoie AU handshake
        // (`api_token`). C'est donc CE token — et non notre clé d'instance
        // statique — qui doit alimenter `se4fs_api_token`, la colonne validée par
        // `ControlHubConnection::validateSE4FSToken()` dans le middleware
        // `ControlHubAuth`. Les deux colonnes portent alors le même token : le
        // bearer commun aux deux sens (sortant SE5→CH via `api_token` chiffré,
        // entrant CH→SE5 via `se4fs_api_token` en clair). Rotation = re-handshake
        // seul (aucun renouvellement hors handshake — le mécanisme token/renew a
        // été retiré, il aurait désynchronisé les deux colonnes).
        $this->repository->saveHandshakeConnection(
            baseUrl: $baseUrl,
            apiToken: $handshakeResponse->apiToken,
            se4fsApiToken: $handshakeResponse->apiToken,
            heartbeatInterval: $handshakeResponse->heartbeatInterval ?? 120,
            expiresAt: $handshakeResponse->expiresAt,
            idpFederated: $handshakeResponse->idpFederated
        );

        // Mettre à jour l'URL du client API principal
        $this->apiClient->setBaseUrl($baseUrl);

        Log::info('SE4FS Handshake avec ControlHub réussi', [
            'controlhub_url' => $baseUrl,
            'se4fs_instance_id' => $this->instanceId,
            'api_token_received' => substr($handshakeResponse->apiToken, 0, 20) . '...',
            'heartbeat_interval' => $handshakeResponse->heartbeatInterval,
            'idp_federated' => $handshakeResponse->idpFederated
                ? ['kid' => $handshakeResponse->idpFederated['kid'], 'iss' => $handshakeResponse->idpFederated['iss']]
                : null,
        ]);

        return $handshakeResponse;
    }

    /**
     * Fabrique le client HTTP dédié au handshake (URL cible saisie à la volée,
     * pas encore persistée). Isolé en méthode protégée pour permettre au test de
     * substituer un client factice — le `new` direct sur Guzzle n'est pas
     * interceptable autrement (couture E10 testable sans réseau, Story 39.5).
     */
    protected function makeHandshakeClient(string $baseUrl): ControlHubApiClient
    {
        return new ControlHubApiClient($baseUrl);
    }

    /**
     * Effectuer un heartbeat
     */
    public function performHeartbeat(): HeartbeatResponse
    {
        Log::info('SE4FS Heartbeat ControlHub', [
            'instance_id' => $this->instanceId,
        ]);

        $connection = $this->repository->getCurrentConnection();
        if (!$connection) {
            return HeartbeatResponse::failed('Aucune connexion ControlHub active trouvée');
        }

        // La BDD fait foi sur l'URL du ControlHub (dynamique, saisie au handshake) :
        // on resynchronise le client au cas où le singleton aurait une URL obsolète.
        if ($connection->base_url) {
            $this->apiClient->setBaseUrl($connection->base_url);
        }

        $token = $this->getToken();
        if (!$token) {
            return HeartbeatResponse::failed('Aucun token disponible pour le heartbeat');
        }

        $apiResponse = $this->apiClient->sendHeartbeat($this->instanceId, $token);
        $heartbeatResponse = HeartbeatResponse::fromApiResponse($apiResponse);

        if (!$heartbeatResponse->success) {
            $this->repository->updateStatus('error', 'heartbeat');
            return $heartbeatResponse;
        }

        // Mettre à jour le dernier heartbeat et le statut
        $this->repository->updateLastHeartbeat();
        $this->repository->updateStatus('online');

        Log::info('SE4FS Heartbeat ControlHub - Succès', [
            'connection_id' => $connection->id,
            'last_heartbeat_at' => $connection->fresh()->last_heartbeat_at
        ]);

        return $heartbeatResponse;
    }

    /**
     * Démarrer le heartbeat automatique (modifie la BDD)
     */
    public function startAutomaticHeartbeat(): void
    {
        $connection = $this->repository->getCurrentConnection();
        if (!$connection) {
            Log::warning('ControlHub Heartbeat - Impossible de démarrer : aucune connexion active');
            throw new \Exception('Aucune connexion ControlHub active');
        }

        if ($connection->heartbeat_enabled) {
            Log::info('ControlHub Heartbeat automatique - Déjà actif, ignoré');
            return;
        }

        $connection->update([
            'heartbeat_enabled' => true,
            'heartbeat_failures' => 0,
            'status' => 'online',
        ]);

        Log::info('ControlHub Heartbeat automatique - Démarré', [
            'connection_id' => $connection->id,
        ]);
    }

    /**
     * Arrêter le heartbeat automatique (modifie la BDD)
     */
    public function stopAutomaticHeartbeat(): void
    {
        $connection = $this->repository->getCurrentConnection();
        if (!$connection) {
            Log::info('ControlHub Heartbeat - Aucune connexion à arrêter');
            return;
        }

        $connection->update([
            'heartbeat_enabled' => false,
        ]);

        Log::info('ControlHub Heartbeat automatique - Arrêté', [
            'connection_id' => $connection->id,
        ]);
    }

    /**
     * Redémarrer le heartbeat automatique
     */
    public function restartAutomaticHeartbeat(): void
    {
        $connection = $this->repository->getCurrentConnection();
        if (!$connection) {
            Log::warning('ControlHub Heartbeat - Impossible de redémarrer : aucune connexion active');
            throw new \Exception('Aucune connexion ControlHub active pour redémarrer le heartbeat');
        }

        $connection->update([
            'heartbeat_enabled' => true,
            'heartbeat_failures' => 0,
            'status' => 'online',
        ]);

        Log::info('ControlHub Heartbeat automatique - Redémarré', [
            'connection_id' => $connection->id,
        ]);
    }

    /**
     * Vérifier si le heartbeat est actif (depuis la BDD)
     */
    public function isHeartbeatActive(): bool
    {
        $connection = $this->repository->getCurrentConnection();
        return $connection && $connection->heartbeat_enabled;
    }

    /**
     * Obtenir les statistiques du heartbeat (depuis la BDD)
     */
    public function getHeartbeatStats(): array
    {
        $connection = $this->repository->getCurrentConnection();
        return [
            'active' => $connection ? $connection->heartbeat_enabled : false,
            'last_heartbeat' => $connection ? $connection->last_heartbeat_at : null,
            'failures' => $connection ? ($connection->heartbeat_failures ?? 0) : 0,
            'next_interval' => ($connection ? $connection->heartbeat_interval : config('controlHub.heartbeat.interval', 5) * 60) . ' secondes'
        ];
    }

    /**
     * Obtenir le token sortant (SE5→CH) de la connexion active.
     *
     * Rotation = re-handshake seul. Le renouvellement hors handshake
     * (endpoint amont `token/renew`) a été retiré : côté amont il était
     * hors service, et depuis la couture E10 le token est dual-use
     * (`api_token` sortant == `se4fs_api_token` entrant) — une rotation qui
     * n'aurait mis à jour que `api_token` aurait rebasculé l'ingress en 403
     * jusqu'au re-handshake. Cf. Story 39.5.
     */
    public function getToken(): ?string
    {
        $connection = $this->repository->getCurrentConnection();
        if (!$connection) {
            Log::warning('Aucune connexion ControlHub active trouvée');
            return null;
        }

        return $connection->api_token;
    }

    /**
     * Tester la connexion
     */
    public function testConnection(): array
    {
        $connection = $this->repository->getCurrentConnection();
        if ($connection && $connection->base_url) {
            $this->apiClient->setBaseUrl($connection->base_url);
        }

        $token = $this->getToken();
        if (!$token) {
            return [
                'success' => false,
                'message' => 'No ControlHub token available'
            ];
        }

        $apiResponse = $this->apiClient->callEndpoint('/instances', 'GET', [], $token);

        if ($apiResponse->isSuccessful()) {
            $this->repository->updateStatus('online');
            return [
                'success' => true,
                'message' => 'Connection to ControlHub successful',
                'instances_count' => count($apiResponse->data['data'] ?? [])
            ];
        }

        $this->repository->updateStatus('error', 'connection');
        return [
            'success' => false,
            'message' => 'Connection to ControlHub failed: ' . $apiResponse->message
        ];
    }

    /**
     * Appeler un endpoint de l'API ControlHub
     */
    public function callControlHubApi(string $endpoint, array $data = [], string $method = 'GET'): array
    {
        $token = $this->getToken();
        if (!$token) {
            // Tentative de handshake automatique
            return [
                'success' => false,
                'message' => 'No ControlHub token available'
            ];
        }

        $apiResponse = $this->apiClient->callEndpoint($endpoint, $method, $data, $token);

        return [
            'success' => $apiResponse->isSuccessful(),
            'data' => $apiResponse->data,
            'message' => $apiResponse->message,
            'http_status' => $apiResponse->httpStatus
        ];
    }

    /**
     * Supprimer la connexion
     */
    public function deleteConnection(): array
    {
        try {
            // Arrêter le heartbeat automatique s'il est actif
            if ($this->isHeartbeatActive()) {
                $this->stopAutomaticHeartbeat();
                Log::info('Heartbeat automatique arrêté lors de la suppression de la connexion');
            }

            // Récupérer la connexion actuelle
            $connection = $this->repository->getCurrentConnection();

            if (!$connection) {
                Log::warning('Tentative de suppression d\'une connexion inexistante');
                return [
                    'success' => false,
                    'message' => 'Aucune connexion active trouvée à supprimer',
                    'type' => 'error'
                ];
            }

            // Notifier ControlHub de la déconnexion (on ignore les erreurs)
            try {
                $token = $connection->api_token;
                if ($token) {
                    $apiResponse = $this->apiClient->notifyDisconnection($this->instanceId, $token);
                    $disconnectionResponse = DisconnectionResponse::fromApiResponse($apiResponse);

                    if ($disconnectionResponse->success) {
                        Log::info('ControlHub notifié de la déconnexion avec succès', [
                            'instance_id' => $this->instanceId
                        ]);
                    } else {
                        Log::warning('Échec de notification de déconnexion à ControlHub', [
                            'instance_id' => $this->instanceId,
                            'error' => $disconnectionResponse->message
                        ]);
                    }
                }
            } catch (\Exception $notificationException) {
                // On log mais on continue quand même la suppression
                Log::warning('Impossible de notifier ControlHub de la déconnexion', [
                    'instance_id' => $this->instanceId,
                    'error' => $notificationException->getMessage()
                ]);
            }

            // Sauvegarder les informations pour les logs
            $connectionInfo = [
                'id' => $connection->id,
                'base_url' => $connection->base_url,
                'last_handshake_at' => $connection->last_handshake_at,
                'last_heartbeat_at' => $connection->last_heartbeat_at
            ];

            // Supprimer la connexion
            $this->repository->delete($connection);

            // Nettoyer le cache
            $this->clearAllCache();

            Log::info('Connexion ControlHub supprimée avec succès', $connectionInfo);

            return [
                'success' => true,
                'message' => 'La connexion ControlHub a été supprimée avec succès. Toutes les données associées ont été effacées.',
                'type' => 'success',
                'deleted_connection' => $connectionInfo
            ];

        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de la connexion ControlHub', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Erreur lors de la suppression de la connexion : ' . $e->getMessage(),
                'type' => 'error'
            ];
        }
    }

    /**
     * Nettoyer tout le cache ControlHub
     */
    private function clearAllCache(): void
    {
        Cache::forget('controlHub_heartbeat_active');
        Cache::forget('controlHub_heartbeat_failures');
        Cache::forget('controlHub_instance_connected');
        Cache::forget('controlHub_last_heartbeat');
        Cache::forget('controlHub_last_heartbeat_time');
        Cache::forget('controlHub_next_heartbeat_time');
    }

    /**
     * Obtenir la configuration
     */
    public function getConfig(): array
    {
        return [
            'base_url' => $this->apiClient->getBaseUrl(),
            'instance_id' => $this->instanceId,
            'instance_api_key' => $this->instanceApiKey,
            'endpoints' => config('controlHub.endpoints', []),
            'api' => config('controlHub.api', []),
            'heartbeat' => config('controlHub.heartbeat', [])
        ];
    }

    /**
     * Obtenir l'instance ID
     */
    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    /**
     * Obtenir l'instance API key
     */
    public function getInstanceApiKey(): string
    {
        return $this->instanceApiKey;
    }

    /**
     * Obtenir le token webhook
     */
    public function getWebhookToken(): ?string
    {
        $connection = $this->repository->getCurrentConnection();
        return $connection ? $connection->se4fs_api_token : null;
    }

    /**
     * Obtenir l'URL du webhook
     */
    public function getWebhookUrl(): ?string
    {
        return $this->repository->getWebhookUrl();
    }

    /**
     * Obtenir l'URL du heartbeat
     */
    public function getHeartbeatUrl(): ?string
    {
        return $this->repository->getHeartbeatUrl();
    }

    /**
     * Obtenir l'intervalle de heartbeat
     */
    public function getHeartbeatInterval(): ?int
    {
        $connection = $this->repository->getCurrentConnection();
        return $connection ? $connection->heartbeat_interval : 300;
    }

    /**
     * Obtenir l'URL de base du ControlHub
     */
    public function getBaseUrl(): string
    {
        return $this->apiClient->getBaseUrl();
    }
}

