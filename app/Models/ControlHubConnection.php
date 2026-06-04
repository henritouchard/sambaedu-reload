<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ControlHubConnection extends Model
{
    use HasFactory;

    protected $table = 'controlhub_connection';

    protected $fillable = [
        'base_url',
        'api_token',
        'se4fs_api_token',
        'idp_public_key',
        'idp_kid',
        'idp_iss',
        'heartbeat_interval',
        'heartbeat_enabled',
        'heartbeat_failures',
        'last_handshake_at',
        'last_heartbeat_at',
        'expires_at',
        'is_active',
        'status',
        'error_type',
    ];

    protected $casts = [
        'last_handshake_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'heartbeat_enabled' => 'boolean',
        'heartbeat_interval' => 'integer',
        'heartbeat_failures' => 'integer',
    ];

    public function setApiTokenAttribute($value)
    {
        $this->attributes['api_token'] = Crypt::encryptString($value);
    }

    public function getApiTokenAttribute($value)
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public static function current(): ?self
    {
        return self::where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            // Ordre déterministe : si deux lignes actives coexistent (course sur
            // createOrUpdate), la plus récente fait foi — elle porte notamment
            // la clé publique SSO du dernier handshake.
            ->orderByDesc('id')
            ->first();
    }

    public function updateFromHandshake(array $handshakeData): void
    {
        $this->update([
            'api_token' => $handshakeData['api_token'] ?? null,
            'se4fs_api_token' => $handshakeData['se4fs_api_token'],
            'base_url' => $handshakeData['base_url'] ?? null,
            'heartbeat_interval' => $handshakeData['heartbeat_interval'] ?? 300,
            'last_handshake_at' => now(),
            'expires_at' => $handshakeData['expires_at'] ?? now()->addDays(30),
            'is_active' => true,
        ]);
    }

    public static function createOrUpdate(array $data): self
    {
        // Transaction : désactivation de l'ancienne ligne + création de la nouvelle
        // doivent être atomiques (sinon une course peut laisser deux lignes actives
        // ou aucune). La ligne active porte la clé publique SSO : pas d'état ambigu.
        return DB::transaction(fn () => self::createActiveConnection($data));
    }

    private static function createActiveConnection(array $data): self
    {
        self::where('is_active', true)->update(['is_active' => false]);
        return self::create([
            'base_url' => $data['base_url'] ?? null,
            'api_token' => $data['api_token'] ?? null,
            'se4fs_api_token' => $data['se4fs_api_token'],
            'heartbeat_interval' => $data['heartbeat_interval'] ?? 300,
            'last_handshake_at' => now(),
            'expires_at' => $data['expires_at'] ?? now()->addDays(30),
            'is_active' => true,
            // SSO fédéré : clé publique du controlHub reçue au handshake (null si non fournie)
            'idp_public_key' => $data['idp_public_key'] ?? null,
            'idp_kid' => $data['idp_kid'] ?? null,
            'idp_iss' => $data['idp_iss'] ?? null,
        ]);
    }

    public function getSE4FSToken(): string
    {
        return $this->se4fs_api_token;
    }

    public function getWebhookUrl(): string
    {
        $instanceId = config('controlHub.se4fs.instance_id', 'se4fs_default');
        return $this->base_url . '/api/sambaedu/webhook/' . $instanceId;
    }

    public function getHeartbeatUrl(): string
    {
        $instanceId = config('controlHub.se4fs.instance_id', 'se4fs_default');
        return $this->base_url . '/api/sambaedu/heartbeat/' . $instanceId;
    }

    public function isValid(): bool
    {
        return $this->is_active && !$this->isExpired();
    }

    /**
     * Le controlHub a-t-il fourni sa clé publique de SSO fédéré au handshake ?
     * (prérequis à la vérification des JWT de login fédéré)
     *
     * Risque résiduel (review F8) : la clé est stockée sans protection d'intégrité —
     * un accès en écriture à la DB permettrait de la remplacer et de forger des JWT
     * fédérés. À adresser dans la story de vérification JWT (pinning/contrôle).
     */
    public function hasFederatedIdp(): bool
    {
        return !empty($this->idp_public_key)
            && !empty($this->idp_kid)
            && !empty($this->idp_iss);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function needsRenewal(): bool
    {
        if (!$this->expires_at) {
            return false;
        }
        $createdAt = $this->last_handshake_at ?? $this->created_at;
        $totalDuration = $createdAt->diffInSeconds($this->expires_at);
        $twoThirdsDuration = $totalDuration * (2 / 3);
        $renewalTime = $createdAt->addSeconds($twoThirdsDuration);
        return now()->greaterThan($renewalTime);
    }

    public function updateLastHandshake(): void
    {
        $this->update(['last_handshake_at' => now()]);
    }

    public function updateLastHeartbeat(): void
    {
        $this->update(['last_heartbeat_at' => now()]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    public function validateSE4FSToken(string $providedToken): bool
    {
        return hash_equals($this->se4fs_api_token, $providedToken);
    }

    public function updateStatus(string $status, ?string $errorType = null): void
    {
        $this->update([
            'status' => $status,
            'error_type' => $errorType
        ]);
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            'online' => 'success',
            'offline' => 'info',
            'error' => 'error',
            default => 'warning'
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }
}


