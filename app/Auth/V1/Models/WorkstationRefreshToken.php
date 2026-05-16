<?php

declare(strict_types=1);

namespace App\Auth\V1\Models;

use Database\Factories\Auth\V1\WorkstationRefreshTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Story 16.10 — AC3.1.
 *
 * Modèle Eloquent pour la table `workstation_refresh_tokens`.
 *
 * Convention :
 *
 *  - `id` UUID v4 généré par `HasUuids` trait (primary).
 *  - `client_meta` casté `array` (lecture/écriture transparente).
 *  - Scope `active()` = non révoqué ET non expiré.
 *  - Scope `expired()` = `expires_at <= now()` (utile job de purge futur).
 *
 * @property string $id
 * @property string $workstation_uuid
 * @property string $refresh_token_hash
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property Carbon|null $last_used_at
 * @property array<string,mixed>|null $client_meta
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WorkstationRefreshToken extends Model
{
    use HasFactory;
    use HasUuids;

    /** @var string */
    protected $table = 'workstation_refresh_tokens';

    /** @var bool */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    /** @var array<int,string> */
    protected $fillable = [
        'id',
        'workstation_uuid',
        'refresh_token_hash',
        'issued_at',
        'expires_at',
        'revoked_at',
        'revocation_reason',
        'last_used_at',
        'client_meta',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
        'client_meta' => 'array',
    ];

    /**
     * Refresh tokens actifs : non révoqués + non expirés.
     *
     * @param Builder<self> $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now());
    }

    /**
     * Refresh tokens expirés (`expires_at <= now`).
     *
     * @param Builder<self> $query
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', Carbon::now());
    }

    /**
     * Refresh tokens révoqués (révoqués + non expirés OU expirés et révoqués).
     *
     * @param Builder<self> $query
     */
    public function scopeRevoked(Builder $query): Builder
    {
        return $query->whereNotNull('revoked_at');
    }

    /**
     * Vrai si le token est utilisable (non révoqué + non expiré).
     */
    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at instanceof Carbon
            && $this->expires_at->isFuture();
    }

    /**
     * Override : la factory vit sous le sous-namespace `Auth\V1`, pas la
     * convention racine `Database\Factories\WorkstationRefreshTokenFactory`.
     */
    protected static function newFactory(): Factory
    {
        return WorkstationRefreshTokenFactory::new();
    }
}
