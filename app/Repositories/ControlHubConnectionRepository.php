<?php

namespace App\Repositories;

use App\Models\ControlHubConnection;
use Carbon\Carbon;

class ControlHubConnectionRepository
{
    /**
     * Récupérer la connexion active actuelle
     */
    public function getCurrentConnection(): ?ControlHubConnection
    {
        return ControlHubConnection::current();
    }

    /**
     * Créer ou mettre à jour une connexion
     */
    public function createOrUpdate(array $data): ControlHubConnection
    {
        return ControlHubConnection::createOrUpdate($data);
    }

    /**
     * Sauvegarder une nouvelle connexion depuis le handshake
     *
     * @param array{public_key: string, kid: string, iss: string}|null $idpFederated
     *        Bloc SSO fédéré validé par HandshakeResponse (null si absent de la réponse)
     */
    public function saveHandshakeConnection(
        string $baseUrl,
        string $apiToken,
        string $se4fsApiToken,
        int $heartbeatInterval,
        ?Carbon $expiresAt = null,
        ?array $idpFederated = null
    ): ControlHubConnection {
        return $this->createOrUpdate([
            'base_url' => $baseUrl,
            'api_token' => $apiToken,
            'se4fs_api_token' => $se4fsApiToken,
            'heartbeat_interval' => $heartbeatInterval,
            'expires_at' => $expiresAt ?? now()->addDays(30),
            'idp_public_key' => $idpFederated['public_key'] ?? null,
            'idp_kid' => $idpFederated['kid'] ?? null,
            'idp_iss' => $idpFederated['iss'] ?? null,
        ]);
    }

    /**
     * Mettre à jour le dernier heartbeat
     */
    public function updateLastHeartbeat(?ControlHubConnection $connection = null): void
    {
        $connection = $connection ?? $this->getCurrentConnection();
        if ($connection) {
            $connection->updateLastHeartbeat();
        }
    }

    /**
     * Mettre à jour le statut de la connexion
     */
    public function updateStatus(
        string $status,
        ?string $errorType = null,
        ?ControlHubConnection $connection = null
    ): void {
        $connection = $connection ?? $this->getCurrentConnection();
        if ($connection) {
            $connection->updateStatus($status, $errorType);
        }
    }

    /**
     * Renouveler le token de connexion
     */
    public function renewToken(
        string $newToken,
        ?Carbon $expiresAt = null,
        ?ControlHubConnection $connection = null
    ): ControlHubConnection {
        $connection = $connection ?? $this->getCurrentConnection();
        
        if (!$connection) {
            throw new \Exception('Aucune connexion active à renouveler');
        }

        $connection->update([
            'api_token' => $newToken,
            'expires_at' => $expiresAt,
            'last_handshake_at' => now()
        ]);

        return $connection->fresh();
    }

    /**
     * Désactiver une connexion
     */
    public function deactivate(?ControlHubConnection $connection = null): void
    {
        $connection = $connection ?? $this->getCurrentConnection();
        if ($connection) {
            $connection->deactivate();
        }
    }

    /**
     * Supprimer une connexion
     */
    public function delete(?ControlHubConnection $connection = null): bool
    {
        $connection = $connection ?? $this->getCurrentConnection();
        
        if (!$connection) {
            return false;
        }

        $connection->deactivate();
        return $connection->delete();
    }

    /**
     * Vérifier si une connexion existe
     */
    public function exists(): bool
    {
        return $this->getCurrentConnection() !== null;
    }

    /**
     * Vérifier si le token nécessite un renouvellement
     */
    public function needsRenewal(?ControlHubConnection $connection = null): bool
    {
        $connection = $connection ?? $this->getCurrentConnection();
        return $connection ? $connection->needsRenewal() : false;
    }

    /**
     * Vérifier si le token est expiré
     */
    public function isExpired(?ControlHubConnection $connection = null): bool
    {
        $connection = $connection ?? $this->getCurrentConnection();
        return $connection ? $connection->isExpired() : true;
    }

    /**
     * Obtenir l'URL du webhook
     */
    public function getWebhookUrl(?ControlHubConnection $connection = null): ?string
    {
        $connection = $connection ?? $this->getCurrentConnection();
        return $connection ? $connection->getWebhookUrl() : null;
    }

    /**
     * Obtenir l'URL du heartbeat
     */
    public function getHeartbeatUrl(?ControlHubConnection $connection = null): ?string
    {
        $connection = $connection ?? $this->getCurrentConnection();
        return $connection ? $connection->getHeartbeatUrl() : null;
    }
}

