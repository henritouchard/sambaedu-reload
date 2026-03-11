<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\SE4FSApiToken;
use Carbon\Carbon;

/**
 * Service principal SE4FS pour l'intégration d'applications tierces
 * Implémente la logique métier selon Discovery.md
 * TODO: vu qu'il n'y a plus de discovery, vérifier si on peut supprimer ce service
 */
class SE4FSService
{
    private const API_VERSION = '1.0';
    private const SE4FS_VERSION = '4.2.1';
    private const TOKEN_EXPIRY_DAYS = 90; // 90 jours par défaut

    /**
     * Génère les données de découverte SE4FS
     */
    public function getDiscoveryData(): array
    {
        return [
            'se4fs_instance' => true,
            'name' => $this->getEstablishmentName(),
            'se4fs_version' => self::SE4FS_VERSION,
            'api_version' => self::API_VERSION,
            'establishment' => $this->getEstablishmentInfo(),
            'system_info' => $this->getSystemInfo(),
            'capabilities' => $this->getCapabilities(),
            'endpoints' => $this->getEndpoints()
        ];
    }

    /**
     * Génère un token API SE4FS
     */
    public function generateApiToken(): string
    {
        return SE4FSApiToken::generateToken();
    }

    /**
     * Génère un token webhook SE4FS
     */
    public function generateWebhookToken(): string
    {
        return SE4FSApiToken::generateWebhookToken();
    }

    /**
     * Valide un token API
     */
    public function validateApiToken(string $token): bool
    {
        return SE4FSApiToken::validateToken($token);
    }

    /**
     * Valide un token webhook
     */
    public function validateWebhookToken(string $providedToken, string $instanceId): bool
    {
        $tokenRecord = SE4FSApiToken::where('instance_id', $instanceId)
            ->where('is_active', true)
            ->first();

        if (!$tokenRecord) {
            return false;
        }

        return $tokenRecord->validateWebhookToken($providedToken);
    }

    /**
     * Stocke les informations de handshake de l'application tierce
     * @deprecated Cette méthode n'est plus utilisée car SE4FS est maintenant client du ControlHub
     */
    public function storeClientHandshake(array $handshakeData, ?string $clientIp = null): array
    {
        // Cette méthode est conservée pour compatibilité mais ne devrait plus être utilisée
        Log::warning('SE4FS storeClientHandshake called but deprecated - SE4FS should connect to ControlHub instead');

        $instanceId = Str::uuid();
        $apiToken = $this->generateApiToken();
        $webhookToken = $this->generateWebhookToken();

        // Calculer la date d'expiration (90 jours par défaut)
        $expiresAt = Carbon::now()->addDays(self::TOKEN_EXPIRY_DAYS);

        // Stocker en base de données
        $tokenRecord = SE4FSApiToken::create([
            'instance_id' => $instanceId,
            'token_hash' => SE4FSApiToken::hashToken($apiToken),
            'client_name' => $handshakeData['client_instance']['name'],
            'client_url' => $handshakeData['client_instance']['url'],
            'client_version' => $handshakeData['client_instance']['version'],
            'webhook_url' => $handshakeData['authentication']['webhook_url'],
            'webhook_token_hash' => SE4FSApiToken::hashToken($webhookToken),
            'capabilities' => $handshakeData['capabilities'] ?? [],
            'expires_at' => $expiresAt,
            'created_by_ip' => $clientIp,
        ]);

        Log::info('SE4FS Handshake stored in database', [
            'instance_id' => $instanceId,
            'client_url' => $handshakeData['client_instance']['url'] ?? null,
            'client_name' => $handshakeData['client_instance']['name'] ?? null,
            'expires_at' => $expiresAt->toISOString(),
        ]);

        return [
            'id' => $instanceId,
            'api_token' => $apiToken,
            'webhook_url' => url('/api/v1/webhook'),
            'webhook_token' => $webhookToken,
            'expires_at' => $expiresAt->toISOString(),
        ];
    }

