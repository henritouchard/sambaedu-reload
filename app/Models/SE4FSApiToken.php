<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SE4FSApiToken extends Model
{
    use HasFactory;

    protected $table = 'se4fs_api_tokens';

    protected $fillable = [
        'instance_id',
        'token_hash',
        'client_name',
        'client_url',
        'client_version',
        'webhook_url',
        'webhook_token_hash',
        'capabilities',
        'last_used_at',
        'expires_at',
        'is_active',
        'created_by_ip',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Génère un nouveau token API
     */
    public static function generateToken(): string
    {
        return 'se4fs_' . Str::random(32);
    }

    /**
     * Génère un nouveau token webhook
     */
    public static function generateWebhookToken(): string
    {
        return 'se4fs-webhook-' . Str::random(32);
    }

    /**
     * Crée un hash sécurisé du token
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Trouve un token par son hash
     */
    public static function findByToken(string $token): ?self
    {
        $hash = self::hashToken($token);
        return self::where('token_hash', $hash)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /**
     * Valide un token API
     */
    public static function validateToken(string $token): bool
    {
        $tokenRecord = self::findByToken($token);

        if (!$tokenRecord) {
            return false;
        }

        // Mettre à jour la dernière utilisation
        $tokenRecord->updateLastUsed();

        return true;
    }

    /**
     * Met à jour la dernière utilisation du token
     */
    public function updateLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Révoque le token
     */
    public function revoke(): void
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Vérifie si le token est expiré
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Vérifie si le token est actif
     */
    public function isActive(): bool
    {
        return $this->is_active && !$this->isExpired();
    }

    /**
     * Valide un token webhook
     */
    public function validateWebhookToken(string $providedToken): bool
    {
        $providedHash = hash('sha256', $providedToken);
        return hash_equals($this->webhook_token_hash, $providedHash);
    }

    /**
     * Scope pour les tokens actifs
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope pour les tokens expirés
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }
}