    /**
     * Récupère les informations d'un token par son instance ID
     */
    public function getTokenInfo(string $instanceId): ?SE4FSApiToken
    {
        return SE4FSApiToken::where('instance_id', $instanceId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Révoque un token API
     */
    public function revokeToken(string $instanceId): bool
    {
        $tokenRecord = $this->getTokenInfo($instanceId);

        if (!$tokenRecord) {
            return false;
        }

        $tokenRecord->revoke();

        Log::info('SE4FS Token revoked', [
            'instance_id' => $instanceId,
            'client_name' => $tokenRecord->client_name,
        ]);

        return true;
    }

    /**
     * Nettoie les tokens expirés
     */
    public function cleanupExpiredTokens(): int
    {
        $expiredTokens = SE4FSApiToken::expired()->get();
        $count = $expiredTokens->count();

        foreach ($expiredTokens as $token) {
            $token->update(['is_active' => false]);
        }

        if ($count > 0) {
            Log::info('SE4FS Expired tokens cleaned up', [
                'count' => $count,
            ]);
        }

        return $count;
    }

    /**
     * Envoie un webhook vers l'application tierce
     */
    public function sendWebhook(string $event, array $data, string $instanceId): bool
    {
        $tokenRecord = $this->getTokenInfo($instanceId);

        if (!$tokenRecord) {
            Log::error('SE4FS Webhook failed: token not found', [
                'instance_id' => $instanceId,
                'event' => $event,
            ]);
            return false;
        }

        try {
            $payload = [
                'event' => $event,
                'timestamp' => now()->toISOString(),
                'se4fs_instance_id' => config('se4fs.instance_id'),
                'data' => $data
            ];

            // TODO: Implémenter l'envoi HTTP avec Guzzle
            Log::info('SE4FS Webhook sent', [
                'event' => $event,
                'url' => $tokenRecord->webhook_url,
                'instance_id' => $instanceId,
                'payload_size' => strlen(json_encode($payload))
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('SE4FS Webhook failed', [
                'event' => $event,
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Informations sur l'établissement
     */
    private function getEstablishmentInfo(): array
    {
        return [
            'name' => config('sambaedu.se4fs.establishment.name', 'SE4FS Instance'),
            'uai' => config('sambaedu.se4fs.establishment.uai', ''),
            'dn' => config('sambaedu.se4fs.establishment.dn', ''),
            'contact' => [
                'email' => config('sambaedu.se4fs.establishment.contact.email', ''),
                'phone' => config('sambaedu.se4fs.establishment.contact.phone', ''),
            ]
        ];
    }

    /**
     * Nom de l'établissement
     */
    private function getEstablishmentName(): string
    {
        return config('sambaedu.se4fs.establishment.name', 'SE4FS - ' . gethostname());
    }

    /**
     * Informations système
     */
    private function getSystemInfo(): array
    {
        return [
            'hostname' => gethostname(),
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'disk_usage' => $this->getDiskUsage(),
            'uptime' => $this->getUptime(),
            'load_average' => $this->getLoadAverage()
        ];
    }

    /**
     * Capacités de l'instance SE4FS
     */
    private function getCapabilities(): array
    {
        return [
            'file_sharing' => true,
            'user_management' => true,
            'quota_management' => true,
            'webhook_support' => true,
            'real_time_monitoring' => true,
            'historical_data' => true,
            'location_tracking' => true,
        ];
    }

    /**
     * Endpoints API disponibles
     */
    private function getEndpoints(): array
    {
        return [
            'webhook' => '/api/v1/webhook',
            'users' => '/api/v1/users',
            'stats' => '/api/v1/stats',
            'static' => '/api/v1/static',
            'health' => '/api/v1/health',
            'metrics' => '/api/v1/metrics',
            'historical' => '/api/v1/historical/{period}',
            'location_summary' => '/api/v1/public/location/summary',
        ];
    }

    /**
     * Utilisation du disque
     */
    private function getDiskUsage(): array
    {
        try {
            $totalSpace = disk_total_space('/');
            $freeSpace = disk_free_space('/');
            $usedSpace = $totalSpace - $freeSpace;

            return [
                'total_gb' => round($totalSpace / (1024 ** 3), 2),
                'used_gb' => round($usedSpace / (1024 ** 3), 2),
                'free_gb' => round($freeSpace / (1024 ** 3), 2),
                'percentage' => round(($usedSpace / $totalSpace) * 100, 1)
            ];
        } catch (\Exception $e) {
            return [
                'total_gb' => 0,
                'used_gb' => 0,
                'free_gb' => 0,
                'percentage' => 0
            ];
        }
    }

    /**
     * Uptime du système
     */
    private function getUptime(): int
    {
        try {
            if (file_exists('/proc/uptime')) {
                $uptime = file_get_contents('/proc/uptime');
                return (int) floatval(explode(' ', $uptime)[0]);
            }
            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Charge moyenne du système
     */
    private function getLoadAverage(): array
    {
        try {
            if (function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();
                return [
                    round($load[0], 2),
                    round($load[1], 2),
                    round($load[2], 2)
                ];
            }
            return [0, 0, 0];
        } catch (\Exception $e) {
            return [0, 0, 0];
        }
    }
}